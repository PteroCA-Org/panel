<?php

namespace App\Core\Controller;

use App\Core\Enum\SettingEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Enum\WidgetContext;
use App\Core\Event\Landing\LandingPageAccessedEvent;
use App\Core\Event\Landing\LandingPageDataLoadedEvent;
use App\Core\Event\Landing\NavigationButtonsCollectedEvent;
use App\Core\Event\Widget\WidgetsCollectedEvent;
use App\Core\Service\SettingService;
use App\Core\Service\StoreService;
use App\Core\Service\Widget\WidgetRegistry;
use App\Core\Trait\EventContextTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    use EventContextTrait;

    public function __construct(
        private readonly SettingService $settingService,
        private readonly StoreService $storeService,
        private readonly WidgetRegistry $widgetRegistry,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/', name: 'homepage')]
    public function index(): Response
    {
        $landingPageEnabled = (bool) $this->settingService->getSetting(
            SettingEnum::LANDING_PAGE_ENABLED->value
        );

        if (!$landingPageEnabled) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();

        // 1. Dispatch access event
        $this->dispatchDataEvent(
            LandingPageAccessedEvent::class,
            $request,
            ['homepage']
        );

        // 2. Widget collection
        $contextData = ['user' => $user, 'pageType' => 'homepage'];
        $widgetEvent = new WidgetsCollectedEvent(
            $this->widgetRegistry,
            WidgetContext::LANDING_HOMEPAGE,
            $contextData
        );
        $this->dispatchEvent($widgetEvent);

        // 3. Load data
        $categories = method_exists($this->storeService, 'getFeaturedCategories')
            ? $this->storeService->getFeaturedCategories(6)
            : [];
        $featuredProducts = method_exists($this->storeService, 'getFeaturedProducts')
            ? $this->storeService->getFeaturedProducts(6)
            : [];

        // 4. Dispatch data loaded event
        $this->dispatchDataEvent(
            LandingPageDataLoadedEvent::class,
            $request,
            ['homepage', count($categories), count($featuredProducts), null]
        );

        // 5. Collect navigation buttons
        $navigationButtons = $this->collectNavigationButtons($user, $request);

        // 6. Prepare view data
        $viewData = [
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
            'widgetRegistry' => $this->widgetRegistry,
            'widgetContext' => WidgetContext::LANDING_HOMEPAGE,
            'contextData' => $contextData,
            'navigationButtons' => $navigationButtons,
        ];

        // 7. Dispatch view event
        $viewEvent = $this->prepareViewDataEvent(
            ViewNameEnum::LANDING_HOMEPAGE,
            $viewData,
            $request
        );

        return $this->render('index.html.twig', $viewEvent->getViewData());
    }

    #[Route('/store', name: 'landing_store')]
    public function store(Request $request): Response
    {
        $user = $this->getUser();

        // 1. Dispatch access event
        $this->dispatchDataEvent(
            LandingPageAccessedEvent::class,
            $request,
            ['store']
        );

        // 2. Widget collection
        $contextData = ['user' => $user, 'pageType' => 'store'];
        $widgetEvent = new WidgetsCollectedEvent(
            $this->widgetRegistry,
            WidgetContext::LANDING_STORE,
            $contextData
        );
        $this->dispatchEvent($widgetEvent);

        // 3. Load data
        $categoryId = $request->query->get('category');
        $categories = method_exists($this->storeService, 'getPublicCategories')
            ? $this->storeService->getPublicCategories()
            : [];

        $storeData = [];
        $productsCount = 0;

        if ($categoryId) {
            $selectedCategory = null;
            foreach ($categories as $category) {
                if ($category->getId() == $categoryId) {
                    $selectedCategory = $category;
                    break;
                }
            }

            if ($selectedCategory) {
                $products = method_exists($this->storeService, 'getCategoryProducts')
                    ? $this->storeService->getCategoryProducts($selectedCategory)
                    : [];

                if (!empty($products)) {
                    $storeData[] = [
                        'category' => $selectedCategory,
                        'products' => $products
                    ];
                    $productsCount = count($products);
                }
            }
        } else {
            foreach ($categories as $category) {
                $products = method_exists($this->storeService, 'getCategoryProducts')
                    ? $this->storeService->getCategoryProducts($category)
                    : [];

                if (empty($products)) {
                    continue;
                }

                $storeData[] = [
                    'category' => $category,
                    'products' => $products
                ];
                $productsCount += count($products);
            }

            $uncategorizedProducts = method_exists($this->storeService, 'getCategoryProducts')
                ? $this->storeService->getCategoryProducts(null)
                : [];

            if (!empty($uncategorizedProducts)) {
                $storeData[] = [
                    'category' => (object) ['name' => 'pteroca.store.products_with_no_category'],
                    'products' => $uncategorizedProducts,
                    'is_translation_key' => true
                ];
                $productsCount += count($uncategorizedProducts);
            }
        }

        // 4. Dispatch data loaded event
        $this->dispatchDataEvent(
            LandingPageDataLoadedEvent::class,
            $request,
            ['store', count($categories), $productsCount, $categoryId]
        );

        // 5. Collect navigation buttons
        $navigationButtons = $this->collectNavigationButtons($user, $request);

        // 6. Prepare view data
        $viewData = [
            'storeData' => $storeData,
            'selectedCategoryId' => $categoryId,
            'widgetRegistry' => $this->widgetRegistry,
            'widgetContext' => WidgetContext::LANDING_STORE,
            'contextData' => $contextData,
            'navigationButtons' => $navigationButtons,
        ];

        // 7. Dispatch view event
        $viewEvent = $this->prepareViewDataEvent(
            ViewNameEnum::LANDING_STORE,
            $viewData,
            $request
        );

        return $this->render('store.html.twig', $viewEvent->getViewData());
    }

    /**
     * Collect navigation buttons for landing page header.
     */
    private function collectNavigationButtons(?object $user, Request $request): array
    {
        $defaultButtons = $this->buildDefaultNavigationButtons($user);

        $context = $this->buildMinimalEventContext($request);
        $event = new NavigationButtonsCollectedEvent(
            $user,
            $defaultButtons,
            'landing_page',
            $context
        );

        $event = $this->dispatchEvent($event);

        return $this->sortNavigationButtons($event->getButtons());
    }

    /**
     * Build default navigation buttons based on user authentication state.
     */
    private function buildDefaultNavigationButtons(?object $user): array
    {
        if ($user) {
            return [
                [
                    'type' => 'primary',
                    'label' => 'pteroca.landing.nav.dashboard',
                    'url' => $this->generateUrl('panel'),
                    'icon' => 'fas fa-tachometer-alt',
                    'position' => 'right',
                    'priority' => 100,
                    'condition' => 'authenticated',
                ]
            ];
        }

        return [
            [
                'type' => 'secondary',
                'label' => 'pteroca.landing.nav.login',
                'url' => $this->generateUrl('app_login'),
                'position' => 'right',
                'priority' => 50,
                'condition' => 'guest',
            ],
            [
                'type' => 'primary',
                'label' => 'pteroca.landing.nav.get_started',
                'url' => $this->generateUrl('app_register'),
                'position' => 'right',
                'priority' => 100,
                'condition' => 'guest',
            ],
        ];
    }

    /**
     * Sort navigation buttons by priority and position.
     */
    private function sortNavigationButtons(array $buttons): array
    {
        usort($buttons, function($a, $b) {
            // Primary sort by priority (higher first)
            $priorityDiff = ($b['priority'] ?? 50) - ($a['priority'] ?? 50);
            if ($priorityDiff !== 0) {
                return $priorityDiff;
            }

            // Secondary sort by position (right before left)
            $positionOrder = ['right' => 0, 'left' => 1];
            return ($positionOrder[$a['position'] ?? 'right'] ?? 0) -
                   ($positionOrder[$b['position'] ?? 'right'] ?? 0);
        });

        return $buttons;
    }
}
