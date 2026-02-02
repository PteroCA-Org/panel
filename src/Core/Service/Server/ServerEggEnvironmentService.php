<?php

namespace App\Core\Service\Server;

use App\Core\DTO\Pterodactyl\Collection;
use App\Core\DTO\Pterodactyl\Resource;

readonly class ServerEggEnvironmentService
{
    /**
     * Build environment variables for a given egg.
     *
     * @param Resource $egg The egg resource from Pterodactyl API (must include 'variables' relationship)
     * @param array $productEggConfiguration Product-specific egg configuration (from ServerProduct.eggsConfiguration)
     * @param int|null $slots Optional slots count for slot-based pricing (overrides slot variables)
     * @return array Environment variables as key-value pairs
     */
    public function buildEnvironmentVariables(
        Resource $egg,
        array $productEggConfiguration,
        ?int $slots = null
    ): array
    {
        $environmentVariables = [];
        $eggId = $egg->get('id');

        if (!$egg->has('relationships')) {
            return $environmentVariables;
        }

        $variables = $egg->get('relationships')['variables'] ?? null;
        if (!$variables instanceof Collection) {
            return $environmentVariables;
        }

        foreach ($variables->toArray() as $variable) {
            $variableFromProduct = $productEggConfiguration[$eggId]['variables'][$variable['id']] ?? null;

            // Priority: product config > default value from egg
            $valueToSet = $variableFromProduct['value'] ?? $variable['default_value'] ?? null;

            // Special handling: Override with slot count if this is a slot variable
            if ($slots !== null && !empty($variableFromProduct['slot_variable']) && $variableFromProduct['slot_variable'] === 'on') {
                $valueToSet = $slots;
            }

            $environmentVariables[$variable['env_variable']] = $valueToSet;
        }

        return $environmentVariables;
    }
}
