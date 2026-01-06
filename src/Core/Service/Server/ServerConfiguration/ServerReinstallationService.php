<?php

namespace App\Core\Service\Server\ServerConfiguration;

use App\Core\Contract\UserInterface;
use App\Core\DTO\Pterodactyl\Collection;
use App\Core\DTO\Pterodactyl\Resource;
use App\Core\Entity\Server;
use App\Core\Event\Server\Configuration\ServerReinstallInitiatedEvent;
use App\Core\Event\Server\Configuration\ServerReinstallRequestedEvent;
use App\Core\Event\Server\Configuration\ServerReinstalledEvent;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Server\ServerEggEnvironmentService;
use Exception;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ServerReinstallationService extends AbstractServerConfiguration
{
    public function __construct(
        private readonly PterodactylApplicationService     $pterodactylApplicationService,
        private readonly ServerConfigurationStartupService $serverConfigurationStartupService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
        private readonly EventContextService $eventContextService,
        private readonly LoggerInterface $logger,
        private readonly ServerEggEnvironmentService $serverEggEnvironmentService,
    )
    {
        parent::__construct($this->pterodactylApplicationService);
    }

    /**
     * @throws Exception
     */
    public function reinstallServer(Server $server, UserInterface $user, ?int $selectedEgg): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $serverDetails = $this->getServerDetails($server, ['egg']);
        $currentEgg = $serverDetails['egg'];
        $eggChanged = false;

        $requestedEvent = new ServerReinstallRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $selectedEgg,
            $currentEgg,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Server reinstall was blocked';
            throw new Exception($reason);
        }

        if ($selectedEgg) {
            $this->validateEgg($server, $selectedEgg);

            $newEgg = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nestEggs()
                ->getEgg(
                    $server->getServerProduct()->getNest(),
                    $selectedEgg,
                    ['include' => 'variables']
                );

            if (!$newEgg->has('id')) {
                throw new Exception('Failed to fetch egg data from Pterodactyl');
            }

            try {
                $productEggConfiguration = json_decode(
                    $server->getServerProduct()->getEggsConfiguration(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $productEggConfiguration = [];
            }

            $slots = $this->extractSlotCount($server, $selectedEgg, $newEgg, $productEggConfiguration, $serverDetails);

            $environment = $this->serverEggEnvironmentService->buildEnvironmentVariables(
                $newEgg,
                $productEggConfiguration,
                $slots
            );

            $dockerImage = $productEggConfiguration[$selectedEgg]['options']['docker_image']['value']
                ?? $newEgg->get('docker_image');
            $startup = $productEggConfiguration[$selectedEgg]['options']['startup']['value']
                ?? $newEgg->get('startup');

            $startupPayload = [
                'startup' => $startup,
                'egg' => $selectedEgg,
                'environment' => $environment,
                'image' => $dockerImage,
                'skip_scripts' => false,
            ];

            $this->serverConfigurationStartupService->updateServerStartup($server, $startupPayload);
            $eggChanged = ($selectedEgg !== $currentEgg);
        }

        $initiatedEvent = new ServerReinstallInitiatedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $selectedEgg,
            $currentEgg,
            $eggChanged,
            $context
        );
        $this->eventDispatcher->dispatch($initiatedEvent);

        try {
            $this->pterodactylApplicationService
                ->getClientApi($user)
                ->servers()
                ->reinstallServer($server->getPterodactylServerIdentifier());
        } catch (Exception $e) {
            $this->logger->error('Pterodactyl API reinstall failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to reinstall server: ' . $e->getMessage());
        }

        $reinstalledEvent = new ServerReinstalledEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $selectedEgg,
            $currentEgg,
            $eggChanged,
            $context
        );
        $this->eventDispatcher->dispatch($reinstalledEvent);
    }

    /**
     * @throws Exception
     */
    private function validateEgg(Server $server, int $selectedEgg): void
    {
        if (!in_array($selectedEgg, $server->getServerProduct()->getEggs())) {
            throw new Exception('Invalid egg');
        }
    }

    /**
     * Extract current slot count from server environment if slot-based pricing is used.
     */
    private function extractSlotCount(
        Server $server,
        int $selectedEgg,
        Resource $newEgg,
        array $productEggConfiguration,
        array $serverDetails
    ): ?int
    {
        if (empty($productEggConfiguration[$selectedEgg]['variables'])) {
            return null;
        }

        foreach ($productEggConfiguration[$selectedEgg]['variables'] as $variableId => $variableConfig) {
            if (empty($variableConfig['slot_variable']) || $variableConfig['slot_variable'] !== 'on') {
                continue;
            }

            $variables = $newEgg->get('relationships')['variables'] ?? null;
            if (!$variables instanceof Collection) {
                continue;
            }

            foreach ($variables->toArray() as $eggVariable) {
                if ($eggVariable['id'] === $variableId) {
                    $envVarName = $eggVariable['env_variable'];
                    $currentValue = $serverDetails['container']['environment'][$envVarName] ?? null;

                    if ($currentValue !== null) {
                        return (int) $currentValue;
                    }
                }
            }
        }

        return null;
    }
}
