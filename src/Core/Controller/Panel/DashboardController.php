<?php

namespace App\Core\Controller\Panel;

use App\Core\Controller\Panel\Setting\EmailSettingCrudController;
use App\Core\Controller\Panel\Setting\GeneralSettingCrudController;
use App\Core\Controller\Panel\Setting\PaymentSettingCrudController;
use App\Core\Controller\Panel\Setting\PterodactylSettingCrudController;
use App\Core\Controller\Panel\Setting\SecuritySettingCrudController;
use App\Core\Controller\Panel\Setting\ThemeSettingCrudController;
use App\Core\Entity\Panel\UserAccount;
use App\Core\Service\Menu\MenuBuilder;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingContextEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Enum\WidgetContext;
use App\Core\Event\Dashboard\DashboardAccessedEvent;
use App\Core\Event\Dashboard\DashboardDataLoadedEvent;
use App\Core\Event\Menu\MenuItemsCollectedEvent;
use App\Core\Event\Widget\WidgetsCollectedEvent;
use App\Core\Service\Widget\WidgetRegistry;
use App\Core\Widget\Dashboard\BalanceWidget;
use App\Core\Widget\Dashboard\ServersWidget;
use App\Core\Widget\Dashboard\MotdWidget;
use App\Core\Widget\Dashboard\ActivityWidget;
use App\Core\Widget\Dashboard\QuickActionsWidget;
use App\Core\Repository\ServerRepository;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Server\ServerService;
use App\Core\Service\SettingService;
use App\Core\Service\System\SystemVersionService;
use App\Core\Service\Template\TemplateManager;
use App\Core\Trait\EventContextTrait;
use App\Core\Trait\GetUserTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DashboardController extends AbstractDashboardController
{
    use GetUserTrait;
    use EventContextTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly SettingService $settingService,
        private readonly ServerRepository $serverRepository,
        private readonly LogService $logService,
        private readonly SystemVersionService $systemVersionService,
        private readonly TemplateManager $templateManager,
        private readonly ServerService $serverService,
        private readonly RequestStack $requestStack,
        private readonly BalanceWidget $balanceWidget,
        private readonly ServersWidget $serversWidget,
        private readonly MotdWidget $motdWidget,
        private readonly ActivityWidget $activityWidget,
        private readonly QuickActionsWidget $quickActionsWidget,
        private readonly MenuBuilder $menuBuilder,
        private readonly WidgetRegistry $widgetRegistry,
    ) {}

    #[Route('/panel', name: 'panel')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(PermissionEnum::ACCESS_DASHBOARD->value);

        $user = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();

        $this->dispatchDataEvent(
            DashboardAccessedEvent::class,
            $request,
            [$user->getRoles()]
        );

        // === Widget Registry System ===
        // Register builtin widgets (injected as dependencies)
        $this->registerBuiltinWidgets($this->widgetRegistry);

        // Dispatch event for plugins to register custom widgets
        $contextData = ['user' => $user];
        $widgetEvent = new WidgetsCollectedEvent(
            $this->widgetRegistry,
            WidgetContext::DASHBOARD,
            $contextData
        );
        $this->dispatchEvent($widgetEvent);
        // === End Widget Registry System ===

        $pterodactylPanelUrl = $this->settingService->getSetting(SettingEnum::PTERODACTYL_PANEL_URL->value);
        $servers = $this->serverService->getServersWithAccess($user);
        $logs = $this->logService->getLogsByUser($user, 5);
        $motdEnabled = $this->settingService->getSetting(SettingEnum::CUSTOMER_MOTD_ENABLED->value);
        $motdTitle = $this->settingService->getSetting(SettingEnum::CUSTOMER_MOTD_TITLE->value);
        $motdMessage = $this->settingService->getSetting(SettingEnum::CUSTOMER_MOTD_MESSAGE->value);

        $this->dispatchDataEvent(
            DashboardDataLoadedEvent::class,
            $request,
            [count($servers), count($logs), (bool)$motdEnabled]
        );

        $viewData = [
            'widgetRegistry' => $this->widgetRegistry,
            'widgetContext' => WidgetContext::DASHBOARD,
            'contextData' => $contextData,
            'servers' => $servers,
            'user' => $user,
            'logs' => $logs,
            'motdEnabled' => $motdEnabled,
            'motdTitle' => $motdTitle,
            'motdMessage' => $motdMessage,
            'pterodactylPanelUrl' => $pterodactylPanelUrl,
        ];

        $viewEvent = $this->prepareViewDataEvent(ViewNameEnum::DASHBOARD, $viewData, $request);

        return $this->render('panel/dashboard/dashboard.html.twig', $viewEvent->getViewData());
    }

    /**
     * Register builtin (core) dashboard widgets.
     *
     * @param WidgetRegistry $registry
     * @return void
     */
    private function registerBuiltinWidgets(WidgetRegistry $registry): void
    {
        $registry->registerWidget($this->quickActionsWidget);
        $registry->registerWidget($this->balanceWidget);
        $registry->registerWidget($this->serversWidget);
        $registry->registerWidget($this->motdWidget);
        $registry->registerWidget($this->activityWidget);
    }

    public function configureDashboard(): Dashboard
    {
        $title = $this->settingService->getSetting(SettingEnum::SITE_TITLE->value);
        $logoUrl = $this->settingService->getSetting(SettingEnum::LOGO->value);
        if (!empty($logoUrl)) {
            $logoUrl = sprintf('/uploads/settings/%s', $logoUrl);
        } else {
            $logoUrl = '/assets/img/logo/logo.png';
        }
        $logo = sprintf('<img src="%s" alt="%s" style="max-width: 90%%;">', $logoUrl, $title);

        $currentTemplateOptions = $this->templateManager->getCurrentTemplateOptions();
        $disableDarkMode = !$currentTemplateOptions->isSupportDarkMode()
            || $this->settingService->getSetting(SettingEnum::THEME_DISABLE_DARK_MODE->value);
        $defaultMode = $disableDarkMode
            ? ColorScheme::LIGHT
            : $this->settingService->getSetting(SettingEnum::THEME_DEFAULT_MODE->value);

        return Dashboard::new()
            ->setTitle($logo)
            ->setDefaultColorScheme($defaultMode)
            ->disableDarkMode($disableDarkMode)
            ;
    }

    public function configureMenuItems(): iterable
    {
        // Build menu structure using MenuBuilder service
        $menuItems = $this->menuBuilder->buildMenuStructure(
            fn(SettingContextEnum $context) => $this->generateSettingsUrl($context)
        );

        // Add conditional footer items (phpMyAdmin, if enabled)
        if ($this->settingService->getSetting(SettingEnum::SHOW_PHPMYADMIN_URL->value)) {
            $menuItems['footer'][] = MenuItem::linkToUrl(
                $this->translator->trans('pteroca.crud.menu.phpmyadmin'),
                'fa fa-database',
                $this->settingService->getSetting(SettingEnum::PHPMYADMIN_URL->value),
            )->setLinkTarget('_blank');
        }

        $menuItems['footer'][] = MenuItem::linkToLogout(
            $this->translator->trans('pteroca.crud.menu.logout'),
            'fa fa-sign-out-alt'
        );

        // Dispatch event for plugins to add/modify menu items
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $event = new MenuItemsCollectedEvent(
            $this->getUser(),
            $menuItems,
            $context
        );

        $event = $this->dispatchEvent($event);
        $menuItems = $event->getMenuItems();

        // Yield all items in order: main -> admin -> footer
        foreach ($menuItems as $items) {
            foreach ($items as $item) {
                yield $item;
            }
        }
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile(sprintf(
                '/assets/theme/%s/css/panel.css?v=%s',
                $this->templateManager->getCurrentTemplate(),
                $this->systemVersionService->getCurrentVersion(),
            ));
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $userMenu = parent::configureUserMenu($user);
        $menuItems = $userMenu->getAsDto()->getItems();

        $logoutAction = end($menuItems);
        $logoutAction->getAsDto()->setIcon('fa-sign-out-alt');

        $userMenu->addMenuItems([
            MenuItem::linkToCrud(
                $this->translator->trans('pteroca.dashboard.account_settings'),
                'fa fa-user-cog',
                UserAccount::class
            ),
        ]);

        return $userMenu;
    }

    private function generateSettingsUrl(SettingContextEnum $context): string
    {
        $crudFqcn = match ($context) {
            SettingContextEnum::THEME => ThemeSettingCrudController::class,
            SettingContextEnum::SECURITY => SecuritySettingCrudController::class,
            SettingContextEnum::PAYMENT => PaymentSettingCrudController::class,
            SettingContextEnum::EMAIL => EmailSettingCrudController::class,
            SettingContextEnum::PTERODACTYL => PterodactylSettingCrudController::class,
            default => GeneralSettingCrudController::class,
        };

        return $this->generateUrl('panel', [
            'crudAction' => 'index',
            'crudControllerFqcn' => $crudFqcn,
        ]);
    }
}
