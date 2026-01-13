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
    public function store(): Response
    {
        $categories = method_exists($this->storeService, 'getPublicCategories')
            ? $this->storeService->getPublicCategories()
            : [];

        return $this->render('store.html.twig', [
            'categories' => $categories,
        ]);
    }
}
