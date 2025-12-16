<?php

namespace App\Core\Handler;

use App\Core\Entity\Server;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Event\Cli\SuspendUnpaidServers\ServerAutoRenewedEvent;
use App\Core\Event\Cli\SuspendUnpaidServers\ServerSuspendedForNonPaymentEvent;
use App\Core\Event\Cli\SuspendUnpaidServers\ServerSuspensionFailedEvent;
use App\Core\Event\Cli\SuspendUnpaidServers\SuspendUnpaidServersProcessCompletedEvent;
use App\Core\Event\Cli\SuspendUnpaidServers\SuspendUnpaidServersProcessFailedEvent;
use App\Core\Event\Cli\SuspendUnpaidServers\SuspendUnpaidServersProcessStartedEvent;
use App\Core\Repository\ServerRepository;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Mailer\ServerSuspensionEmailService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Server\RenewServerService;
use App\Core\Service\Server\ServerSlotPricingService;
use App\Core\Service\StoreService;
use DateTime;
use DateTimeImmutable;
use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class SuspendUnpaidServersHandler implements HandlerInterface
{

    public function __construct(
        private ServerRepository $serverRepository,
        private PterodactylApplicationService $pterodactylApplicationService,
        private StoreService $storeService,
        private RenewServerService $renewServerService,
        private ServerSlotPricingService $serverSlotPricingService,
        private ServerSuspensionEmailService $serverSuspensionEmailService,
        private EventDispatcherInterface $eventDispatcher,
        private EventContextService $eventContextService,
    ) {}

    /**
     * @throws Exception
     */
    public function handle(): void
    {
        $startTime = new DateTimeImmutable();
        $context = $this->eventContextService->buildCliContext('app:suspend-unpaid-servers');

        $this->eventDispatcher->dispatch(
            new SuspendUnpaidServersProcessStartedEvent($startTime, $context)
        );

        $stats = ['checked' => 0, 'suspended' => 0, 'renewed' => 0, 'failed' => 0];

        try {
            $this->handleServersToSuspend($stats, $context);

            $duration = (new DateTimeImmutable())->getTimestamp() - $startTime->getTimestamp();
            $this->eventDispatcher->dispatch(
                new SuspendUnpaidServersProcessCompletedEvent(
                    $stats['checked'],
                    $stats['suspended'],
                    $stats['renewed'],
                    $stats['failed'],
                    $duration,
                    new DateTimeImmutable(),
                    $context
                )
            );
        } catch (Exception $e) {
            $this->eventDispatcher->dispatch(
                new SuspendUnpaidServersProcessFailedEvent(
                    $e->getMessage(),
                    $stats,
                    new DateTimeImmutable(),
                    $context
                )
            );
            throw $e;
        }
    }

    private function handleServersToSuspend(array &$stats, array $context): void
    {
        $serversToSuspend = $this->serverRepository->getServersToSuspend(new DateTime());

        foreach ($serversToSuspend as $server) {
            $stats['checked']++;

            try {
                $renewalDetails = $this->tryToRenewServer($server);

                if ($renewalDetails !== null) {
                    $stats['renewed']++;

                    $this->eventDispatcher->dispatch(
                        new ServerAutoRenewedEvent(
                            $server->getUser()->getId() ?? 0,
                            $server->getId(),
                            $server->getPterodactylServerIdentifier(),
                            $server->getName(),
                            new DateTimeImmutable(),
                            $renewalDetails['cost'],
                            $context
                        )
                    );
                    continue;
                }

                $server->setIsSuspended(true);
                $this->serverRepository->save($server);

                $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->servers()
                    ->suspendServer($server->getPterodactylServerId());

                $this->serverSuspensionEmailService->sendServerSuspensionEmail($server);

                $stats['suspended']++;

                $this->eventDispatcher->dispatch(
                    new ServerSuspendedForNonPaymentEvent(
                        $server->getUser()->getId() ?? 0,
                        $server->getId(),
                        $server->getPterodactylServerIdentifier(),
                        $server->getName() ?? 'Unknown Server',
                        new DateTimeImmutable(),
                        $context
                    )
                );

            } catch (Exception $e) {
                $stats['failed']++;

                $this->eventDispatcher->dispatch(
                    new ServerSuspensionFailedEvent(
                        $server->getUser()->getId() ?? 0,
                        $server->getId(),
                        $server->getPterodactylServerIdentifier(),
                        $server->getName() ?? 'Unknown Server',
                        $e->getMessage(),
                        $context
                    )
                );

                // Continue processing other servers
            }
        }
    }

    private function tryToRenewServer(Server $server): ?array
    {
        if (!$server->isAutoRenewal() || !$server->getServerProduct()) {
            return null;
        }

        try {
            $selectedPrice = $server->getServerProduct()->getSelectedPrice();
            $slots = null;

            if ($selectedPrice->getType()->value === ProductPriceTypeEnum::SLOT->value) {
                $slots = $this->serverSlotPricingService->getServerSlots($server);
            }

            $this->storeService->validateUserBalanceByPrice(
                $server->getUser(),
                $selectedPrice,
                $slots
            );

            $this->renewServerService->renewServer($server, $server->getUser(), null, $slots);

            $cost = $selectedPrice->getPrice();
            if ($slots !== null) {
                $cost = $selectedPrice->getPrice() * $slots;
            }

            return [
                'cost' => $cost,
            ];
        } catch (Exception) {
            return null;
        }
    }
}
