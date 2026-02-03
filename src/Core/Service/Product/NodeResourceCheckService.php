<?php

namespace App\Core\Service\Product;

use App\Core\Entity\Product;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\StoreService;

readonly class NodeResourceCheckService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private StoreService $storeService,
    ) {}

    /**
     * Check if specific node has resources for product
     * Returns: ['available' => bool, 'message' => string]
     */
    public function checkNodeAvailability(int $nodeId, Product $product): array
    {
        try {
            $node = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nodes()
                ->getNode($nodeId)
                ->toArray();

            $hasResources = $this->storeService->checkNodeResources(
                $product->getMemory(),
                $product->getDiskSpace(),
                $node
            );

            if ($hasResources) {
                return [
                    'available' => true,
                    'message' => 'pteroca.store.location_available'
                ];
            }

            return [
                'available' => false,
                'message' => 'pteroca.store.location_currently_unavailable'
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'message' => 'pteroca.store.location_check_failed'
            ];
        }
    }
}
