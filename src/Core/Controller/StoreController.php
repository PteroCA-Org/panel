<?php

namespace App\Core\Controller;

use App\Core\Enum\PermissionEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Event\Store\StoreAccessedEvent;
use App\Core\Event\Store\StoreDataLoadedEvent;
use App\Core\Event\Store\StoreCategoryAccessedEvent;
use App\Core\Event\Store\StoreCategoryDataLoadedEvent;
use App\Core\Event\Store\StoreProductViewedEvent;
use App\Core\Event\Store\StoreProductDataLoadedEvent;
use App\Core\Service\Product\LocationService;
use App\Core\Service\StoreService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class StoreController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly StoreService $storeService,
        private readonly LocationService $locationService,
    ) {}

    #[Route('/panel/store', name: 'panel_store')]
    public function store(Request $request): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_SHOP);

        $this->dispatchSimpleEvent(StoreAccessedEvent::class, $request);

        $categories = $this->storeService->getCategories();
        $products = $this->storeService->getCategoryProducts();

        $this->dispatchDataEvent(
            StoreDataLoadedEvent::class,
            $request,
            [$categories, $products, count($categories), count($products)]
        );

        $viewData = [
            'categories' => $categories,
            'products' => $products,
        ];

        return $this->renderWithEvent(ViewNameEnum::STORE_INDEX, 'panel/store/index.html.twig', $viewData, $request);
    }

    #[Route('/panel/store/category', name: 'panel_store_category')]
    public function category(Request $request): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_SHOP);
        $categoryId = $request->query->getInt('id');

        $category = $this->storeService->getCategory($categoryId);
        if (empty($category)) {
            throw $this->createNotFoundException($this->translator->trans('pteroca.store.category_not_found'));
        }

        $this->dispatchDataEvent(
            StoreCategoryAccessedEvent::class,
            $request,
            [$categoryId, $category->getName()]
        );

        $products = $this->storeService->getCategoryProducts($category);

        $this->dispatchDataEvent(
            StoreCategoryDataLoadedEvent::class,
            $request,
            [$categoryId, $products, count($products)]
        );

        $viewData = [
            'category' => $category,
            'products' => $products,
        ];

        return $this->renderWithEvent(ViewNameEnum::STORE_CATEGORY, 'panel/store/list.html.twig', $viewData, $request);
    }

    #[Route('/panel/store/product', name: 'panel_store_product')]
    public function product(Request $request): Response
    {
        $this->checkPermission(PermissionEnum::ACCESS_SHOP);

        $productId = $request->query->getInt('id');
        $product = $this->storeService->getActiveProduct($productId);

        if (empty($product)) {
            throw $this->createNotFoundException($this->translator->trans('pteroca.store.product_not_found'));
        }

        $product = $this->storeService->prepareProduct($product);
        $this->dispatchDataEvent(
            StoreProductViewedEvent::class,
            $request,
            [$productId, $product->getName(), $product->getPrices()]
        );

        $preparedEggs = $this->storeService->getProductEggs($product);

        if (empty($preparedEggs) && !empty($product->getEggs())) {
            $this->addFlash(
                'warning',
                $this->translator->trans('pteroca.store.product_eggs_unavailable')
            );
        }

        $this->dispatchDataEvent(
            StoreProductDataLoadedEvent::class,
            $request,
            [$productId, $product, $preparedEggs, count($preparedEggs)]
        );

        $groupedLocations = null;
        if ($product->getAllowUserSelectLocation()) {
            $groupedLocations = $this->locationService->getGroupedNodesForProduct($product);
        }

        $viewData = [
            'product' => $product,
            'eggs' => $preparedEggs,
            'groupedLocations' => $groupedLocations,
        ];

        return $this->renderWithEvent(ViewNameEnum::STORE_PRODUCT, 'panel/store/product.html.twig', $viewData, $request);
    }
}
