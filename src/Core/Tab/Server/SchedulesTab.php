<?php

namespace App\Core\Tab\Server;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\ServerPermissionEnum;

class SchedulesTab implements ServerTabInterface
{
    public function getId(): string
    {
        return 'schedules';
    }

    public function getLabel(): string
    {
        return 'pteroca.server.schedules';
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function isVisible(ServerTabContext $context): bool
    {
        return $context->getProductFeature('schedules') > 0
            && $context->hasPermission(ServerPermissionEnum::SCHEDULE_READ->value);
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function getTemplate(): string
    {
        return 'panel/server/tabs/schedules/schedules.html.twig';
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
