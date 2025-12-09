<?php

namespace App\Core\Service\Product;

use App\Core\Entity\Product;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;

readonly class ProductHealthCheckService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
    ) {}

    public function validateProductEggs(Product $product, string $noEggsMessage, string $invalidEggMessage, string $validationErrorMessage): void
    {
        $selectedEggs = $product->getEggs();

        if (empty($selectedEggs)) {
            throw new \RuntimeException($noEggsMessage);
        }

        try {
            $eggs = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nestEggs()
                ->all($product->getNest())
                ->toArray();

            $validEggIds = array_column($eggs, 'id');

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

    public function checkProductHealth(Product $product): array
    {
        $issues = [];

        try {
            $eggs = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nestEggs()
                ->all($product->getNest())
                ->toArray();

            $apiEggIds = array_column($eggs, 'id');
            $storedEggIds = $product->getEggs();
            $missingEggs = array_diff($storedEggIds, $apiEggIds);

            if (!empty($missingEggs)) {
                $issues[] = sprintf(
                    'Missing eggs: %s (total: %d)',
                    implode(', ', $missingEggs),
                    count($missingEggs)
                );
            }

            if (empty($apiEggIds) && !empty($storedEggIds)) {
                $issues[] = 'No valid eggs available for this product';
            }
        } catch (\Exception $e) {
            $issues[] = 'Could not validate eggs: ' . $e->getMessage();
        }

        return $issues;
    }
}
