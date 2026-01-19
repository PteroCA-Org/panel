<?php

namespace App\Core\Service\Pterodactyl;

use App\Core\Contract\ProductInterface;
use App\Core\Contract\Pterodactyl\AllocationIpPrioritizationServiceInterface;
use Exception;
use Psr\Log\LoggerInterface;

readonly class NodeSelectionService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private AllocationIpPrioritizationServiceInterface $allocationIpPrioritizationService,
        private LoggerInterface $logger,
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

        $currentPage = 1;
        $allAllocationsForSummary = [];

        do {
            $collection = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nodeAllocations()
                ->paginate($bestNode['id'], ['page' => $currentPage, 'per_page' => 100]);

            $allocations = $collection->toArray();
            $allAllocationsForSummary = array_merge($allAllocationsForSummary, $allocations);

            $bestAllocation = $this->allocationIpPrioritizationService->getBestAllocation($allocations);

            if ($bestAllocation) {
                return $bestAllocation['id'];
            }

            $meta = $collection->getMeta();
            $pagination = $meta['pagination'] ?? [];
            $totalPages = $pagination['total_pages'] ?? 1;
            $hasMorePages = $currentPage < $totalPages;

            $currentPage++;
        } while ($hasMorePages);

        $summary = $this->allocationIpPrioritizationService->getAvailableAllocationsSummary($allAllocationsForSummary);

        $this->logger->warning('No suitable allocation found', [
            'node_id' => $bestNode['id'],
            'node_name' => $bestNode['name'] ?? 'unknown',
            'allocation_summary' => $summary,
        ]);

        if ($summary['total'] === 0) {
            throw new Exception('No allocations configured on the selected node. Please add allocations to the node.');
        }

        if ($summary['unassigned'] === 0) {
            throw new Exception(sprintf(
                'No unassigned allocations available on the selected node. All %d allocation(s) are currently in use.',
                $summary['total']
            ));
        }

        $localhostOnly = $summary['unassigned'] === $summary['by_category']['localhost']['unassigned'];
        if ($localhostOnly) {
            throw new Exception(
                'Only localhost allocations are available on the selected node. ' .
                'For production use, please add public or private IP allocations to the node.'
            );
        }

        throw new Exception('No suitable allocation found on the selected node');
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
