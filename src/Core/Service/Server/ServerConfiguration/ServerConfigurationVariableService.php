<?php

namespace App\Core\Service\Server\ServerConfiguration;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Server;
use App\Core\Enum\PermissionEnum;
use App\Core\Event\Server\Configuration\ServerStartupVariableUpdateRequestedEvent;
use App\Core\Event\Server\Configuration\ServerStartupVariableUpdatedEvent;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ServerConfigurationVariableService extends AbstractServerConfiguration
{
    public function __construct(
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly ServerConfigurationStartupService $serverConfigurationStartupService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
        private readonly EventContextService $eventContextService,
    ) {
        parent::__construct($this->pterodactylApplicationService);
    }

    /**
     * @throws Exception
     */
    public function updateServerVariable(
        Server $server,
        UserInterface $user,
        string $variableKey,
        string $variableValue,
    ): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $serverDetails = $this->getServerDetails($server, ['variables']);
        $serverVariable = $this->getServerVariable($serverDetails, $variableKey);

        $oldValue = $serverVariable['server_value'] ?? '';

        $requestedEvent = new ServerStartupVariableUpdateRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $variableKey,
            $variableValue,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Server startup variable update was blocked';
            throw new Exception($reason);
        }

        $this->validateVariable($server, $serverDetails, $serverVariable, $variableValue, $user);

        $isReadOnlyVariable = $serverVariable['user_editable'] === false;

        if ($isReadOnlyVariable && $user->hasPermission(PermissionEnum::EDIT_SERVER)) {
            // Use Application API for read-only variables (admin only)
            $fullServerDetails = $this->getServerDetails($server, ['egg']);
            $payload = $this->serverConfigurationStartupService->getEnvironmentVariablePayload(
                $variableKey,
                $variableValue,
                $fullServerDetails
            );
            $this->serverConfigurationStartupService->updateServerStartup($server, $payload);
        } else {
            // Use Client API for user-editable variables
            $this->pterodactylApplicationService
                ->getClientApi($user)
                ->servers()
                ->updateServerStartupVariable($server->getPterodactylServerIdentifier(), $variableKey, $variableValue);
        }

        $updatedEvent = new ServerStartupVariableUpdatedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $variableKey,
            $variableValue,
            $oldValue,
            $context
        );
        $this->eventDispatcher->dispatch($updatedEvent);
    }

    /**
     * @throws Exception
     */
    private function getServerVariable(array $serverDetails, string $variableKey): array
    {
        $serverVariables = $serverDetails['relationships']['variables'];
        $foundVariable = current(array_filter($serverVariables, function ($variable) use ($variableKey) {
            return $variable['env_variable'] === $variableKey;
        }));

        if (empty($foundVariable)) {
            throw new Exception('Variable not found');
        }

        return $foundVariable;
    }

    private function isVariableEditableForUser(Server $server, array $serverDetails, array $serverVariable, UserInterface $user): bool
    {
        if ($user->hasPermission(PermissionEnum::EDIT_SERVER)) {
            return true;
        }

        if ($serverVariable['user_editable'] === false) {
            return false;
        }

        $productEggConfiguration = json_decode($server->getServerProduct()->getEggsConfiguration() ?? '');
        if (empty($productEggConfiguration)) {
            return false;
        }

        $currentServerEgg = $serverDetails['egg'] ?? null;
        $variableProductEggConfiguration = $productEggConfiguration->$currentServerEgg
            ?->variables
            ?->{$serverVariable['id']} ?? null;
        if (empty($variableProductEggConfiguration)) {
            return false;
        }

        return $variableProductEggConfiguration->user_editable ?? false;
    }

    /**
     * @throws Exception
     */
    private function validateVariable(Server $server, array $serverDetails, array $serverVariable, string $variableValue, UserInterface $user): void
    {
        if (!$this->isVariableEditableForUser($server, $serverDetails, $serverVariable, $user)) {
            throw new Exception('Variable is not editable for user');
        }

        $variableValueRules = $serverVariable['rules'];
        $rules = explode('|', $variableValueRules);
        
        if (in_array('boolean', $rules)) {
            if (!in_array($variableValue, ['0', '1', 'true', 'false', ''])) {
                throw new Exception('Variable value is invalid');
            }
            return;
        }

        $ruleMap = [
            'required' => Assert\NotBlank::class,
            'string' => Assert\Type::class,
            'numeric' => Assert\Type::class,
            'email' => Assert\Email::class,
            'url' => Assert\Url::class,
            'ip' => Assert\Ip::class,
            'regex' => Assert\Regex::class,
            'length' => Assert\Length::class,
            'range' => Assert\Range::class,
        ];

        $constraints = [];

        foreach ($rules as $rule) {
            if (str_contains($rule, ':')) {
                $partedRules = explode(':', $rule);
                $rule = $partedRules[0];
            }

            if (isset($ruleMap[$rule])) {
                $constraints[] = match ($rule) {
                    'string', 'numeric' => new $ruleMap[$rule](['type' => $rule]),
                    'regex' => new $ruleMap[$rule](['pattern' => $partedRules[1] ?? '']),
                    default => new $ruleMap[$rule]([]),
                };
            }
        }

        if (!empty($constraints)) {
            $validator = Validation::createValidator();
            $violations = $validator->validate($variableValue, $constraints);

            if (count($violations) > 0) {
                throw new Exception('Variable value is invalid');
            }
        }
    }
}
