<?php

namespace App\Core\Service\Product;

use App\Core\Entity\Product;
use App\Core\Enum\ProductHealthStatusEnum;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;

class NestEggsCacheService
{
    private ?array $eggsCache = null;
    private bool $cacheInitialized = false;

    public function __construct(
        private readonly PterodactylApplicationService $pterodactylApplicationService,
    ) {}

    public function getAllEggsGroupedByNest(): array
    {
        if ($this->cacheInitialized) {
            return $this->eggsCache;
        }

        $this->eggsCache = [];

        try {
            $nests = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nests()
                ->all(['include' => 'eggs'])
                ->toArray();

            foreach ($nests as $nest) {
                $nestId = $nest['id'];

                $eggs = $nest['relationships']['eggs'] ?? [];

                $this->eggsCache[$nestId] = $eggs;
            }
        } catch (\Exception $e) {
            $this->eggsCache = [];
        }

        $this->cacheInitialized = true;
        return $this->eggsCache;
    }

    public function getEggsForNest(int $nestId): array
    {
        $allEggs = $this->getAllEggsGroupedByNest();
        return $allEggs[$nestId] ?? [];
    }

    public function getEggIdsForNest(int $nestId): array
    {
        $eggs = $this->getEggsForNest($nestId);
        return array_column($eggs, 'id');
    }

    public function checkProductHealth(Product $product): ProductHealthStatusEnum
    {
        $productEggs = $product->getEggs();

        if (empty($productEggs)) {
            return ProductHealthStatusEnum::NO_EGGS;
        }

        $validEggIds = $this->getEggIdsForNest($product->getNest());

        if (empty($validEggIds)) {
            return ProductHealthStatusEnum::NEST_UNAVAILABLE;
        }

        $missingEggs = array_diff($productEggs, $validEggIds);

        if (!empty($missingEggs)) {
            if (count($missingEggs) === count($productEggs)) {
                return ProductHealthStatusEnum::ALL_EGGS_INVALID;
            }
            return ProductHealthStatusEnum::SOME_EGGS_INVALID;
        }

        if ($product->getPrices()->isEmpty()) {
            return ProductHealthStatusEnum::NO_PRICES;
        }

        return ProductHealthStatusEnum::HEALTHY;
    }

    public function validateProductEggs(Product $product, string $noEggsMessage, string $invalidEggMessage, string $validationErrorMessage): void
    {
        $selectedEggs = $product->getEggs();

        if (empty($selectedEggs)) {
            throw new \RuntimeException($noEggsMessage);
        }

        try {
            $validEggIds = $this->getEggIdsForNest($product->getNest());

            if (empty($validEggIds)) {
                throw new \RuntimeException($validationErrorMessage);
            }

            foreach ($selectedEggs as $eggId) {
                if (!in_array($eggId, $validEggIds)) {
                    throw new \RuntimeException(
                        str_replace('%id%', (string)$eggId, $invalidEggMessage)
                    );
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException($validationErrorMessage);
        }
    }
}
