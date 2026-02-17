<?php

namespace App\Core\Controller;

use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Event\Balance\BalanceRechargePageAccessedEvent;
use App\Core\Event\Balance\BalanceRechargeFormDataLoadedEvent;
use App\Core\Event\Balance\BalancePaymentCallbackAccessedEvent;
use App\Core\Event\Form\FormBuildEvent;
use App\Core\Event\Payment\PaymentGatewaysCollectedEvent;
use App\Core\Service\Payment\PaymentService;
use App\Core\Service\Payment\PaymentGatewayManager;
use App\Core\Service\SettingService;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class BalanceController extends AbstractController
{

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly SettingService $settingService,
        private readonly TranslatorInterface $translator,
        private readonly PaymentGatewayManager $gatewayManager,
    ) {
    }

    #[Route('/wallet/recharge', name: 'recharge_balance')]
    public function rechargeBalance(
        Request $request,
    ): Response {
        $this->checkPermission(PermissionEnum::ACCESS_WALLET);

        $this->dispatchDataEvent(
            BalanceRechargePageAccessedEvent::class,
            $request,
            [$this->getUser()->getBalance()]
        );

        $currency = $this->settingService
            ->getSetting(SettingEnum::CURRENCY_NAME->value);
        $minAmount = (float) ($this->settingService
            ->getSetting(SettingEnum::MINIMUM_TOPUP_AMOUNT->value) ?? '1.00');

        $formBuilder = $this->createFormBuilder()
            ->add('amount', MoneyType::class, [
                'currency' => $currency,
                'label' => sprintf(
                    '%s (%s)',
                    $this->translator->trans('pteroca.recharge.recharge_amount'),
                    $currency
                ),
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.recharge.amount_required'),
                    new Assert\Positive(message: 'pteroca.recharge.amount_must_be_positive'),
                    new Assert\Range(
                        minMessage: 'pteroca.recharge.amount_minimum_not_reached',
                        min: $minAmount
                    ),
                ],
                'attr' => [
                    'min' => $minAmount,
                    'step' => '0.01',
                ],
            ])
            ->add('currency', HiddenType::class, [
                'data' => $currency,
            ]);

        $context = $this->buildMinimalEventContext($request);
        $this->dispatchEvent(new FormBuildEvent($formBuilder, 'balance_recharge', $context));

        $form = $formBuilder->getForm();
        $form->handleRequest($request);

        $this->dispatchDataEvent(
            BalanceRechargeFormDataLoadedEvent::class,
            $request,
            [$this->getUser()->getBalance(), $currency]
        );

        $gatewaysEvent = new PaymentGatewaysCollectedEvent($this->gatewayManager, $context);
        $this->dispatchEvent($gatewaysEvent);

        $availableGateways = $this->gatewayManager->getProvidersForCurrency($currency);

        $viewData = [
            'form' => $form->createView(),
            'balance' => $this->getUser()->getBalance(),
            'availableGateways' => $availableGateways,
            'currency' => $currency,
            'minAmount' => $minAmount,
        ];

        return $this->renderWithEvent(ViewNameEnum::BALANCE_RECHARGE, 'panel/wallet/recharge.html.twig', $viewData, $request);
    }

    #[Route('/wallet/{provider}/success', name: 'payment_callback_success', requirements: ['provider' => '[a-z]+'])]
    public function paymentSuccess(Request $request, string $provider): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_WALLET);

        $paymentProvider = $this->gatewayManager->getProvider($provider);
        if ($paymentProvider === null) {
            $this->addFlash('danger', $this->translator->trans('pteroca.payment.gateway_not_found'));
            return $this->redirectToRechargeBalance();
        }

        $sessionId = $paymentProvider->extractSessionIdFromCallback($request);

        $this->dispatchDataEvent(
            BalancePaymentCallbackAccessedEvent::class,
            $request,
            [$sessionId, 'success']
        );

        if (empty($sessionId)) {
            $this->addFlash('danger', $this->translator->trans('pteroca.recharge.invalid_session_id'));
            return $this->redirectToRechargeBalance();
        }

        $error = $this->paymentService->finalizePayment($this->getUser(), $sessionId);
        if (!empty($error)) {
            $this->addFlash('danger', $error);
            return $this->redirectToRechargeBalance();
        }

        $this->addFlash('success', $this->translator->trans('pteroca.recharge.payment_success'));

        return $this->redirectToRechargeBalance();
    }

    #[Route('/wallet/{provider}/cancel', name: 'payment_callback_cancel', requirements: ['provider' => '[a-z]+'])]
    public function paymentCancel(Request $request, string $provider): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_WALLET);

        $this->dispatchDataEvent(
            BalancePaymentCallbackAccessedEvent::class,
            $request,
            [null, 'cancel']
        );

        $this->addFlash('danger', $this->translator->trans('pteroca.recharge.payment_canceled'));

        return $this->redirectToRechargeBalance();
    }

    /**
     * @deprecated Since v0.6, use paymentSuccess() with provider-specific routes
     * This route is maintained for backward compatibility with payments in progress
     * Will be removed in v0.7.0
     */
    #[Route('/wallet/recharge/success', name: 'stripe_success')]
    public function success(Request $request): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_WALLET);

        $sessionId = $request->query->get('session_id');

        $this->dispatchDataEvent(
            BalancePaymentCallbackAccessedEvent::class,
            $request,
            [$sessionId, 'success']
        );

        if (empty($sessionId)) {
            $this->addFlash('danger', $this->translator->trans('pteroca.recharge.invalid_session_id'));
            return $this->redirectToRechargeBalance();
        }

        $error = $this->paymentService->finalizePayment($this->getUser(), $sessionId);
        if (!empty($error)) {
            $this->addFlash('danger', $error);
            return $this->redirectToRechargeBalance();
        }

        $this->addFlash('success', $this->translator->trans('pteroca.recharge.payment_success'));

        return $this->redirectToRechargeBalance();
    }

    /**
     * @deprecated Since v0.6, use paymentCancel() with provider-specific routes
     * This route is maintained for backward compatibility with payments in progress
     * Will be removed in v0.7.0
     */
    #[Route('/wallet/recharge/cancel', name: 'stripe_cancel')]
    public function cancel(Request $request): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_WALLET);

        $this->dispatchDataEvent(
            BalancePaymentCallbackAccessedEvent::class,
            $request,
            [null, 'cancel']
        );

        $this->addFlash('danger', $this->translator->trans('pteroca.recharge.payment_canceled'));

        return $this->redirectToRechargeBalance();
    }

    private function redirectToRechargeBalance(): Response
    {
        return $this->redirectToRoute('panel', ['routeName' => 'recharge_balance']);
    }
}
