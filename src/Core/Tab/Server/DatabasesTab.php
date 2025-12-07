<?php

namespace App\Core\Tab\Server;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\ServerPermissionEnum;

class DatabasesTab implements ServerTabInterface
{
    public function getId(): string
    {
        return 'databases';
    }

    public function getLabel(): string
    {
        return 'pteroca.server.databases';
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function isVisible(ServerTabContext $context): bool
    {
        return $context->getProductFeature('dbCount') > 0
            && $context->hasPermission(ServerPermissionEnum::DATABASE_READ->value);
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'panel/server/tabs/databases/databases.html.twig';
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
