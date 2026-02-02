<?php

namespace App\Core\Contract\Pterodactyl;

interface AllocationIpPrioritizationServiceInterface
{
    /**
     * Select the best allocation from a list based on IP prioritization
     *
     * Priority: public > private > wildcard > localhost
     *
     * @param array $allocations Array of allocations from Pterodactyl API
     * @return array|null The best allocation or null if none suitable
     */
    public function getBestAllocation(array $allocations): ?array;

    /**
     * Get summary of available allocations for debugging and error reporting
     *
     * @param array $allocations Array of allocations from Pterodactyl API
     * @return array Summary with counts of assigned/unassigned by category
     */
    public function getAvailableAllocationsSummary(array $allocations): array;

    /**
     * Classify IP address type for allocation prioritization
     *
     * @return string One of: 'localhost', 'wildcard', 'private', 'public', 'link_local'
     */
    public function classifyIpAddress(string $ip): string;
}
