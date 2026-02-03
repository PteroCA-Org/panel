<?php

namespace App\Core\Service;

use App\Core\Contract\ProductInterface;
use App\Core\Contract\ProductPriceInterface;
use App\Core\Contract\UserInterface;
use App\Core\Entity\Category;
use App\Core\Entity\Product;
use App\Core\Entity\Server;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Repository\CategoryRepository;
use App\Core\Repository\ProductRepository;
use App\Core\Service\Product\ProductPriceCalculatorService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Server\ServerSlotConfigurationService;
use Exception;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class StoreService
{
    public function __construct(
        private CategoryRepository             $categoryRepository,
        private ProductRepository              $productRepository,
        private PterodactylApplicationService  $pterodactylApplicationService,
        private TranslatorInterface            $translator,
        private ProductPriceCalculatorService  $productPriceCalculatorService,
        private ServerSlotConfigurationService $serverSlotConfigurationService,
        private LoggerInterface                $logger,
        private string                         $categoriesBasePath,
        private string                         $productsBasePath,
    ) {}

    public function getCategories(): array
    {
        $imagePath = $this->categoriesBasePath . '/';
        return array_map(function ($category) use ($imagePath) {
            if (!empty($category->getImagePath())) {
                $category->setImagePath( $imagePath . $category->getImagePath());
            }
            return $category;
        }, $this->categoryRepository->findBy(['deletedAt' => null], ['priority' => 'ASC', 'name' => 'ASC']));
    }

    public function getCategory(int $categoryId): ?Category
    {
        $category = $this->categoryRepository->find($categoryId);
        return ($category && $category->getDeletedAt() === null) ? $category : null;
    }

    public function getCategoryProducts(?Category $category = null): array
    {
        $imagePath = $this->productsBasePath . '/';
        return array_map(function ($product) use ($imagePath) {
            if (!empty($product->getImagePath())) {
                $product->setImagePath($imagePath . $product->getImagePath());
            }
            return $product;
        }, $this->productRepository->findBy(
            [
                'category' => $category,
                'isActive' => true,
                'deletedAt' => null,
            ],
            ['priority' => 'ASC', 'id' => 'ASC']
        ));
    }

    public function getActiveProduct(int $productId): ?Product
    {
        $product = $this->productRepository->find($productId);

        if (empty($product) || $product->getIsActive() === false || $product->getDeletedAt() !== null) {
            return null;
        }

        return $product;
    }

    public function prepareProduct(Product $product): Product
    {
        if (!empty($product->getImagePath())) {
            $imagePath = $this->productsBasePath . '/';
            $product->setImagePath($imagePath . $product->getImagePath());
        }

        if (!empty($product->getBannerPath())) {
            $bannerPath = $this->productsBasePath . '/';
            $product->setBannerPath($bannerPath . $product->getBannerPath());
        }

        return $product;
    }

    public function getProductEggs(Product $product): array
    {
        $eggs = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nestEggs()
            ->all($product->getNest())
            ->toArray();

        $apiEggIds = array_column($eggs, 'id');
        $storedEggIds = $product->getEggs();

        $missingEggIds = array_diff($storedEggIds, $apiEggIds);

        if (!empty($missingEggIds)) {
            $this->logger->warning('Product has eggs that are no longer available in Pterodactyl', [
                'product_id' => $product->getId(),
                'product_name' => $product->getName(),
                'missing_egg_ids' => $missingEggIds,
                'missing_count' => count($missingEggIds),
                'total_stored_eggs' => count($storedEggIds),
                'available_eggs' => count($apiEggIds),
            ]);
        }

        return array_filter($eggs, fn($egg) => in_array($egg['id'], $product->getEggs()));
    }

    public function productHasNodeWithResources(Product $product): bool
    {
        foreach ($product->getNodes() as $node) {
            $node = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nodes()
                ->getNode($node)
                ->toArray();

            if ($this->checkNodeResources($product->getMemory(), $product->getDiskSpace(), $node)) {
                return true;
            }
        }

        return false;
    }

    public function checkNodeResources(int $requiredMemory, int $requiredDisk, array $nodeData): bool
    {
        $totalMemory = $nodeData['memory'] + ($nodeData['memory'] * $nodeData['memory_overallocate'] / 100);
        $totalDisk = $nodeData['disk'] + ($nodeData['disk'] * $nodeData['disk_overallocate'] / 100);

        if (!isset($nodeData['allocated_resources'])) {
            return false;
        }

        $allocatedMemory = $nodeData['allocated_resources']['memory'] ?? 0;
        $allocatedDisk = $nodeData['allocated_resources']['disk'] ?? 0;

        $availableMemory = $totalMemory - $allocatedMemory;
        $availableDisk = $totalDisk - $allocatedDisk;

        if ($availableMemory >= $requiredMemory && $availableDisk >= $requiredDisk) {
            return true;
        }

        return false;
    }

    public function validateBoughtProduct(
        ProductInterface $product,
        ?int $eggId,
        int $priceId,
        ?Server $server = null,
        ?int $slots = null
    ): void
    {
        if (empty($server) && (empty($eggId) || !in_array($eggId, $product->getEggs()))) {
            throw new NotFoundHttpException($this->translator->trans('pteroca.store.egg_not_found'));
        }

        if (!empty($eggId) && empty($server)) {
            $validEggs = $this->getProductEggs($product);
            $validEggIds = array_column($validEggs, 'id');

            if (!in_array($eggId, $validEggIds)) {
                throw new NotFoundHttpException(
                    $this->translator->trans('pteroca.store.egg_not_found')
                );
            }
        }

        $productPrices = $product->getPrices()->toArray();
        $price = array_filter($productPrices, fn($price) => $price->getId() === $priceId);
        if (empty($price)) {
            throw new NotFoundHttpException($this->translator->trans('pteroca.store.price_not_found'));
        }

        $selectedPrice = current($price);
        if ($selectedPrice->getType()->value === ProductPriceTypeEnum::SLOT->value) {
            if (empty($slots) || $slots < 1) {
                throw new NotFoundHttpException($this->translator->trans('pteroca.store.invalid_slots_number'));
            }

            if (!empty($eggId)) {
                $eggsConfigurationJson = $product->getEggsConfiguration();
                $maxSlots = 32; // default value
                
                if ($eggsConfigurationJson) {
                    try {
                        $eggsConfiguration = json_decode($eggsConfigurationJson, true, 512, JSON_THROW_ON_ERROR);
                        $maxSlots = $this->serverSlotConfigurationService->getMaxSlotsFromEggConfiguration($eggsConfiguration, $eggId) ?? $maxSlots;
                    } catch (JsonException) {
                        // If JSON is invalid, use default maxSlots
                    }
                }

                if ($slots > $maxSlots) {
                    throw new NotFoundHttpException(
                        $this->translator->trans('pteroca.store.slots_exceed_maximum', ['max' => $maxSlots])
                    );
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    public function validateUserBalanceByPrice(UserInterface $user, ProductPriceInterface $selectedPrice, ?int $slots = null): void
    {
        $finalPrice = $this->productPriceCalculatorService->calculateFinalPrice($selectedPrice, $slots);

        if ($finalPrice > $user->getBalance()) {
            throw new Exception($this->translator->trans('pteroca.store.not_enough_funds'));
        }
    }

    public function getFeaturedProducts(int $limit = 6): array
    {
        $imagePath = $this->productsBasePath . '/';
        return array_map(function ($product) use ($imagePath) {
            if (!empty($product->getImagePath())) {
                $product->setImagePath($imagePath . $product->getImagePath());
            }
            return $product;
        }, $this->productRepository->findBy(
            [
                'featured' => true,
                'isActive' => true,
                'deletedAt' => null,
            ],
            ['priority' => 'ASC', 'id' => 'ASC'],
            $limit
        ));
    }

    public function getFeaturedCategories(int $limit = 6): array
    {
        $imagePath = $this->categoriesBasePath . '/';
        return array_map(function ($category) use ($imagePath) {
            if (!empty($category->getImagePath())) {
                $category->setImagePath($imagePath . $category->getImagePath());
            }
            return $category;
        }, $this->categoryRepository->findBy(
            [
                'featured' => true,
                'deletedAt' => null,
            ],
            ['priority' => 'ASC', 'name' => 'ASC'],
            $limit
        ));
    }

    public function getPublicCategories(): array
    {
        return $this->getCategories();
    }

    public function getPublicCategoriesWithProducts(): array
    {
        $categories = $this->getCategories();
        $result = [];

        foreach ($categories as $category) {
            $products = $this->getCategoryProducts($category);

            if (!empty($products)) {
                $result[] = [
                    'category' => $category,
                    'products' => $products,
                ];
            }
        }

        return $result;
    }
}
