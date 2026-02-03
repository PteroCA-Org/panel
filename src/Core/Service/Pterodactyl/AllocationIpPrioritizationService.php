<?php

namespace App\Core\Service\Pterodactyl;

use App\Core\Contract\Pterodactyl\AllocationIpPrioritizationServiceInterface;

readonly class AllocationIpPrioritizationService implements AllocationIpPrioritizationServiceInterface
{
    /**
     * Select the best allocation from a list based on IP prioritization
     *
     * Priority: public > private > wildcard > localhost
     *
     * @param array $allocations Array of allocations from Pterodactyl API
     * @return array|null The best allocation or null if none suitable
     */
    public function getBestAllocation(array $allocations): ?array
    {
        $categorizedAllocations = [
            'public' => [],
            'private' => [],
            'wildcard' => [],
            'localhost' => [],
        ];

        foreach ($allocations as $allocation) {
            if ($allocation['assigned']) {
                continue;
            }

            $category = $this->classifyIpAddress($allocation['ip']);
            $categorizedAllocations[$category][] = $allocation;
        }

        // Priority: public > private > wildcard > localhost
        foreach (['public', 'private', 'wildcard', 'localhost'] as $category) {
            if (!empty($categorizedAllocations[$category])) {
                return $categorizedAllocations[$category][0];
            }
        }

        return null;
    }

    /**
     * Get summary of available allocations for debugging and error reporting
     *
     * @param array $allocations Array of allocations from Pterodactyl API
     * @return array Summary with counts of assigned/unassigned by category
     */
    public function getAvailableAllocationsSummary(array $allocations): array
    {
        $summary = [
            'total' => count($allocations),
            'assigned' => 0,
            'unassigned' => 0,
            'by_category' => [
                'public' => ['total' => 0, 'unassigned' => 0],
                'private' => ['total' => 0, 'unassigned' => 0],
                'wildcard' => ['total' => 0, 'unassigned' => 0],
                'localhost' => ['total' => 0, 'unassigned' => 0],
                'link_local' => ['total' => 0, 'unassigned' => 0],
            ],
        ];

        foreach ($allocations as $allocation) {
            $category = $this->classifyIpAddress($allocation['ip']);
            $isAssigned = $allocation['assigned'];

            $summary['by_category'][$category]['total']++;

            if ($isAssigned) {
                $summary['assigned']++;
            } else {
                $summary['unassigned']++;
                $summary['by_category'][$category]['unassigned']++;
            }
        }

        return $summary;
    }

    /**
     * Classify IP address type for allocation prioritization
     *
     * @return string One of: 'localhost', 'wildcard', 'private', 'public', 'link_local'
     */
    public function classifyIpAddress(string $ip): string
    {
        // Localhost/loopback addresses - lowest priority
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return 'localhost';
        }

        // Wildcard addresses (bind to all interfaces)
        if ($ip === '0.0.0.0' || $ip === '::') {
            return 'wildcard';
        }

        // Private IPv4 ranges (RFC 1918)
        if (preg_match('/^10\./', $ip) ||                          // 10.0.0.0/8
            preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip) || // 172.16.0.0/12
            preg_match('/^192\.168\./', $ip)) {                    // 192.168.0.0/16
            return 'private';
        }

        // IPv6 link-local addresses (fe80::/10)
        if (preg_match('/^fe80:/i', $ip)) {
            return 'link_local';
        }

        // IPv6 unique local addresses (fc00::/7) - ULA
        if (preg_match('/^f[cd][0-9a-f]{2}:/i', $ip)) {
            return 'private';
        }

        // Everything else is considered public
        return 'public';
    }
}
