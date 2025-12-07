<?php

namespace App\Core\Tab\Server;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\ServerPermissionEnum;

class StartupTab implements ServerTabInterface
{
    public function getId(): string
    {
        return 'startup';
    }

    public function getLabel(): string
    {
        return 'pteroca.server.startup';
    }

    public function getPriority(): int
    {
        return 90;
    }

    public function isVisible(ServerTabContext $context): bool
    {
        return $context->hasConfigurableStartup()
            && $context->hasPermission(ServerPermissionEnum::STARTUP_READ->value);
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'panel/server/tabs/startup/startup.html.twig';
    }

    public function getStylesheets(): array
    {
        return [];
    }

    public function getJavascripts(): array
    {
        return [];
    }

    public function requiresFullReload(): bool
    {
        return false;
    }
}
