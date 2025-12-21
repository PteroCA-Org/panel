<?php

namespace App\Core\Widget\Dashboard;

use App\Core\Contract\UserInterface;
use App\Core\Contract\Widget\WidgetInterface;
use App\Core\Enum\WidgetContext;
use App\Core\Enum\WidgetPosition;
use App\Core\Service\Logs\LogService;
use InvalidArgumentException;

readonly class ActivityWidget implements WidgetInterface
{
    public function __construct(
        private LogService $logService
    ) {}

    public function getName(): string
    {
        return 'activity';
    }

    public function getDisplayName(): string
    {
        return 'Recent Activity';
    }

    public function getSupportedContexts(): array
    {
        return [WidgetContext::DASHBOARD];
    }

    public function getPosition(): WidgetPosition
    {
        return WidgetPosition::RIGHT;
    }

    public function getPriority(): int
    {
        return 80; // Below balance (100) and MOTD (90), show third
    }

    public function getTemplate(): string
    {
        return 'panel/dashboard/components/activity.html.twig';
    }

    public function getData(WidgetContext $context, array $contextData): array
    {
        /** @var UserInterface $user */
        $user = $contextData['user'] ?? throw new InvalidArgumentException('User required in context data');

        return [
            'logs' => $this->logService->getLogsByUser($user, 5),
            'demoMode' => $contextData['demoMode'] ?? false,
        ];
    }

    public function isVisible(WidgetContext $context, array $contextData): bool
    {
        return $context === WidgetContext::DASHBOARD;
    }

    public function getColumnSize(): int
    {
        return 12; // Full width within RIGHT position
    }
}
