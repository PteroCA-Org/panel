<?php

namespace App\Core\Service\Server;

use App\Core\DTO\Pterodactyl\Application\PterodactylServer;
use App\Core\Entity\Server;
use App\Core\Entity\ServerProduct;
use App\Core\Entity\User;
use App\Core\Enum\LogActionEnum;
use App\Core\Repository\ServerProductRepository;
use App\Core\Repository\ServerRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminServerCreationService
{
    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly ServerProductRepository $serverProductRepository,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly ServerBuildService $serverBuildService,
        private readonly UserRepository $userRepository,
        private readonly LogService $logService,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Creates a new server for a user from admin panel.
     *
     * @param int|null $eggId Starting egg ID (if null, selects first available - fallback only)
     * @throws Exception if server creation fails
     */
    public function createServerForUser(
        User $user,
        ServerProduct $serverProduct,
        string $serverName,
        DateTime $expiresAt,
        bool $autoRenewal,
        bool $isSuspended,
        ?int $eggId,
        bool $freeServer,
        User $createdByAdmin
    ): Server {
        $this->validateSelectedPrice($serverProduct);

        $selectedEggId = $eggId ?? $this->selectFirstEgg($serverProduct);

        $server = $this->createServerEntity(
            $user,
            $serverName,
            $expiresAt,
            $autoRenewal,
            $isSuspended
        );

        $this->linkServerProductToServer($serverProduct, $server);

        try {
            $pterodactylServer = $this->createPterodactylServer(
                $serverProduct,
                $user,
                $selectedEggId,
                $serverName
            );

            $this->updateServerWithPterodactylData($server, $pterodactylServer);
        } catch (Exception $e) {
            $this->rollbackServerCreation($server);

            $this->logger->error('Failed to create server on Pterodactyl', [
                'error' => $e->getMessage(),
                'server_id' => $server->getId(),
                'user_id' => $user->getId(),
            ]);

            throw new Exception(
                $this->translator->trans('pteroca.crud.server.pterodactyl_creation_failed')
                . ': ' . $e->getMessage()
            );
        }

        if (!$freeServer) {
            $this->chargeUserBalance($user, $serverProduct, $server, $createdByAdmin);
        } else {
            $this->logFreeServerCreation($server, $user, $createdByAdmin);
        }

        return $server;
    }

    private function createServerEntity(
        User $user,
        string $serverName,
        DateTime $expiresAt,
        bool $autoRenewal,
        bool $isSuspended
    ): Server {
        $server = (new Server())
            ->setUser($user)
            ->setName($serverName)
            ->setExpiresAt($expiresAt)
            ->setAutoRenewal($autoRenewal)
            ->setIsSuspended($isSuspended);

        $server->setPterodactylServerId(0);
        $server->setPterodactylServerIdentifier('pending');

        $this->serverRepository->save($server);

        return $server;
    }

    private function linkServerProductToServer(ServerProduct $serverProduct, Server $server): void
    {
        $serverProduct->setServer($server);
        $this->serverProductRepository->save($serverProduct);
    }

    private function createPterodactylServer(
        ServerProduct $serverProduct,
        User $user,
        int $eggId,
        string $serverName
    ): PterodactylServer {
        $preparedServerBuild = $this->serverBuildService->prepareServerBuild(
            $serverProduct,
            $user,
            $eggId,
            $serverName,
            null
        );

        return $this->pterodactylApplicationService
            ->getApplicationApi()
            ->servers()
            ->createServer($preparedServerBuild);
    }

    private function updateServerWithPterodactylData(Server $server, PterodactylServer $pterodactylServer): void
    {
        $server->setPterodactylServerId($pterodactylServer->getId());
        $server->setPterodactylServerIdentifier($pterodactylServer->getIdentifier());
        $this->serverRepository->save($server);
    }

    private function rollbackServerCreation(Server $server): void
    {
        try {
            $this->serverRepository->remove($server);
        } catch (Exception $e) {
            $this->logger->error('Failed to rollback server creation', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function chargeUserBalance(
        User $user,
        ServerProduct $serverProduct,
        Server $server,
        User $createdByAdmin
    ): void {
        $selectedPrice = $serverProduct->getSelectedPrice();
        if (!$selectedPrice) {
            throw new Exception($this->translator->trans('pteroca.crud.product.at_least_one_selected_price_required'));
        }

        $priceAmount = $selectedPrice->getPrice();

        if ($user->getBalance() < $priceAmount) {
            throw new Exception($this->translator->trans('pteroca.store.insufficient_balance'));
        }

        $user->setBalance($user->getBalance() - $priceAmount);
        $this->userRepository->save($user);

        $this->logService->logAction(
            $user,
            LogActionEnum::ADMIN_CREATE_SERVER,
            [
                'server_id' => $server->getId(),
                'server_name' => $server->getName(),
                'charged_amount' => $priceAmount,
                'created_by_admin' => $createdByAdmin->getEmail(),
            ]
        );
    }

    private function logFreeServerCreation(Server $server, User $user, User $createdByAdmin): void
    {
        $this->logService->logAction(
            $user,
            LogActionEnum::ADMIN_CREATE_FREE_SERVER,
            [
                'server_id' => $server->getId(),
                'server_name' => $server->getName(),
                'created_by_admin' => $createdByAdmin->getEmail(),
            ]
        );
    }

    private function validateSelectedPrice(ServerProduct $serverProduct): void
    {
        $hasSelectedPrice = false;
        foreach ($serverProduct->getPrices() as $price) {
            if ($price->isSelected()) {
                $hasSelectedPrice = true;
                break;
            }
        }

        if (!$hasSelectedPrice) {
            throw new Exception($this->translator->trans('pteroca.crud.product.at_least_one_selected_price_required'));
        }
    }

    private function selectFirstEgg(ServerProduct $serverProduct): int
    {
        $eggs = $serverProduct->getEggs();

        if (is_string($eggs)) {
            $eggs = json_decode($eggs, true);
        }

        if (empty($eggs)) {
            throw new Exception($this->translator->trans('pteroca.admin.server_create.eggs_required'));
        }

        return (int) $eggs[0];
    }
}
