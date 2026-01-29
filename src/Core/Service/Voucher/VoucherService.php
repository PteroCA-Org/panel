<?php

namespace App\Core\Service\Voucher;

use App\Core\Contract\UserInterface;
use App\Core\DTO\Action\Result\RedeemVoucherActionResult;
use App\Core\Entity\Payment;
use App\Core\Entity\Voucher;
use App\Core\Entity\VoucherUsage;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\VoucherTypeEnum;
use App\Core\Repository\PaymentRepository;
use App\Core\Repository\ServerRepository;
use App\Core\Repository\UserRepository;
use App\Core\Repository\VoucherRepository;
use App\Core\Repository\VoucherUsageRepository;
use App\Core\Event\Voucher\VoucherRedemptionFailedEvent;
use App\Core\Event\Voucher\VoucherRedemptionRequestedEvent;
use App\Core\Event\Voucher\VoucherRedeemedEvent;
use App\Core\Service\Authorization\UserVerificationService;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\SettingService;
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class VoucherService
{
    public function __construct(
        private VoucherRepository        $voucherRepository,
        private VoucherUsageRepository   $voucherUsageRepository,
        private ServerRepository         $serverRepository,
        private PaymentRepository        $paymentRepository,
        private SettingService           $settingService,
        private UserRepository           $userRepository,
        private LogService               $logService,
        private UserVerificationService  $userVerificationService,
        private TranslatorInterface      $translator,
        private EventDispatcherInterface $eventDispatcher,
        private RequestStack             $requestStack,
        private EventContextService      $eventContextService,
    ) {}

    public function redeemVoucher(string $code, ?float $orderAmount, UserInterface $user): RedeemVoucherActionResult
    {
        // Build event context
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $voucher = null;

        try {
            $this->userVerificationService->validateUserVerification($user);

            // 1. Emit VoucherRedemptionRequestedEvent (pre, stoppable)
            $requestedEvent = new VoucherRedemptionRequestedEvent(
                $user->getId(),
                $code,
                $orderAmount,
                $context
            );
            $this->eventDispatcher->dispatch($requestedEvent);

            // Check if event was stopped (e.g., by fraud detection plugin)
            if ($requestedEvent->isPropagationStopped()) {
                $reason = $requestedEvent->getRejectionReason() ?? $this->translator->trans('pteroca.api.voucher.redemption_blocked');
                throw new Exception($reason);
            }

            $voucher = $this->getValidVoucher($code);
            $this->validateNewAccountRequirementIfNeeded($voucher, $user);
            $this->validateOneUsePerUserRequirementIfNeeded($voucher, $user);
            $this->validateMinimumTopupAmountRequirementIfNeeded($voucher, $user);
            $this->validateMinimumOrderAmountRequirementIfNeeded($orderAmount, $voucher);

            $balanceAdded = null;
            $oldBalance = null;
            $newBalance = null;
            $voucherUsageId = null;

            if ($voucher->getType() === VoucherTypeEnum::BALANCE_TOPUP) {
                $oldBalance = $user->getBalance();
                $voucherUsage = $this->redeemVoucherForUser($voucher, $user);
                $voucherUsageId = $voucherUsage->getId();
                $newBalance = $user->getBalance();
                $balanceAdded = $newBalance - $oldBalance;
            }

            // 2. Emit VoucherRedeemedEvent (post-commit)
            $redeemedEvent = new VoucherRedeemedEvent(
                $user->getId(),
                $voucher->getId(),
                $voucher->getCode(),
                $voucher->getType(),
                (float)$voucher->getValue(),
                $voucherUsageId ?? 0,
                $balanceAdded,
                $oldBalance,
                $newBalance,
                $context
            );
            $this->eventDispatcher->dispatch($redeemedEvent);

            $successMessage = $this->translator->trans('pteroca.api.voucher.successfully_applied');

            return RedeemVoucherActionResult::success(
                $successMessage,
                $voucher->getType()->value,
                $voucher->getValue(),
            );
        } catch (Exception $exception) {
            // 3. Emit VoucherRedemptionFailedEvent (error)
            $failedEvent = new VoucherRedemptionFailedEvent(
                $user->getId(),
                $code,
                $exception->getMessage(),
                $voucher?->getType(),
                $voucher ? (float)$voucher->getValue() : null,
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            return RedeemVoucherActionResult::failure(
                $exception->getMessage(),
                $voucher?->getType()->value,
                $voucher?->getValue(),
            );
        }
    }

    /**
     * Validates if a voucher can be redeemed WITHOUT actually consuming it.
     * Use this for pre-validation checks before calling redeemVoucher().
     *
     * @param string $code Voucher code to validate
     * @param float|null $orderAmount Order amount for minimum validation
     * @param UserInterface $user User attempting to redeem
     * @return Voucher The validated voucher entity
     * @throws Exception if voucher is invalid or cannot be used
     */
    public function validateVoucherForRedemption(string $code, ?float $orderAmount, UserInterface $user): Voucher
    {
        $voucher = $this->getValidVoucher($code);
        $this->userVerificationService->validateUserVerification($user);
        $this->validateNewAccountRequirementIfNeeded($voucher, $user);
        $this->validateOneUsePerUserRequirementIfNeeded($voucher, $user);
        $this->validateMinimumTopupAmountRequirementIfNeeded($voucher, $user);
        $this->validateMinimumOrderAmountRequirementIfNeeded($orderAmount, $voucher);

        return $voucher;
    }

    /**
     * @throws Exception
     */
    public function getValidVoucher(string $code): Voucher
    {
        $voucher = $this->voucherRepository->getVoucherByCode($code);

        if (empty($voucher) || !empty($voucher->getDeletedAt())) {
            throw new Exception($this->translator->trans('pteroca.api.voucher.not_found'));
        }

        if (!empty($voucher->getExpirationDate()) && $voucher->getExpirationDate() < new DateTime()) {
            throw new Exception($this->translator->trans('pteroca.api.voucher.expired'));
        }

        if (!empty($voucher->getMaxGlobalUses()) && $voucher->getUsedCount() >= $voucher->getMaxGlobalUses()) {
            throw new Exception($this->translator->trans('pteroca.api.voucher.max_global_uses_reached'));
        }

        return $voucher;
    }

    /**
     * @throws Exception
     */
    private function validateNewAccountRequirementIfNeeded(Voucher $voucher, UserInterface $user): void
    {
        if (false === $voucher->isNewAccountsOnly()) {
            return;
        }

        if (
            $this->voucherUsageRepository->hasUsedAnyVoucher($user->getId())
            || $this->serverRepository->getAllServersOwnedCount($user->getId()) > 0
            || $this->paymentRepository->getUserSuccessfulPaymentsCount($user->getId()) > 0
        ) {
            throw new Exception($this->translator->trans('pteroca.api.voucher.only_for_new_accounts'));
        }
    }

    /**
     * @throws Exception
     */
    private function validateOneUsePerUserRequirementIfNeeded(Voucher $voucher, UserInterface $user): void
    {
        if (false === $voucher->isOneUsePerUser()) {
            return;
        }

        if ($this->voucherUsageRepository->hasUsedVoucher($voucher->getCode(), $user->getId())) {
            throw new Exception($this->translator->trans('pteroca.api.voucher.already_used'));
        }
    }

    /**
     * @throws Exception
     */
    private function validateMinimumTopupAmountRequirementIfNeeded(Voucher $voucher, UserInterface $user): void
    {
        if (empty($voucher->getMinimumTopupAmount())) {
            return;
        }

        $userPayments = $this->paymentRepository->getUserSuccessfulPayments($user->getId());
        $userPaymentsSum = array_reduce($userPayments, function ($carry, Payment $payment) {
            return $carry + $payment->getAmount();
        }, 0);

        if ($voucher->getMinimumTopupAmount() > $userPaymentsSum) {
            $exceptionMessage = $this->translator->trans('pteroca.api.voucher.minimum_top_up_amount_required', [
                '{{ amount }}' => $voucher->getMinimumTopupAmount(),
                '{{ currency }}' => $this->settingService->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value),
            ]);

            throw new Exception($exceptionMessage);
        }
    }

    /**
     * @throws Exception
     */
    private function validateMinimumOrderAmountRequirementIfNeeded(?float $orderAmount, Voucher $voucher): void
    {
        if (
            empty($orderAmount)
            || $voucher->getType() === VoucherTypeEnum::BALANCE_TOPUP
            || empty($voucher->getMinimumOrderAmount())
        ) {
            return;
        }

        if ($voucher->getMinimumOrderAmount() > $orderAmount) {
            $exceptionMessage = $this->translator->trans('pteroca.api.voucher.minimum_order_amount_required', [
               '{{ amount }}' => $voucher->getMinimumOrderAmount(),
            ]);

            throw new Exception($exceptionMessage);
        }
    }

    public function redeemVoucherForUser(Voucher $voucher, UserInterface $user): VoucherUsage
    {
        $voucherUsage = (new VoucherUsage())
            ->setUser($user)
            ->setVoucher($voucher);
        $this->voucherUsageRepository->save($voucherUsage);

        $this->logService->logAction($user, LogActionEnum::VOUCHER_REDEEMED, [
            'voucher_code' => $voucher->getCode(),
            'amount' => $voucher->getValue(),
        ]);

        if ($voucher->getType() === VoucherTypeEnum::BALANCE_TOPUP) {
            $this->addVoucherBalanceTopup($voucher, $user);
        }

        $voucher->setUsedCount($voucher->getUsedCount() + 1);
        $this->voucherRepository->save($voucher);

        return $voucherUsage;
    }

    private function addVoucherBalanceTopup(Voucher $voucher, UserInterface $user): void
    {
        $updatedUserBalance = (float)$voucher->getValue() + $user->getBalance();
        $user->setBalance($updatedUserBalance);
        $this->userRepository->save($user);
    }
}
