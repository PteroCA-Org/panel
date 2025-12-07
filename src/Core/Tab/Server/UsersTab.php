<?php

namespace App\Core\Tab\Server;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\ServerPermissionEnum;

class UsersTab implements ServerTabInterface
{
    public function getId(): string
    {
        return 'users';
    }

    public function getLabel(): string
    {
        return 'pteroca.server.users';
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function isVisible(ServerTabContext $context): bool
    {
        return $context->hasPermission(ServerPermissionEnum::USER_READ->value);
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'panel/server/tabs/users/users.html.twig';
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
