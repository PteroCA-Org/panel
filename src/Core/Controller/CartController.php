<?php

namespace App\Core\Controller;

use Exception;
use App\Core\Entity\Server;
use App\Core\Entity\Product;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Service\StoreService;
use App\Core\Service\SettingService;
use App\Core\Form\Cart\ServerOrderType;
use App\Core\Form\Cart\ServerRenewType;
use App\Core\Repository\UserRepository;
use App\Core\Form\Cart\TopUpBalanceType;
use Doctrine\ORM\EntityManagerInterface;
use App\Core\Repository\ServerRepository;
use App\Core\Service\PurchaseTokenService;
use App\Core\Service\Payment\PaymentService;
use App\Core\Attribute\RequiresVerifiedEmail;
use Symfony\Component\HttpFoundation\Request;
use App\Core\Event\Cart\CartBuyRequestedEvent;
use Symfony\Component\HttpFoundation\Response;
use App\Core\Service\Server\RenewServerService;
use Symfony\Component\Routing\Annotation\Route;
use App\Core\Repository\ServerSubuserRepository;
use App\Core\Service\Server\CreateServerService;
use App\Core\Event\Cart\CartPaymentRedirectEvent;
use App\Core\Event\Cart\CartRenewDataLoadedEvent;
use App\Core\Event\Cart\CartTopUpDataLoadedEvent;
use App\Core\Event\Cart\CartRenewBuyRequestedEvent;
use App\Core\Event\Cart\CartRenewPageAccessedEvent;
use App\Core\Event\Cart\CartTopUpPageAccessedEvent;
use App\Core\Service\Payment\PaymentGatewayManager;
use App\Core\Event\Cart\CartConfigureDataLoadedEvent;
use App\Core\Service\Server\ServerSlotPricingService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Event\Cart\CartConfigurePageAccessedEvent;
use App\Core\Event\Payment\PaymentGatewaysCollectedEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Core\Service\Product\LocationService;

class CartController extends AbstractController
{
    public function __construct(
        private readonly StoreService $storeService,
        private readonly ServerRepository $serverRepository,
        private readonly ServerSubuserRepository $serverSubuserRepository,
        private readonly TranslatorInterface $translator,
        private readonly ServerSlotPricingService $serverSlotPricingService,
        private readonly PurchaseTokenService $purchaseTokenService,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LocationService $locationService,
    ) {}

