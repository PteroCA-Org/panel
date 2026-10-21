<?php

namespace App\Core\Service\Server;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Server;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\VoucherTypeEnum;
use App\Core\Event\Server\ServerRenewalValidatedEvent;
use App\Core\Event\Server\ServerAboutToBeRenewedEvent;
use App\Core\Event\Server\ServerExpirationExtendedEvent;
use App\Core\Event\Server\ServerUnsuspendedEvent;
use App\Core\Event\Server\ServerRenewalBalanceChargedEvent;
use App\Core\Event\Server\ServerRenewalBeforeBalanceChargeEvent;
use App\Core\Event\Server\ServerRenewalCompletedEvent;
use App\Core\Exception\Email\ProductPriceNotFoundException;
use App\Core\Exception\Email\ServerDetailsNotAvailableException;
use App\Core\Repository\ServerRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Mailer\BoughtConfirmationEmailService;
use App\Core\Service\Product\ProductPriceCalculatorService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\SettingService;
use App\Core\Service\Voucher\VoucherPaymentService;
use DateInvalidOperationException;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RenewServerService extends AbstractActionServerService
{
    public function __construct(
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly ServerRepository $serverRepository,
        private readonly BoughtConfirmationEmailService $boughtConfirmationEmailService,
        private readonly LogService $logService,
        private readonly VoucherPaymentService $voucherPaymentService,
        private readonly ServerSlotPricingService $serverSlotPricingService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SettingService $settingService,
        UserRepository $userRepository,
        ProductPriceCalculatorService $productPriceCalculatorService,
        TranslatorInterface $translator,
        LoggerInterface $logger,
    ) {
        parent::__construct($userRepository, $pterodactylApplicationService, $voucherPaymentService, $productPriceCalculatorService, $translator, $logger);
    }

    /**
     * @throws DateInvalidOperationException
     * @throws ProductPriceNotFoundException
     * @throws ServerDetailsNotAvailableException
     * @throws ExceptionInterface
     * @throws Exception
     * @return array{emailError: string|null}
     */
    public function renewServer(
        Server $server,
        UserInterface $user,
        ?string $voucherCode = null,
        ?int $slots = null
    ): array
    {
        if (!empty($voucherCode)) {
            $this->voucherPaymentService->validateVoucherCode(
                $voucherCode,
                $user,
                VoucherTypeEnum::SERVER_DISCOUNT,
            );
        }

        $selectedPrice = $server->getServerProduct()->getSelectedPrice();

        $validatedEvent = new ServerRenewalValidatedEvent(
            $user->getId(),
            $server->getId(),
            $selectedPrice->getId(),
            $slots
        );
        $this->eventDispatcher->dispatch($validatedEvent);

        $currentExpirationDate = $server->getExpiresAt();
        if ($currentExpirationDate < new DateTime()) {
            $currentExpirationDate = new DateTime();
        } else {
            $currentExpirationDate = clone $currentExpirationDate;
        }
        
        if ($slots === null && $selectedPrice->getType()->value === ProductPriceTypeEnum::SLOT->value) {
            $slots = $this->serverSlotPricingService->getServerSlots($server);
        }
        
        if ($selectedPrice->getType() === ProductPriceTypeEnum::ON_DEMAND) {
            try {
                $pterodactylClientApi = $this->pterodactylApplicationService
                    ->getClientApi($user);
                $serverResources = $pterodactylClientApi->servers()
                    ->getServerResources($server->getPterodactylServerIdentifier());
            } catch (Exception) {
                $serverResources = null;
            }

            $isServerOffline = $serverResources === null || $serverResources['current_state'] === 'offline';
            $chargeBalance = !$isServerOffline;
        } else {
            $chargeBalance = true;
        }

        $expirationDateModifier = sprintf('+%d %s', $selectedPrice->getValue(), $selectedPrice->getUnit()->value);
        $newExpirationDate = (clone $currentExpirationDate)->modify($expirationDateModifier);

        // 2. Emit ServerAboutToBeRenewedEvent (before renewal, stoppable)
        $aboutToBeRenewedEvent = new ServerAboutToBeRenewedEvent(
            $user->getId(),
            $server->getId(),
            $currentExpirationDate,
            $newExpirationDate,
            $slots
        );
        $this->eventDispatcher->dispatch($aboutToBeRenewedEvent);

        // Check if event was stopped (e.g. by fraud detection)
        if ($aboutToBeRenewedEvent->isPropagationStopped()) {
            throw new Exception($this->translator->trans('pteroca.store.server_renewal_blocked'));
        }

        $oldExpiresAt = $server->getExpiresAt();
        $server->setExpiresAt($newExpirationDate);

        // 3. Emit ServerExpirationExtendedEvent (after setExpiresAt)
        $expirationExtendedEvent = new ServerExpirationExtendedEvent(
            $server->getId(),
            $user->getId(),
            $oldExpiresAt,
            $server->getExpiresAt()
        );
        $this->eventDispatcher->dispatch($expirationExtendedEvent);

        // 4. Emit ServerUnsuspendedEvent (if was suspended)
        $wasSuspended = $server->getIsSuspended();
        if ($wasSuspended) {
            $this->pterodactylApplicationService
                ->getApplicationApi()
                ->servers()
                ->unsuspendServer($server->getPterodactylServerId());
            $server->setIsSuspended(false);

            $unsuspendedEvent = new ServerUnsuspendedEvent(
                $server->getId(),
                $user->getId(),
                $server->getPterodactylServerId()
            );
            $this->eventDispatcher->dispatch($unsuspendedEvent);
        }

        $this->serverRepository->save($server);

        // 5. Emit ServerRenewalBeforeBalanceChargeEvent + ServerRenewalBalanceChargedEvent
        $oldBalance = $user->getBalance();
        $currency = $this->settingService->getSetting(SettingEnum::CURRENCY_NAME->value);
        $calculatedAmount = $chargeBalance
            ? $this->productPriceCalculatorService->calculateFinalPrice($selectedPrice, $slots)
            : 0;

        $beforeChargeEvent = new ServerRenewalBeforeBalanceChargeEvent(
            $user->getId(),
            $server->getId(),
            $calculatedAmount,
            $currency,
            $voucherCode,
        );
        $this->eventDispatcher->dispatch($beforeChargeEvent);

        if ($chargeBalance && !$beforeChargeEvent->isBalanceChargeSkipped()) {
            $this->updateUserBalance($user, $server->getServerProduct(), $selectedPrice->getId(), $voucherCode, $slots);
        }

        $newBalance = $user->getBalance();
        $finalPrice = $oldBalance - $newBalance;

        if ($chargeBalance) {
            $balanceChargedEvent = new ServerRenewalBalanceChargedEvent(
                $user->getId(),
                $oldBalance,
                $newBalance,
                $server->getId(),
                $finalPrice,
                $currency
            );
            $this->eventDispatcher->dispatch($balanceChargedEvent);
        }

        $previousExpiresAt = clone $currentExpirationDate;
        $emailError = null;
        if ($this->boughtConfirmationEmailService->shouldSendRenewalNotification($server, $previousExpiresAt, $server->getExpiresAt())) {
            // Try to send renewal confirmation email, but don't fail if email is misconfigured
            try {
                $this->boughtConfirmationEmailService->sendRenewConfirmationEmail(
                    $user,
                    $server,
                    $this->getPterodactylAccountLogin($user),
                );
            } catch (\Exception $e) {
                $emailError = 'pteroca.email.renewal_not_sent_misconfigured';
                $this->logger->error('Failed to send renewal confirmation email', [
                    'user_id' => $user->getId(),
                    'server_id' => $server->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logService->logAction(
            $user,
            LogActionEnum::RENEW_SERVER,
            [
                'server_id' => $server->getId(),
                'server_name' => $server->getName(),
                'pterodactyl_server_id' => $server->getPterodactylServerId(),
                'charged_amount' => round($finalPrice, 2),
                'currency' => $currency,
                'voucher' => $voucherCode,
                'previous_expires_at' => $previousExpiresAt->format('Y-m-d H:i:s'),
                'new_expires_at' => $server->getExpiresAt()->format('Y-m-d H:i:s'),
            ],
        );

        // 6. Emit ServerRenewalCompletedEvent (after the entire process)
        $renewalCompletedEvent = new ServerRenewalCompletedEvent(
            $server->getId(),
            $user->getId(),
            $chargeBalance ? $finalPrice : 0.0,
            $server->getExpiresAt()
        );
        $this->eventDispatcher->dispatch($renewalCompletedEvent);

        return [
            'emailError' => $emailError,
        ];
    }
}
