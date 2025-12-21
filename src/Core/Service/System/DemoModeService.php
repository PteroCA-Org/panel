<?php

namespace App\Core\Service\System;

class DemoModeService
{
    private bool $demoMode;

    public function __construct(
        private readonly EnvironmentConfigurationService $envService,
    ) {
        $demoModeValue = $this->envService->getEnvValue('/^DEMO_MODE=(.*)$/m');
        $this->demoMode = strtolower(trim($demoModeValue)) === 'true';
    }

    public function isDemoModeEnabled(): bool
    {
        return $this->demoMode;
    }

    public function getDemoModeMessage(): string
    {
        return 'pteroca.demo_mode.action_disabled';
    }
}
