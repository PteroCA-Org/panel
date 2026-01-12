<?php

namespace App\Core\Service\Product;

use App\Core\Entity\Product;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;

readonly class LocationService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
    ) {}

    /**
     * Get all nodes grouped by location for a product and its variants
     * Returns: ['locationName' => ['nodes' => [['id' => X, 'name' => 'Node 1', 'productId' => Y], ...], 'locationShort' => 'LOC']]
     */
    public function getGroupedNodesForProduct(Product $product): array
    {
        $allProducts = $this->getProductsWithVariants($product);
        $nodeIds = $this->collectNodeIds($allProducts);

        return $this->groupNodesByLocation($nodeIds, $allProducts);
    }

    public function validateNodeBelongsToProduct(int $nodeId, Product $product): bool
    {
        $allProducts = $this->getProductsWithVariants($product);

        foreach ($allProducts as $prod) {
            if (in_array($nodeId, $prod->getNodes())) {
                return true;
            }
        }

        return false;
    }

    public function findProductByNodeId(int $nodeId, Product $baseProduct): ?Product
    {
        $allProducts = $this->getProductsWithVariants($baseProduct);

        foreach ($allProducts as $product) {
            if (in_array($nodeId, $product->getNodes())) {
                return $product;
            }
        }

        return null;
    }

    private function getProductsWithVariants(Product $product): array
    {
        $products = [$product];
        foreach ($product->getVariantProducts() as $variant) {
            if ($variant->getIsActive() && !$variant->getDeletedAt()) {
                $products[] = $variant;
            }
        }
        return $products;
    }

    private function collectNodeIds(array $products): array
    {
        $nodeIds = [];
        foreach ($products as $prod) {
            $nodeIds = array_merge($nodeIds, $prod->getNodes());
        }
        return array_unique($nodeIds);
    }

    /**
     * Group nodes by location with product association
     * Returns: [
     *   'Location Name' => [
     *     'nodes' => [['id' => X, 'name' => 'Node 1', 'productId' => Y], ...],
     *     'locationShort' => 'LOC'
     *   ]
     * ]
     */
    private function groupNodesByLocation(array $nodeIds, array $products): array
    {
        if (empty($nodeIds)) {
            return [];
        }

        $nodes = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nodes()
            ->getAllNodes()
            ->toArray();

        $locations = [];
        $grouped = [];

        foreach ($nodes as $node) {
            if (!in_array($node['id'], $nodeIds)) {
                continue;
            }

            // Fetch location if not cached
            if (!isset($locations[$node['location_id']])) {
                $locations[$node['location_id']] = $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->locations()
                    ->get($node['location_id']);
            }

            $location = $locations[$node['location_id']];
            $locationName = $location['long'] ?? $location['short'];

            if (!isset($grouped[$locationName])) {
                $grouped[$locationName] = [
                    'nodes' => [],
                    'locationShort' => $location['short'],
                ];
            }

            // Find which product owns this node
            $ownerProductId = null;
            foreach ($products as $product) {
                if (in_array($node['id'], $product->getNodes())) {
                    $ownerProductId = $product->getId();
                    break;
                }
            }

            $grouped[$locationName]['nodes'][] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'productId' => $ownerProductId,
            ];
        }

        return $grouped;
    }
}
