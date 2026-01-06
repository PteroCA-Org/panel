<?php

namespace App\Core\Service\Pterodactyl;

use App\Core\Contract\ProductInterface;
use App\Core\Contract\Pterodactyl\AllocationIpPrioritizationServiceInterface;
use Exception;

readonly class NodeSelectionService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private AllocationIpPrioritizationServiceInterface $allocationIpPrioritizationService,
    ) {}

    /**
     * @throws Exception
     */
    public function getBestAllocationId(ProductInterface $product, ?int $preferredNodeId = null): int
    {
        if ($preferredNodeId !== null) {
            return $this->getAllocationForNode($preferredNodeId, $product);
        }

        $bestNode = null;
        $bestNodeFreeMemory = 0;
        $bestNodeFreeDisk = 0;

        foreach ($product->getNodes() as $nodeId) {
            $node = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nodes()
                ->getNode($nodeId);

            $freeMemory = $node['memory'] - $node['allocated_resources']['memory'];
            $freeDisk = $node['disk'] - $node['allocated_resources']['disk'];

            if ($freeMemory >= $product->getMemory() && $freeDisk >= $product->getDiskSpace()) {
                if ($freeMemory > $bestNodeFreeMemory || ($freeMemory == $bestNodeFreeMemory && $freeDisk > $bestNodeFreeDisk)) {
                    $bestNode = $node;
                    $bestNodeFreeMemory = $freeMemory;
                    $bestNodeFreeDisk = $freeDisk;
                }
            }
        }

        if (!$bestNode) {
            throw new Exception('No suitable node found with enough resources');
        }

        $allocations = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nodeAllocations()
            ->all($bestNode['id'])
            ->toArray();

        $bestAllocation = $this->allocationIpPrioritizationService->getBestAllocation($allocations);

        if (!$bestAllocation) {
            throw new Exception('No suitable allocation found on the best node (only localhost addresses available)');
        }

        return $bestAllocation['id'];
    }

    private function getAllocationForNode(int $nodeId, ProductInterface $product): int
    {
        $node = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nodes()
            ->getNode($nodeId);

        $freeMemory = $node['memory'] - $node['allocated_resources']['memory'];
        $freeDisk = $node['disk'] - $node['allocated_resources']['disk'];

        if ($freeMemory < $product->getMemory() || $freeDisk < $product->getDiskSpace()) {
            throw new Exception('Selected node does not have enough resources');
        }

        $allocations = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nodeAllocations()
            ->all($nodeId)
            ->toArray();

        $bestAllocation = $this->allocationIpPrioritizationService->getBestAllocation($allocations);

        if (!$bestAllocation) {
            throw new Exception('No suitable allocation found on the selected node');
        }

        return $bestAllocation['id'];
    }
}