    #[Route('/cart/topup', name: 'cart_topup', methods: ['GET', 'POST'])]
    #[RequiresVerifiedEmail]
    public function topUpBalance(
        Request $request,
        SettingService $settingService,
        PaymentService $paymentService,
        PaymentGatewayManager $gatewayManager,
    ): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::ACCESS_WALLET->value);

        $currency = $settingService->getSetting(SettingEnum::CURRENCY_NAME->value);

        $context = $this->buildMinimalEventContext($request);
        $gatewaysEvent = new PaymentGatewaysCollectedEvent($gatewayManager, $context);
        $this->dispatchEvent($gatewaysEvent);

        $availableGateways = $gatewayManager->getProvidersForCurrency($currency);

        $gatewayChoices = [];
        foreach ($availableGateways as $gateway) {
            $gatewayChoices[$gateway->getDisplayName()] = $gateway->getIdentifier();
        }

        $initialData = [];
        if ($request->query->has('amount')) {
            $initialData['amount'] = (float) $request->query->get('amount');
        }
        if ($request->query->has('currency')) {
            $initialData['currency'] = $request->query->get('currency');
        }

        $form = $this->createForm(TopUpBalanceType::class, $initialData, [
            'currency' => $currency,
            'payment_gateways' => $gatewayChoices,
        ]);

        $form->handleRequest($request);

        $amount = $form->has('amount') && $form->get('amount')->getData()
            ? (float) $form->get('amount')->getData()
            : (float) $request->query->get('amount', 0);

        $this->dispatchDataEvent(
            CartTopUpPageAccessedEvent::class,
            $request,
            [$amount, $currency]
        );

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $formData = $form->getData();
                $gateway = $formData['gateway'];

                $paymentProvider = $gatewayManager->getProvider($gateway);
                if ($paymentProvider === null) {
                    throw new Exception($this->translator->trans('pteroca.payment.gateway_not_found'));
                }

                $baseSuccessUrl = $this->generateUrl(
                    $paymentProvider->getSuccessCallbackRoute(),
                    ['provider' => $gateway],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $baseCancelUrl = $this->generateUrl(
                    $paymentProvider->getCancelCallbackRoute(),
                    ['provider' => $gateway],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $successUrl = $paymentProvider->buildSuccessUrl($baseSuccessUrl);
                $cancelUrl = $paymentProvider->buildCancelUrl($baseCancelUrl);

                $paymentUrl = $paymentService->createPayment(
                    $this->getUser(),
                    $formData['amount'],
                    $currency,
                    $formData['voucher'] ?? '',
                    $successUrl,
                    $cancelUrl,
                    $gateway
                );

                $this->dispatchDataEvent(
                    CartPaymentRedirectEvent::class,
                    $request,
                    [$formData['amount'], $currency, $paymentUrl]
                );

                return $this->redirect($paymentUrl);
            } catch (Exception $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        $this->dispatchDataEvent(
            CartTopUpDataLoadedEvent::class,
            $request,
            [$amount, $currency]
        );

        $viewData = [
            'form' => $form,
            'availableGateways' => $availableGateways,
            'currency' => $currency,
            'request' => ['amount' => $amount, 'currency' => $currency], // For backward compatibility with template
        ];

        return $this->renderWithEvent(ViewNameEnum::CART_TOPUP, 'panel/cart/topup.html.twig', $viewData, $request);
    }

    #[Route('/cart/configure', name: 'cart_configure')]
    public function configure(Request $request): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::ACCESS_SHOP->value);

        $product = $this->getProductByRequest($request);

        $this->dispatchDataEvent(
            CartConfigurePageAccessedEvent::class,
            $request,
            [$product->getId(), $product->getName()]
        );

        $hasSlotPrices = $this->serverSlotPricingService->hasSlotPrices($product);
        $purchaseToken = $this->purchaseTokenService->generateToken($this->getUser(), 'buy');
        $preparedEggs = $this->storeService->getProductEggs($product);

        $groupedLocations = null;
        if ($product->getAllowUserSelectLocation()) {
            $groupedLocations = $this->locationService->getGroupedNodesForProduct($product);
        }

        if (empty($preparedEggs)) {
            $this->addFlash(
                'danger',
                $this->translator->trans('pteroca.store.product_configuration_unavailable')
            );

            return $this->redirectToRoute('panel', [
                'routeName' => 'panel_store_product',
                'id' => $product->getId()
            ]);
        }

        $requestData = $request->query->all();

        $eggChoices = [];
        foreach ($preparedEggs as $egg) {
            $eggChoices[$egg['name']] = $egg['id'];
        }

        $priceChoices = [];
        foreach ($product->getPrices() as $price) {
            $priceChoices[$price->getId()] = $price->getId();
        }

        $initialData = [];
        if (isset($requestData['egg'])) {
            $initialData['egg'] = (int) $requestData['egg'];
        }
        if (isset($requestData['duration'])) {
            $initialData['duration'] = (int) $requestData['duration'];
        }
        if (isset($requestData['slots'])) {
            $initialData['slots'] = (int) $requestData['slots'];
        }
        if (isset($requestData['node'])) {
            $initialData['node'] = (int) $requestData['node'];
        }

        $form = $this->createForm(ServerOrderType::class, $initialData, [
            'product_id' => $product->getId(),
            'eggs' => $eggChoices,
            'prices' => $priceChoices,
            'has_slot_prices' => $hasSlotPrices,
            'initial_slots' => isset($requestData['slots']) ? (int) $requestData['slots'] : null,
            'allow_auto_renewal' => $product->getAllowAutoRenewal(),
            'allow_location_selection' => $product->getAllowUserSelectLocation(),
            'grouped_locations' => $groupedLocations,
        ]);

        $this->dispatchDataEvent(
            CartConfigureDataLoadedEvent::class,
            $request,
            [$product->getId(), $preparedEggs, $hasSlotPrices]
        );

        $viewData = [
            'product' => $product,
            'eggs' => $preparedEggs,
            'form' => $form,
            'request' => $requestData,
            'selectedDuration' => $requestData['duration'] ?? null,
            'selectedEgg' => $requestData['egg'] ?? null,
            'selectedNode' => $requestData['node'] ?? null,
            'isProductAvailable' => $this->storeService->productHasNodeWithResources($product),
            'hasSlotPrices' => $hasSlotPrices,
            'initialSlots' => $requestData['slots'] ?? null,
            'purchase_token' => $purchaseToken,
            'allowAutoRenewal' => $product->getAllowAutoRenewal(),
            'allowLocationSelection' => $product->getAllowUserSelectLocation(),
            'groupedLocations' => $groupedLocations,
        ];

        return $this->renderWithEvent(ViewNameEnum::CART_CONFIGURE, 'panel/cart/configure.html.twig', $viewData, $request);
    }

    #[Route('/cart/buy', name: 'cart_buy', methods: ['POST'])]
    #[RequiresVerifiedEmail]
    public function buy(
        Request $request,
        CreateServerService $createServerService,
    ): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::PURCHASE_SERVER->value);

        try {
            $purchaseToken = $request->request->getString('purchase_token');
            $this->purchaseTokenService->validateAndConsumeToken($purchaseToken, $this->getUser(), 'buy');

            $product = $this->getProductByRequest($request);
            $hasSlotPrices = $this->serverSlotPricingService->hasSlotPrices($product);
            $preparedEggs = $this->storeService->getProductEggs($product);

            $groupedLocations = null;
            if ($product->getAllowUserSelectLocation()) {
                $groupedLocations = $this->locationService->getGroupedNodesForProduct($product);
            }

            if (empty($preparedEggs)) {
                throw new NotFoundHttpException(
                    $this->translator->trans('pteroca.store.product_configuration_unavailable')
                );
            }

            $eggChoices = [];
            foreach ($preparedEggs as $egg) {
                $eggChoices[$egg['name']] = $egg['id'];
            }

            $priceChoices = [];
            foreach ($product->getPrices() as $price) {
                $priceChoices[$price->getId()] = $price->getId();
            }

            $form = $this->createForm(ServerOrderType::class, null, [
                'product_id' => $product->getId(),
                'eggs' => $eggChoices,
                'prices' => $priceChoices,
                'has_slot_prices' => $hasSlotPrices,
                'initial_slots' => null,
                'allow_auto_renewal' => $product->getAllowAutoRenewal(),
                'allow_location_selection' => $product->getAllowUserSelectLocation(),
                'grouped_locations' => $groupedLocations,
            ]);

            $form->handleRequest($request);

            if (!$form->isSubmitted() || !$form->isValid()) {
                $this->addFlash('danger', $this->translator->trans('pteroca.store.invalid_form_data'));
                return $this->redirectToRoute('panel', ['routeName' => 'cart_configure', 'id' => $product->getId()]);
            }

            $formData = $form->getData();
            $eggId = $form->get('egg')->getData();
            $priceId = $form->get('duration')->getData();
            $serverName = $formData['server-name'];
            $autoRenewal = $formData['auto-renewal'] ?? false;
            $slots = $formData['slots'] ?? null;
            $voucher = $formData['voucher'] ?? '';

            $selectedNodeId = null;
            if ($product->getAllowUserSelectLocation() && isset($formData['node'])) {
                $selectedNodeId = (int) $formData['node'];

                if (!$this->locationService->validateNodeBelongsToProduct($selectedNodeId, $product)) {
                    throw new \Exception($this->translator->trans('pteroca.store.invalid_node_selection'));
                }

                $nodeOwnerProduct = $this->locationService->findProductByNodeId($selectedNodeId, $product);
                if ($nodeOwnerProduct && $nodeOwnerProduct->getId() !== $product->getId()) {
                    $product = $nodeOwnerProduct;
                }
            }

            if ($autoRenewal && !$product->getAllowAutoRenewal()) {
                throw new \Exception($this->translator->trans('pteroca.store.auto_renewal_not_allowed'));
            }

            $this->dispatchDataEvent(
                CartBuyRequestedEvent::class,
                $request,
                [$product->getId(), $eggId, $priceId, $serverName, $autoRenewal, $slots]
            );
            $result = null;
            $this->entityManager->wrapInTransaction(function() use (
                $product, $eggId, $priceId, $serverName, $autoRenewal, $slots, $voucher, $selectedNodeId, $createServerService, &$result
            ) {
                $lockedUser = $this->userRepository->findOneByIdWithLock($this->getUser()->getId());

                if (!$lockedUser) {
                    throw new \Exception($this->translator->trans('pteroca.error.user_not_found'));
                }

                $this->storeService->validateBoughtProduct(
                    $product,
                    $eggId,
                    $priceId,
                    null,
                    $slots
                );

                $result = $createServerService->createServer(
                    $product,
                    $eggId,
                    $priceId,
                    $serverName,
                    $autoRenewal,
                    $lockedUser,
                    $voucher,
                    $slots,
                    $selectedNodeId
                );
            });

            if ($result && $result['emailError']) {
                $this->addFlash('warning', $this->translator->trans($result['emailError']));
            }

            $this->addFlash('success', $this->translator->trans('pteroca.store.successful_purchase'));

            if ($result && $result['server']) {
                return $this->redirectToRoute('panel', [
                    'routeName' => 'server',
                    'id' => $result['server']->getPterodactylServerIdentifier()
                ]);
            }
        } catch (Exception $exception) {
            $flashMessage = sprintf(
                '%s: %s',
                $this->translator->trans('pteroca.store.error_during_creating_server'),
                $exception->getMessage()
            );
            $this->addFlash('danger', $flashMessage);
        }

        return $this->redirectToRoute('panel', ['routeName' => 'servers']);
    }

    #[Route('/cart/renew', name: 'cart_renew')]
    public function renew(
        Request $request,
    ): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::RENEW_SERVER->value);

        $server = $this->getServerByRequest($request);

        $this->dispatchDataEvent(
            CartRenewPageAccessedEvent::class,
            $request,
            [$server->getId(), $server->getServerProduct()->getName()]
        );

        $isOwner = $server->getUser() === $this->getUser();
        $hasSlotPrices = $this->serverSlotPricingService->hasSlotPricing($server);
        $serverSlots = null;

        if ($hasSlotPrices) {
            $serverSlots = $this->serverSlotPricingService->getServerSlots($server);
        }

        $purchaseToken = $this->purchaseTokenService->generateToken($this->getUser(), 'renew');

        $form = $this->createForm(ServerRenewType::class, null, [
            'server_id' => $server->getId(),
            'current_auto_renewal' => $server->isAutoRenewal(),
            'is_owner' => $isOwner,
            'has_slot_pricing' => $hasSlotPrices,
            'server_slots' => $serverSlots,
            'allow_auto_renewal' => $server->getServerProduct()->getAllowAutoRenewal(),
        ]);

        $this->dispatchDataEvent(
            CartRenewDataLoadedEvent::class,
            $request,
            [$server->getId(), $isOwner, $hasSlotPrices, $serverSlots]
        );

        $viewData = [
            'server' => $server,
            'form' => $form,
            'isOwner' => $isOwner,
            'hasSlotPrices' => $hasSlotPrices,
            'serverSlots' => $serverSlots ?? null,
            'purchase_token' => $purchaseToken,
            'allowAutoRenewal' => $server->getServerProduct()->getAllowAutoRenewal(),
        ];

        return $this->renderWithEvent(ViewNameEnum::CART_RENEW, 'panel/cart/renew.html.twig', $viewData, $request);
    }

    #[Route('/cart/renew/buy', name: 'cart_renew_buy', methods: ['POST'])]
    #[RequiresVerifiedEmail]
    public function renewBuy(
        Request $request,
        RenewServerService $renewServerService,
    ): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::RENEW_SERVER->value);

        try {
            $purchaseToken = $request->request->getString('purchase_token');
            $this->purchaseTokenService->validateAndConsumeToken($purchaseToken, $this->getUser(), 'renew');

            $server = $this->getServerByRequest($request);
            $isOwner = $server->getUser() === $this->getUser();

            $hasActiveSlotPricing = $this->serverSlotPricingService->hasActiveSlotPricing($server);
            $serverSlots = null;
            if ($hasActiveSlotPricing) {
                $serverSlots = $this->serverSlotPricingService->getServerSlots($server);
            }

            $form = $this->createForm(ServerRenewType::class, null, [
                'server_id'            => $server->getId(),
                'current_auto_renewal' => $server->isAutoRenewal(),
                'is_owner'             => $isOwner,
                'has_slot_pricing'     => $hasActiveSlotPricing,
                'server_slots'         => $serverSlots,
                'allow_auto_renewal'   => $server->getServerProduct()->getAllowAutoRenewal(),
            ]);
            $form->handleRequest($request);

            if (!$form->isSubmitted() || !$form->isValid()) {
                $this->addFlash('danger', $this->translator->trans('pteroca.store.invalid_form_data'));
                return $this->redirectToRoute('panel', ['routeName' => 'cart_renew', 'id' => $server->getId()]);
            }

            $formData   = $form->getData();
            $voucherCode = $formData['voucher'] ?? $request->request->getString('voucher', '');

            $this->dispatchDataEvent(
                CartRenewBuyRequestedEvent::class,
                $request,
                [$server->getId(), $voucherCode ?: null, $serverSlots]
            );

            $this->storeService->validateBoughtProduct(
                $server->getServerProduct(),
                null,
                $server->getServerProduct()->getSelectedPrice()->getId(),
                $server,
                $serverSlots,
            );

            $result = null;
            $this->entityManager->wrapInTransaction(function () use (
                $server,
                $isOwner,
                $formData,
                $voucherCode,
                $serverSlots,
                $renewServerService,
                &$result
            ) {
                $lockedUser = $this->userRepository->findOneByIdWithLock($this->getUser()->getId());
                if (!$lockedUser) {
                    throw new \Exception($this->translator->trans('pteroca.error.user_not_found'));
                }

                $result = $renewServerService->renewServer(
                    $server,
                    $lockedUser,
                    $voucherCode,
                    $serverSlots,
                );

                if ($isOwner && array_key_exists('auto-renewal', $formData)) {
                    $newAutoRenewal = ($formData['auto-renewal'] ?? '') === '1';

                    if ($newAutoRenewal && !$server->getServerProduct()->getAllowAutoRenewal()) {
                        throw new \Exception($this->translator->trans('pteroca.store.auto_renewal_not_allowed'));
                    }

                    $server->setAutoRenewal($newAutoRenewal);
                    $this->serverRepository->save($server);
                }
            });

            if (is_array($result) && !empty($result['emailError'])) {
                $this->addFlash('warning', $this->translator->trans($result['emailError']));
            }

            $this->addFlash('success', $this->translator->trans('pteroca.store.successful_purchase'));
        } catch (\Exception $exception) {
            $flashMessage = sprintf(
                '%s: %s',
                $this->translator->trans('pteroca.store.error_during_creating_server'),
                $exception->getMessage()
            );
            $this->addFlash('danger', $flashMessage);
        }

        return $this->redirectToRoute('panel', ['routeName' => 'servers']);
    }

    private function getProductByRequest(Request $request): Product
    {
        $productId = $request->request->getInt('id') ?: $request->query->getInt('id');
        $product = $this->storeService->getActiveProduct($productId);
        if (empty($product)) {
            throw $this->createNotFoundException($this->translator->trans('pteroca.store.product_not_found'));
        }

        return $product;
    }

    private function getServerByRequest(Request $request): Server
    {
        $serverId = $request->request->getInt('id') ?: $request->query->getInt('id');
        $server = $this->serverRepository->getActiveServer($serverId);

        if (empty($server) || $server->getDeletedAt()) {
            throw $this->createNotFoundException($this->translator->trans('pteroca.store.product_not_found'));
        }

        $isOwner = $server->getUser() === $this->getUser() || $this->isGranted(PermissionEnum::ACCESS_SERVERS->value);
        if ($isOwner) {
            return $server;
        }

        $subuser = $this->serverSubuserRepository->findSubuserByServerAndUser($server, $this->getUser());
        if ($subuser) {
            return $server;
        }

        throw $this->createNotFoundException($this->translator->trans('pteroca.store.product_not_found'));
    }
}
