<?php

namespace App\Core\Tab\Server;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\ServerPermissionEnum;

class BackupsTab implements ServerTabInterface
{
    public function getId(): string
    {
        return 'backups';
    }

    public function getLabel(): string
    {
        return 'pteroca.server.backups';
    }

    public function getPriority(): int
    {
        return 70;
    }

    public function isVisible(ServerTabContext $context): bool
    {
        return $context->getProductFeature('backups') > 0
            && $context->hasPermission(ServerPermissionEnum::BACKUP_READ->value);
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'panel/server/tabs/backups/backups.html.twig';
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
