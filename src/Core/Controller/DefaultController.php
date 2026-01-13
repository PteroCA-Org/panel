<?php

namespace App\Core\Controller;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use App\Core\Service\StoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly StoreService $storeService,
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

        $categories = method_exists($this->storeService, 'getFeaturedCategories')
            ? $this->storeService->getFeaturedCategories(6)
            : [];
        $featuredProducts = method_exists($this->storeService, 'getFeaturedProducts')
            ? $this->storeService->getFeaturedProducts(6)
            : [];

        return $this->render('index.html.twig', [
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
        ]);
    }

    #[Route('/store', name: 'landing_store')]
    public function store(Request $request): Response
    {
        $categoryId = $request->query->get('category');
        $categories = method_exists($this->storeService, 'getPublicCategories')
            ? $this->storeService->getPublicCategories()
            : [];

        $storeData = [];

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
            }
        }

        return $this->render('store.html.twig', [
            'storeData' => $storeData,
            'selectedCategoryId' => $categoryId,
        ]);
    }
}
