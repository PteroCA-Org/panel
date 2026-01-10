<?php

namespace App\Core\Controller;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use App\Core\Service\StoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        // Check if landing page is enabled
        $landingPageEnabled = (bool) $this->settingService->getSetting(
            SettingEnum::LANDING_PAGE_ENABLED->value
        );

        // If landing page is disabled, redirect to login
        if (!$landingPageEnabled) {
            return $this->redirectToRoute('app_login');
        }

        // Render landing page
        // Note: getFeaturedProducts/Categories methods will be added in next phase
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
    public function store(): Response
    {
        // Full product listing on landing page
        $categories = method_exists($this->storeService, 'getPublicCategories')
            ? $this->storeService->getPublicCategories()
            : [];

        return $this->render('store.html.twig', [
            'categories' => $categories,
        ]);
    }
}
