<?php

namespace App\Core\Service\Payment;

use App\Core\Contract\UserInterface;
use App\Core\DTO\PaymentSessionDTO;
use App\Core\Entity\Payment;
use App\Core\Enum\EmailTypeEnum;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\PaymentStatusEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\VoucherTypeEnum;
use App\Core\Event\Balance\BalanceAboutToBeAddedEvent;
use App\Core\Event\Balance\BalanceAddedEvent;
use App\Core\Event\Balance\BalancePaymentValidatedEvent;
use App\Core\Event\Balance\PaymentFinalizedEvent;
use App\Core\Exception\PaymentExpiredException;
use App\Core\Message\SendEmailMessage;
use App\Core\Repository\PaymentRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\Authorization\UserVerificationService;
use App\Core\Service\Email\EmailNotificationService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\SettingService;
use App\Core\Service\Voucher\VoucherPaymentService;
use Exception;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class PaymentService
{
    public function __construct(
        private PaymentGatewayManager    $gatewayManager,
        private PaymentRepository        $paymentRepository,
        private UserRepository           $userRepository,
        private TranslatorInterface      $translator,
        private MessageBusInterface      $messageBus,
        private SettingService           $settingService,
        private LogService               $logService,
        private UserVerificationService  $userVerificationService,
        private VoucherPaymentService    $voucherPaymentService,
        private EmailNotificationService $emailNotificationService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @throws Exception
     */
    public function createPayment(
        UserInterface $user,
        float $amount,
        string $currency,
        string $voucherCode,
        string $successUrl,
        string $cancelUrl,
        string $gateway = 'stripe',
    ): string
    {
        $this->userVerificationService->validateUserVerification($user);

        $this->validateMinimumTopupAmount($amount);

        $paymentProvider = $this->gatewayManager->getProvider($gateway);
        if ($paymentProvider === null) {
            throw new Exception($this->translator->trans('pteroca.payment.gateway_not_found'));
        }

        if (!$paymentProvider->isConfigured()) {
            throw new Exception($this->translator->trans('pteroca.payment.gateway_not_configured'));
        }

        if (!in_array($currency, $paymentProvider->getSupportedCurrencies(), true)) {
            throw new Exception($this->translator->trans('pteroca.payment.currency_not_supported'));
        }

        $balanceAmount = $amount;
        if (!empty($voucherCode)) {
            $this->voucherPaymentService->validateVoucherCode(
                $voucherCode,
                $user,
                VoucherTypeEnum::PAYMENT_DISCOUNT,
            );
            $amount = $this->voucherPaymentService->redeemPaymentVoucher($amount, $voucherCode, $user);
        }

        $session = $paymentProvider->createSession($amount, $currency, $successUrl, $cancelUrl);
        if (empty($session)) {
            throw new Exception($this->translator->trans('pteroca.recharge.failed_to_create_payment'));
        }

        $this->logService->logAction(
            $user,
            LogActionEnum::CREATE_PAYMENT,
            [
                'amount' => $amount,
                'currency' => $currency,
                'sessionId' => $session->getId(),
                'balanceAmount' => $balanceAmount,
                'voucherCode' => $voucherCode,
                'gateway' => $gateway,
            ]
        );
        $this->savePaymentSession($user, $session, $balanceAmount, $voucherCode, $gateway);

        return $session->getUrl();
    }

    /**
     * @throws PaymentExpiredException
     * @throws Exception
     */
    public function continuePayment(
        string $sessionId,
    ): string
    {
        /** @var Payment|null $payment */
        $payment = $this->paymentRepository->findOneBy(['sessionId' => $sessionId]);
        if (empty($payment)) {
            throw new Exception($this->translator->trans('pteroca.recharge.payment_not_found'));
        }

        $paymentProvider = $this->gatewayManager->getProvider($payment->getGateway());
        if ($paymentProvider === null) {
            throw new Exception($this->translator->trans('pteroca.payment.gateway_not_found'));
        }

        $retrievedSession = $paymentProvider->retrieveSession($sessionId);
        if ($retrievedSession === null) {
            throw new Exception($this->translator->trans('pteroca.recharge.payment_not_found'));
        }
        if ($retrievedSession->getPaymentStatus() === PaymentStatusEnum::PAID->value) {
            throw new Exception($this->translator->trans('pteroca.recharge.payment_already_processed'));
        }

        $url = $retrievedSession->getUrl();
        if ($url === null) {
            throw new PaymentExpiredException($this->translator->trans('pteroca.recharge.payment_expired'));
        }

        return $url;
    }

    /**
     * @throws ExceptionInterface
     */
    public function finalizePayment(UserInterface $user, string $sessionId): ?string
    {
        /** @var Payment|null $payment */
        $payment = $this->paymentRepository->findOneBy(['sessionId' => $sessionId]);
        if (empty($payment)) {
            return $this->translator->trans('pteroca.recharge.payment_not_found');
        }

        $paymentProvider = $this->gatewayManager->getProvider($payment->getGateway());
        if ($paymentProvider === null) {
            return $this->translator->trans('pteroca.payment.gateway_not_found');
        }

        $session = $paymentProvider->retrieveSession($sessionId);
        if (empty($session)) {
            return $this->translator->trans('pteroca.recharge.payment_not_found');
        }
        
        $validatedEvent = new BalancePaymentValidatedEvent(
            $user->getId(),
            $sessionId,
            $payment->getBalanceAmount(),
            $session->getCurrency(),
            $session->getPaymentStatus()
        );
        $this->eventDispatcher->dispatch($validatedEvent);

        if ($payment->getStatus() === $session->getPaymentStatus()) {
            return $this->translator->trans('pteroca.recharge.payment_already_processed');
        }

        if ($session->getPaymentStatus() === $paymentProvider::PAID_STATUS) {
            $amount = $payment->getBalanceAmount();
            $oldBalance = $user->getBalance();
            $newBalance = $oldBalance + $amount;
            
            $aboutToBeAddedEvent = new BalanceAboutToBeAddedEvent(
                $user->getId(),
                $amount,
                $oldBalance,
                $newBalance
            );
            $this->eventDispatcher->dispatch($aboutToBeAddedEvent);
            
            if ($aboutToBeAddedEvent->isPropagationStopped()) {
                return $aboutToBeAddedEvent->getRejectionReason() 
                    ?? $this->translator->trans('pteroca.recharge.payment_rejected_by_plugin');
            }
            
            $finalAmount = $aboutToBeAddedEvent->getAmount();
            $finalNewBalance = $oldBalance + $finalAmount;
            
            $user->setBalance($finalNewBalance);
            $this->userRepository->save($user);
            
            $balanceAddedEvent = new BalanceAddedEvent(
                $user->getId(),
                $finalAmount,
                $oldBalance,
                $finalNewBalance,
                $payment->getId(),
                $session->getCurrency()
            );
            $this->eventDispatcher->dispatch($balanceAddedEvent);

            $emailMessage = new SendEmailMessage(
                $user->getEmail(),
                $this->translator->trans('pteroca.email.payment.subject'),
                'email/payment_success.html.twig',
                [
                    'amount' => $finalAmount,
                    'currency' => $session->getCurrency(),
                    'internalCurrency' => $this->settingService
                        ->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value),
                    'user' => $user,
                ],
            );
            $this->messageBus->dispatch($emailMessage);
            
            $this->emailNotificationService->logEmailSent(
                $user,
                EmailTypeEnum::PAYMENT_SUCCESS,
                null,
                $this->translator->trans('pteroca.email.payment.subject')
            );

            $this->logService->logAction(
                $user,
                LogActionEnum::BOUGHT_BALANCE,
                ['amount' => $finalAmount, 'currency' => $session->getCurrency(), 'newBalance' => $finalNewBalance]
            );
        }

        $payment->setStatus($session->getPaymentStatus());
        $this->paymentRepository->save($payment);
        
        $paymentFinalizedEvent = new PaymentFinalizedEvent(
            $payment->getId(),
            $user->getId(),
            $payment->getAmount(),
            $session->getCurrency(),
            $payment->getBalanceAmount(),
            $sessionId
        );
        $this->eventDispatcher->dispatch($paymentFinalizedEvent);

        return null;
    }

    public function getUserPayments(UserInterface $user, ?int $limit = null): array
    {
        return $this->paymentRepository->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function savePaymentSession(
        UserInterface $user,
        PaymentSessionDTO $session,
        float $balanceAmount,
        string $voucherCode,
        string $gateway,
    ): void {
        if (!empty($voucherCode)) {
            $voucher = $this->voucherPaymentService->getVoucher($voucherCode);
        }

        $payment = (new Payment())
            ->setAmount($session->getAmountTotal())
            ->setCurrency($session->getCurrency())
            ->setBalanceAmount($balanceAmount)
            ->setSessionId($session->getId())
            ->setUser($user)
            ->setGateway($gateway)
            ->setStatus($session->getPaymentStatus());

        if (!empty($voucher)) {
            $payment->setUsedVoucher($voucher);
        }

        $this->paymentRepository->save($payment);
    }

    private function validateMinimumTopupAmount(float $amount): void
    {
        $minAmount = (float) ($this->settingService->getSetting(SettingEnum::MINIMUM_TOPUP_AMOUNT->value) ?? '1.00');

        if ($amount < $minAmount) {
            throw new \InvalidArgumentException(
                sprintf('Amount %.2f is below minimum topup amount of %.2f', $amount, $minAmount)
            );
        }
    }
}
