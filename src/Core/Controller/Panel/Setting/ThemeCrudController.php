<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Controller\Panel\AbstractPanelController;
use App\Core\DTO\ThemeDTO;
use App\Core\Entity\Setting;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\SettingService;
use App\Core\Service\Template\TemplateService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Theme CRUD Controller
 * Manages themes for different contexts (panel, landing, email)
 */
class ThemeCrudController extends AbstractPanelController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly TemplateService $templateService,
        private readonly SettingService $settingService,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LogService $logService,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        // Return dummy entity class to satisfy EasyAdmin
        // Actual theme data comes from filesystem via TemplateService
        return Setting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // Disable all default CRUD operations since we use custom actions
        return $crud
            ->overrideTemplate('crud/index', 'panel/crud/theme/index.html.twig');
    }

    protected function getPermissionMapping(): array
    {
        return [
            Action::INDEX  => PermissionEnum::ACCESS_THEMES->value,
            'viewDetails' => PermissionEnum::VIEW_THEME->value,
            'setDefaultTheme' => PermissionEnum::SET_DEFAULT_THEME->value,
        ];
    }

    public function index(AdminContext $context): Response
    {
        $request = $context->getRequest();
        $themeContext = $request->query->get('context', 'panel');

        // Validate context
        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        // Get active theme for this context
        $activeThemeSetting = match($themeContext) {
            'panel' => SettingEnum::PANEL_THEME->value,
            'landing' => SettingEnum::LANDING_THEME->value,
            'email' => SettingEnum::EMAIL_THEME->value,
        };
        $activeThemeName = $this->settingService->getSetting($activeThemeSetting);

        // Get all themes for this context
        $themes = $this->templateService->getThemesForContext($themeContext, $activeThemeName);

        // Prepare actions for each theme
        $themeActions = [];
        foreach ($themes as $theme) {
            $themeActions[$theme->getName()] = $this->getThemeActions($theme);
        }

        // Set appropriate page title based on context
        $pageTitle = match($themeContext) {
            'panel' => $this->translator->trans('pteroca.crud.theme.panel_themes'),
            'landing' => $this->translator->trans('pteroca.crud.theme.landing_themes'),
            'email' => $this->translator->trans('pteroca.crud.theme.email_themes'),
        };

        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        $this->appendCrudTemplateContext('theme');

        $viewData = [
            'themes' => $themes,
            'theme_actions' => $themeActions,
            'theme_context' => $themeContext,
            'page_title' => $pageTitle,
        ];

        return $this->renderWithEvent(
            ViewNameEnum::THEME_INDEX,
            'panel/crud/theme/index.html.twig',
            $viewData,
            $request
        );
    }

    public function viewDetails(AdminContext $context): Response
    {
        $request = $context->getRequest();
        $themeName = $request->query->get('themeName');
        $themeContext = $request->query->get('context', 'panel');

        // Validate context
        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        // Validate theme exists and supports this context
        if (!$this->templateService->themeSupportsContext($themeName, $themeContext)) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.theme_not_found'),
                $themeName
            ));

            return $this->redirectToRoute('admin', [
                'crudAction' => 'index',
                'crudControllerFqcn' => self::class,
                'context' => $themeContext,
            ]);
        }

        // Get active theme for this context
        $activeThemeSetting = match($themeContext) {
            'panel' => SettingEnum::PANEL_THEME->value,
            'landing' => SettingEnum::LANDING_THEME->value,
            'email' => SettingEnum::EMAIL_THEME->value,
        };
        $activeThemeName = $this->settingService->getSetting($activeThemeSetting);

        // Create ThemeDTO
        $theme = $this->templateService->getThemeDTO($themeName, $themeContext, $themeName === $activeThemeName);

        // Get formatted metadata
        $themeInfo = $this->templateService->getTemplateInfo($themeName);

        // Prepare actions for this theme
        $themeActions = $this->getThemeActions($theme);

        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        $this->appendCrudTemplateContext('theme');

        $viewData = [
            'theme' => $theme,
            'theme_info' => $themeInfo,
            'theme_actions' => $themeActions,
            'theme_context' => $themeContext,
        ];

        return $this->renderWithEvent(
            ViewNameEnum::THEME_DETAILS,
            'panel/crud/theme/detail.html.twig',
            $viewData,
            $request
        );
    }

    public function setDefaultTheme(AdminContext $context): RedirectResponse
    {
        $request = $context->getRequest();
        $themeName = $request->request->get('themeName');
        $themeContext = $request->request->get('context', 'panel');

        // Validate context
        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        // Validate theme exists and supports this context
        if (!$this->templateService->themeSupportsContext($themeName, $themeContext)) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.theme_not_found'),
                $themeName
            ));

            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('index')
                ->set('context', $themeContext)
                ->generateUrl());
        }

        try {
            // Determine setting name based on context
            $settingName = match($themeContext) {
                'panel' => SettingEnum::PANEL_THEME->value,
                'landing' => SettingEnum::LANDING_THEME->value,
                'email' => SettingEnum::EMAIL_THEME->value,
            };

            // Save setting
            $this->settingService->saveSettingInCache($settingName, $themeName);

            // Log action
            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::ENTITY_EDIT,
                [
                    'setting' => $settingName,
                    'value' => $themeName,
                    'context' => $themeContext,
                ]
            );

            // Get theme display name
            $themeMetadata = $this->templateService->getRawTemplateInfo($themeName);
            $displayName = $themeMetadata['name'] ?? $themeName;

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.theme.set_as_default_success'),
                $displayName,
                $themeContext
            ));
        } catch (\Exception $e) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.set_as_default_error'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
            ->set('context', $themeContext)
            ->generateUrl());
    }

    /**
     * Get visible actions for a theme
     */
    private function getThemeActions(ThemeDTO $theme): array
    {
        $actions = [];
        $themeContext = $theme->getContext();

        // Show Details action
        if ($this->getUser()?->hasPermission(PermissionEnum::VIEW_THEME)) {
            $actions[] = [
                'name' => 'details',
                'label' => $this->translator->trans('pteroca.crud.theme.show_details'),
                'icon' => 'fa fa-eye',
                'url' => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('viewDetails')
                    ->set('themeName', $theme->getName())
                    ->set('context', $themeContext)
                    ->generateUrl(),
                'class' => 'info',
            ];
        }

        // Set as Default action (only if not already active)
        if (!$theme->isActive() && $this->getUser()?->hasPermission(PermissionEnum::SET_DEFAULT_THEME)) {
            $actions[] = [
                'name' => 'set_default',
                'label' => $this->translator->trans('pteroca.crud.theme.set_as_default'),
                'icon' => 'fa fa-check',
                'url' => '#',
                'class' => 'success',
                'data_attrs' => [
                    'bs-toggle' => 'modal',
                    'bs-target' => '#setDefaultThemeModal',
                    'theme-name' => $theme->getName(),
                    'theme-display-name' => $theme->getDisplayName(),
                    'theme-context' => $themeContext,
                ],
            ];
        }

        return $actions;
    }
}
