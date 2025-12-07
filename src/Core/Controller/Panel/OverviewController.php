<?php

namespace App\Core\Controller\Panel;

use App\Core\Enum\PermissionEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Enum\WidgetContext;
use App\Core\Controller\AbstractController;
use App\Core\Service\Widget\WidgetRegistry;
use App\Core\Widget\Admin\SystemInfoWidget;
use App\Core\Widget\Admin\RecentUsersWidget;
use App\Core\Widget\Admin\SystemStatsWidget;
use App\Core\Widget\Admin\PluginHealthWidget;
use App\Core\Widget\Admin\PluginStatusWidget;
use Symfony\Component\HttpFoundation\Response;
use App\Core\Widget\Admin\PluginSecurityWidget;
use App\Core\Widget\Admin\RecentPaymentsWidget;
use Symfony\Component\Routing\Annotation\Route;
use App\Core\Event\Widget\WidgetsCollectedEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Core\Event\Admin\AdminOverviewAccessedEvent;
use App\Core\Service\System\SystemInformationService;
use App\Core\Event\Admin\AdminOverviewDataLoadedEvent;
use App\Core\Service\Statistics\AdminStatisticsService;

class OverviewController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SystemStatsWidget $systemStatsWidget,
        private readonly RecentPaymentsWidget $recentPaymentsWidget,
        private readonly RecentUsersWidget $recentUsersWidget,
        private readonly SystemInfoWidget $systemInfoWidget,
        private readonly PluginStatusWidget $pluginStatusWidget,
        private readonly PluginSecurityWidget $pluginSecurityWidget,
        private readonly PluginHealthWidget $pluginHealthWidget,
    ) {}

    #[Route('/admin/overview', name: 'admin_overview')]
    public function index(
        SystemInformationService $systemInformationService,
        AdminStatisticsService $adminStatisticsService,
    ): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::ACCESS_ADMIN_OVERVIEW->value);

        $user = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();

        $this->dispatchDataEvent(
            AdminOverviewAccessedEvent::class,
            $request,
            [$user->getRoles()]
        );

        $systemInformation = $systemInformationService->getSystemInformation();
        $statistics = $adminStatisticsService->getAdminStatistics();

        // === Widget Registry System ===
        $widgetRegistry = new WidgetRegistry();

        // Register builtin admin widgets
        $this->registerAdminWidgets($widgetRegistry);

        // Dispatch event for plugins to register custom widgets
        $contextData = [
            'user' => $user,
            'statistics' => $statistics,
            'systemInformation' => $systemInformation,
        ];

        $widgetEvent = new WidgetsCollectedEvent(
            $widgetRegistry,
            WidgetContext::ADMIN_OVERVIEW,
            $contextData
        );
        $this->dispatchEvent($widgetEvent);
        // === End Widget Registry System ===

        $this->dispatchDataEvent(
            AdminOverviewDataLoadedEvent::class,
            $request,
            [
                $statistics['activeServers'],
                $statistics['usersRegisteredLastMonth'],
                $statistics['paymentsCreatedLastMonth'],
                $systemInformation['pterodactyl']['status'],
            ]
        );

        $viewData = [
            'widgetRegistry' => $widgetRegistry,
            'widgetContext' => WidgetContext::ADMIN_OVERVIEW,
            'contextData' => $contextData,
            'systemInformation' => $systemInformation,
            'statistics' => $statistics,
        ];

        return $this->renderWithEvent(
            ViewNameEnum::ADMIN_OVERVIEW,
            'panel/admin/overview.html.twig',
            $viewData,
            $request
        );
    }

    /**
     * Register builtin (core) admin overview widgets.
     *
     * @param WidgetRegistry $registry
     * @return void
     */
    private function registerAdminWidgets(WidgetRegistry $registry): void
    {
        $registry->registerWidget($this->systemStatsWidget);
        $registry->registerWidget($this->recentPaymentsWidget);
        $registry->registerWidget($this->recentUsersWidget);
        $registry->registerWidget($this->systemInfoWidget);

        $registry->registerWidget($this->pluginStatusWidget);
        $registry->registerWidget($this->pluginSecurityWidget);
        $registry->registerWidget($this->pluginHealthWidget);
    }
}