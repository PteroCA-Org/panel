<?php

namespace App\Core\Tab\Server;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\ServerPermissionEnum;

class NetworkTab implements ServerTabInterface
{
    public function getId(): string
    {
        return 'network';
    }

    public function getLabel(): string
    {
        return 'pteroca.server.network';
    }

    public function getPriority(): int
    {
        return 60;
    }

    public function isVisible(ServerTabContext $context): bool
    {
        return $context->hasPermission(ServerPermissionEnum::ALLOCATION_READ->value);
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'panel/server/tabs/network/network.html.twig';
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
