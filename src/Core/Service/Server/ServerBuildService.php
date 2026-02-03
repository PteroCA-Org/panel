<?php

namespace App\Core\Service\Server;

use Exception;
use JsonException;
use App\Core\Entity\ServerProduct;
use App\Core\Contract\UserInterface;
use App\Core\DTO\Pterodactyl\Resource;
use App\Core\Contract\ProductInterface;
use App\Core\Service\Pterodactyl\NodeSelectionService;
use App\Core\DTO\Pterodactyl\Application\PterodactylServer;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;

readonly class ServerBuildService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private NodeSelectionService          $nodeSelectionService,
        private ServerEggEnvironmentService   $serverEggEnvironmentService,
    ) {}

    /**
     * @throws Exception
     */
    public function prepareServerBuild(
        ProductInterface $product,
        UserInterface $user,
        int $eggId,
        string $serverName = '',
        ?int $slots = null,
        ?int $selectedNodeId = null,
    ): array
    {
        $selectedEgg = $this->getSelectedEgg($eggId, $product);
        if (!$selectedEgg->has('id')) {
            throw new Exception('Egg not found');
        }

        try {
            $productEggConfiguration = json_decode(
                $product->getEggsConfiguration(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $productEggConfiguration = [];
        }

        $bestAllocationId = $this->nodeSelectionService->getBestAllocationId($product, $selectedNodeId);
        $dockerImage = $productEggConfiguration[$eggId]['options']['docker_image']['value']
            ?? $selectedEgg->get('docker_image');
        $startup = $productEggConfiguration[$eggId]['options']['startup']['value']
            ?? $selectedEgg->get('startup');
        $threads = trim($product->getThreads() ?? '') !== '' ? $product->getThreads() : null;

        return [
            'name' => $serverName ?: $product->getName(),
            'user' => $user->getPterodactylUserId(),
            'egg' => $selectedEgg->get('id'),
            'docker_image' => $dockerImage,
            'startup' => $startup,
            'environment' => $this->serverEggEnvironmentService->buildEnvironmentVariables($selectedEgg, $productEggConfiguration, $slots),
            'limits' => [
                'memory' => $product->getMemory(),
                'swap' => $product->getSwap(),
                'disk' => $product->getDiskSpace(),
                'io' => $product->getIo(),
                'cpu' => $product->getCpu(),
                'threads' => $threads,
            ],
            'feature_limits' => [
                'databases' => $product->getDbCount(),
                'backups' => $product->getBackups(),
                'allocations' => $product->getPorts(),
            ],
            'allocation' => [
                'default' => $bestAllocationId,
            ],
        ];
    }

    public function prepareUpdateServerBuild(ServerProduct $product, PterodactylServer $pterodactylServer): array
    {
        return [
            'allocation' => $pterodactylServer->get('allocation'),
            'memory' => $product->getMemory(),
            'swap' => $product->getSwap(),
            'io' => $product->getIo(),
            'cpu' => $product->getCpu(),
            'threads' => $product->getThreads(),
            'disk' => $product->getDiskSpace(),
            'feature_limits' => [
                'databases' => $product->getDbCount(),
                'backups' => $product->getBackups(),
                'allocations' => $product->getPorts(),
            ]
        ];
    }

    /**
     * @throws Exception
     */
    public function prepareUpdateServerStartup(ServerProduct $product, PterodactylServer $pterodactylServer): array
    {
        $eggId = $pterodactylServer->get('egg');
        $selectedEgg = $this->getSelectedEgg($eggId, $product);
        if (!$selectedEgg->has('id')) {
            throw new Exception('Egg not found in nest');
        }

        try {
            $productEggConfiguration = json_decode(
                $product->getEggsConfiguration(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $productEggConfiguration = [];
        }

        $dockerImage = $productEggConfiguration[$eggId]['options']['docker_image']['value']
            ?? $selectedEgg->get('docker_image');
        $startup = $productEggConfiguration[$eggId]['options']['startup']['value']
            ?? $selectedEgg->get('startup');

        return [
            'startup' => $startup,
            'environment' => $this->serverEggEnvironmentService->buildEnvironmentVariables($selectedEgg, $productEggConfiguration),
            'egg' => $eggId,
            'image' => $dockerImage,
            'skip_scripts' => false,
        ];
    }

    private function getSelectedEgg(int $eggId, ProductInterface $product): Resource
    {
        return $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nestEggs()
            ->getEgg($product->getNest(), $eggId, ['include' => 'variables']);
    }
}
