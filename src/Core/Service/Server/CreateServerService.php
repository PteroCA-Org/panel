<?php

namespace App\Core\Service\Server;

use App\Core\Contract\UserInterface;
use App\Core\DTO\Pterodactyl\Application\PterodactylServer;
use App\Core\Entity\Product;
use App\Core\Entity\ProductPrice;
use App\Core\Entity\Server;
use App\Core\Entity\ServerProduct;
use App\Core\Entity\ServerProductPrice;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\VoucherTypeEnum;
use App\Core\Event\Server\ServerPurchaseValidatedEvent;
use App\Core\Event\Server\ServerAboutToBeCreatedEvent;
use App\Core\Event\Server\ServerCreatedOnPterodactylEvent;
use App\Core\Event\Server\ServerEntityCreatedEvent;
use App\Core\Event\Server\ServerProductCreatedEvent;
use App\Core\Event\Server\ServerBalanceChargedEvent;
use App\Core\Event\Server\ServerPurchaseCompletedEvent;
use App\Core\Exception\Email\ProductPriceNotFoundException;
use App\Core\Exception\Email\ServerDetailsNotAvailableException;
use App\Core\Repository\ServerProductPriceRepository;
use App\Core\Repository\ServerProductRepository;
use App\Core\Repository\ServerRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Mailer\BoughtConfirmationEmailService;
use App\Core\Service\Product\ProductPriceCalculatorService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\SettingService;
use App\Core\Service\Voucher\VoucherPaymentService;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CreateServerService extends AbstractActionServerService
{
    public function __construct(
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly ServerRepository $serverRepository,
        private readonly ServerProductRepository $serverProductRepository,
        private readonly BoughtConfirmationEmailService $boughtConfirmationEmailService,
        private readonly ServerBuildService $serverBuildService,
        private readonly ServerProductPriceRepository $serverProductPriceRepository,
        private readonly LogService $logService,
        private readonly VoucherPaymentService $voucherPaymentService,
        TranslatorInterface $translator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SettingService $settingService,
        UserRepository $userRepository,
        ProductPriceCalculatorService $productPriceCalculatorService,
        LoggerInterface $logger,
    ) {
        parent::__construct($userRepository, $pterodactylApplicationService, $voucherPaymentService, $productPriceCalculatorService, $translator, $logger);
    }

    /**
     * @throws ExceptionInterface
     * @throws ProductPriceNotFoundException
     * @throws ServerDetailsNotAvailableException
     * @throws Exception
     * @return array{server: Server, emailError: string|null}
     */
    public function createServer(
        Product $product,
        int $eggId,
        int $priceId,
        string $serverName,
        bool $autoRenewal,
        UserInterface $user,
        ?string $voucherCode = null,
        ?int $slots = null,
    ): array
    {
        if (!empty($voucherCode)) {
            $this->voucherPaymentService->validateVoucherCode(
                $voucherCode,
                $user,
                VoucherTypeEnum::SERVER_DISCOUNT,
            );
        }

        $validatedEvent = new ServerPurchaseValidatedEvent(
            $user->getId(),
            $product->getId(),
            $eggId,
            $priceId,
            $slots
        );
        $this->eventDispatcher->dispatch($validatedEvent);

        $aboutToBeCreatedEvent = new ServerAboutToBeCreatedEvent(
            $user->getId(),
            $product->getId(),
            $serverName,
            $eggId,
            $slots
        );
        $this->eventDispatcher->dispatch($aboutToBeCreatedEvent);

        if ($aboutToBeCreatedEvent->isPropagationStopped()) {
            throw new Exception($this->translator->trans('pteroca.store.server_creation_blocked'));
        }

        $createdPterodactylServer = $this->createPterodactylServer($product, $eggId, $serverName, $user, $slots);

        $createdOnPterodactylEvent = new ServerCreatedOnPterodactylEvent(
            $user->getId(),
            $createdPterodactylServer->get('id'),
            $createdPterodactylServer->get('identifier'),
            $product->getId()
        );
        $this->eventDispatcher->dispatch($createdOnPterodactylEvent);

        $createdEntityServer = $this->createEntityServer(
            $createdPterodactylServer,
            $user,
            $product,
            $priceId,
            $autoRenewal
        );

        $entityCreatedEvent = new ServerEntityCreatedEvent(
            $createdEntityServer->getId(),
            $user->getId(),
            $createdEntityServer->getPterodactylServerId(),
            $createdEntityServer->getExpiresAt()
        );
        $this->eventDispatcher->dispatch($entityCreatedEvent);

        $createdEntityServerProduct = $this->createEntityServerProduct($createdEntityServer, $product);
        $this->createEntitiesServerProductPrice($createdEntityServerProduct, $priceId);

        $productCreatedEvent = new ServerProductCreatedEvent(
            $createdEntityServerProduct->getId(),
            $createdEntityServer->getId(),
            $product->getId()
        );
        $this->eventDispatcher->dispatch($productCreatedEvent);

        $oldBalance = $user->getBalance();
        $this->updateUserBalance($user, $product, $priceId, $voucherCode, $slots);
        $newBalance = $user->getBalance();

        $finalPrice = $oldBalance - $newBalance;

        $balanceChargedEvent = new ServerBalanceChargedEvent(
            $user->getId(),
            $oldBalance,
            $newBalance,
            $createdEntityServer->getId(),
            $finalPrice,
            $this->settingService->getSetting(SettingEnum::CURRENCY_NAME->value)
        );
        $this->eventDispatcher->dispatch($balanceChargedEvent);

        $emailError = null;
        try {
            $this->boughtConfirmationEmailService->sendBoughtConfirmationEmail(
                $user,
                $createdEntityServer,
                $product,
                $priceId,
                $this->getPterodactylAccountLogin($user),
            );
        } catch (Exception $e) {
            $emailError = 'pteroca.email.creation_not_sent_misconfigured';
            $this->logger->error('Failed to send purchase confirmation email', [
                'user_id' => $user->getId(),
                'server_id' => $createdEntityServer->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logService->logAction(
            $user,
            LogActionEnum::BOUGHT_SERVER,
            [
                'product' => $product,
                'egg' => $eggId,
                'price' => $priceId,
                'voucher' => $voucherCode,
                'server' => $createdEntityServer,
            ],
        );

        // 7. Emit ServerPurchaseCompletedEvent (after the entire process)
        $purchaseCompletedEvent = new ServerPurchaseCompletedEvent(
            $createdEntityServer->getId(),
            $user->getId(),
            $product->getId(),
            $finalPrice
        );
        $this->eventDispatcher->dispatch($purchaseCompletedEvent);

        return [
            'server' => $createdEntityServer,
            'emailError' => $emailError,
        ];
    }

    /**
     * @throws Exception
     */
    private function createPterodactylServer(
        Product $product,
        int $eggId,
        string $serverName,
        UserInterface $user,
        ?int $slots = null
    ): PterodactylServer
    {
        try {
            $preparedServerBuild = $this->serverBuildService
                ->prepareServerBuild($product, $user, $eggId, $serverName, $slots);

            return $this->pterodactylApplicationService
                ->getApplicationApi()
                ->servers()
                ->createServer($preparedServerBuild);
        } catch (ClientExceptionInterface $exception) {
            // Handle HTTP 4xx errors (validation, authentication, etc.)
            $this->logger->error('Pterodactyl API client error: ' . $exception->getMessage());
            throw new Exception(
                $this->translator->trans('pteroca.store.server_creation_failed') . ': ' . $exception->getMessage()
            );
        } catch (Exception $exception) {
            // Handle other errors from API adapter
            $this->logger->error('Failed to create server on Pterodactyl: ' . $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @throws Exception
     */
    private function createEntityServer(
        PterodactylServer $server,
        UserInterface $user,
        Product $product,
        int $priceId,
        bool $autoRenewal
    ): Server
    {
        /** @var ?ProductPrice $selectedPrice */
        $selectedPrice = $product->getPrices()->filter(
            fn(ProductPrice $price) => $price->getId() === $priceId
        )->first() ?: null;

        if (empty($selectedPrice)) {
            throw new Exception($this->translator->trans('pteroca.store.price_not_found'));
        }

        $datetimeModifier = sprintf(
            '+%d %s',
            $selectedPrice->getValue(),
            $selectedPrice->getUnit()->value
        );
        $autoRenewalStatus = $autoRenewal || $selectedPrice->getType() === ProductPriceTypeEnum::ON_DEMAND;
        $entityServer = (new Server())
            ->setPterodactylServerId($server->get('id'))
            ->setPterodactylServerIdentifier($server->get('identifier'))
            ->setUser($user)
            ->setExpiresAt(new DateTime($datetimeModifier))
            ->setAutoRenewal($autoRenewalStatus);

        $this->serverRepository->save($entityServer);

        return $entityServer;
    }

    private function createEntityServerProduct(Server $server, Product $product): ServerProduct
    {
        $entityServerProduct = (new ServerProduct())
            ->setServer($server)
            ->setOriginalProduct($product)
            ->setName($product->getName())
            ->setDiskSpace($product->getDiskSpace())
            ->setMemory($product->getMemory())
            ->setIo($product->getIo())
            ->setCpu($product->getCpu())
            ->setDbCount($product->getDbCount())
            ->setSwap($product->getSwap())
            ->setBackups($product->getBackups())
            ->setPorts($product->getPorts())
            ->setNodes($product->getNodes())
            ->setNest($product->getNest())
            ->setEggs($product->getEggs())
            ->setEggsConfiguration($product->getEggsConfiguration())
            ->setAllowChangeEgg($product->getAllowChangeEgg());

        $this->serverProductRepository->save($entityServerProduct);
        
        $server->setServerProduct($entityServerProduct);
        $this->serverRepository->save($server);

        return $entityServerProduct;
    }

    private function createEntitiesServerProductPrice(ServerProduct $serverProduct, int $selectedPriceId): void
    {
        foreach ($serverProduct->getOriginalProduct()->getPrices() as $price) {
            $serverProductPrice = (new ServerProductPrice())
                ->setServerProduct($serverProduct)
                ->setType($price->getType())
                ->setValue($price->getValue())
                ->setUnit($price->getUnit())
                ->setPrice($price->getPrice())
                ->setIsSelected($price->getId() === $selectedPriceId);

            $this->serverProductPriceRepository->save($serverProductPrice);
        }
    }
}
