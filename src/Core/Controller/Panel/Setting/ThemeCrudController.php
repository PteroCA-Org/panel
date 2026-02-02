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
use App\Core\Exception\Theme\InvalidTemplateManifestException;
use App\Core\Exception\Theme\InvalidThemeStructureException;
use App\Core\Exception\Theme\ThemeAlreadyExistsException;
use App\Core\Exception\Theme\ThemeSecurityException;
use App\Core\Form\ThemeUploadFormType;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\SettingService;
use App\Core\Service\Template\TemplateService;
use App\Core\Service\Theme\ThemeUploadService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
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
        private readonly ThemeUploadService $themeUploadService,
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
            'uploadTheme' => PermissionEnum::UPLOAD_THEME->value,
            'processUpload' => PermissionEnum::UPLOAD_THEME->value,
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

        // Check all contexts where this theme is active
        $activeContexts = [];
        $contextSettings = [
            'panel' => SettingEnum::PANEL_THEME->value,
            'landing' => SettingEnum::LANDING_THEME->value,
            'email' => SettingEnum::EMAIL_THEME->value,
        ];

        foreach ($contextSettings as $contextName => $settingName) {
            $defaultTheme = $this->settingService->getSetting($settingName);
            if ($defaultTheme === $themeName) {
                $activeContexts[] = $contextName;
            }
        }

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
            'active_contexts' => $activeContexts,
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

    #[Route('/admin/theme/delete', name: 'admin_theme_delete', methods: ['POST'])]
    public function deleteTheme(AdminContext $context): RedirectResponse
    {
        $request = $context->getRequest();
        $themeName = $request->request->get('themeName');
        $themeContext = $request->request->get('context', 'panel');

        // Validate context
        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        // Prevent deletion of system default theme
        if ($themeName === 'default') {
            $this->addFlash('danger', $this->translator->trans('pteroca.crud.theme.cannot_delete_system_default'));

            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('index')
                ->set('context', $themeContext)
                ->generateUrl());
        }

        // Validate theme exists
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

        // Check if theme is not the default theme in ANY context
        $contexts = [
            'panel' => SettingEnum::PANEL_THEME->value,
            'landing' => SettingEnum::LANDING_THEME->value,
            'email' => SettingEnum::EMAIL_THEME->value,
        ];

        foreach ($contexts as $contextName => $settingName) {
            $defaultTheme = $this->settingService->getSetting($settingName);
            if ($defaultTheme === $themeName) {
                $this->addFlash('danger', sprintf(
                    $this->translator->trans('pteroca.crud.theme.cannot_delete_active_theme'),
                    $contextName
                ));

                return $this->redirect($this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('index')
                    ->set('context', $themeContext)
                    ->generateUrl());
            }
        }

        try {
            // Get theme display name before deletion
            $themeMetadata = $this->templateService->getRawTemplateInfo($themeName);
            $displayName = $themeMetadata['name'] ?? $themeName;

            // Get theme paths
            $themePath = $this->getParameter('kernel.project_dir') . '/themes/' . $themeName;
            $assetsPath = $this->getParameter('kernel.project_dir') . '/public/assets/theme/' . $themeName;

            // Delete theme directory
            if (is_dir($themePath)) {
                $this->deleteDirectory($themePath);
            }

            // Delete assets directory if exists
            if (is_dir($assetsPath)) {
                $this->deleteDirectory($assetsPath);
            }

            // Log action
            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::THEME_DELETED,
                [
                    'theme' => $themeName,
                    'context' => $themeContext,
                ]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.theme.delete_success'),
                $displayName
            ));
        } catch (\Exception $e) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.delete_error'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
            ->set('context', $themeContext)
            ->generateUrl());
    }

    #[Route('/admin/theme/upload', name: 'admin_theme_upload')]
    public function uploadTheme(AdminContext $context): Response
    {
        $request = $context->getRequest();
        $form = $this->createForm(ThemeUploadFormType::class);

        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        $this->appendCrudTemplateContext('theme');

        // Generate back URL
        $backUrl = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
            ->set('context', 'panel')
            ->generateUrl();

        return $this->renderWithEvent(
            ViewNameEnum::THEME_UPLOAD,
            'panel/crud/theme/upload.html.twig',
            [
                'form' => $form->createView(),
                'page_title' => $this->translator->trans('pteroca.theme.upload.title'),
                'back_url' => $backUrl,
            ],
            $request
        );
    }

    #[Route('/admin/theme/upload/process', name: 'admin_theme_upload_process', methods: ['POST'])]
    public function processUpload(): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        $form = $this->createForm(ThemeUploadFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $this->translator->trans('pteroca.theme.upload.errors.invalid_form'));
            return $this->redirect($this->adminUrlGenerator->setRoute('admin_theme_upload')->generateUrl());
        }

        try {
            $file = $form->get('themeFile')->getData();
            $result = $this->themeUploadService->uploadTheme($file, true);

            // Success
            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::THEME_UPLOADED,
                [
                    'theme' => $result->manifest->name,
                    'version' => $result->manifest->version,
                ]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.theme.upload.success'),
                $result->manifest->name,
                $result->manifest->version
            ));

            // Show warnings as info messages if any (grouped by type)
            if ($result->hasWarnings()) {
                // Group warnings by type
                $groupedWarnings = [];
                foreach ($result->warnings as $warning) {
                    if (!isset($groupedWarnings[$warning->type])) {
                        $groupedWarnings[$warning->type] = [
                            'count' => 0,
                            'messages' => [],
                        ];
                    }
                    $groupedWarnings[$warning->type]['count']++;
                    if ($warning->message && !in_array($warning->message, $groupedWarnings[$warning->type]['messages'])) {
                        $groupedWarnings[$warning->type]['messages'][] = $warning->message;
                    }
                }

                // Create flash messages for each warning type
                foreach ($groupedWarnings as $type => $data) {
                    $warningMessage = $this->translator->trans('pteroca.theme.upload.warning.' . $type);

                    // Add count if more than one occurrence
                    if ($data['count'] > 1) {
                        $warningMessage .= sprintf(' (%d %s)',
                            $data['count'],
                            $this->translator->trans('pteroca.theme.upload.occurrences')
                        );
                    }

                    // Add unique messages if any
                    if (!empty($data['messages'])) {
                        $warningMessage .= ': ' . implode(', ', array_slice($data['messages'], 0, 3));
                        if (count($data['messages']) > 3) {
                            $warningMessage .= '...';
                        }
                    }

                    $this->addFlash('info', $warningMessage);
                }
            }

            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('index')
                ->generateUrl());

        } catch (ThemeAlreadyExistsException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (InvalidThemeStructureException $e) {
            $this->addFlash('danger', $this->translator->trans('pteroca.theme.upload.errors.invalid_structure'));
        } catch (InvalidTemplateManifestException $e) {
            $details = $e->getDetails();
            $errors = isset($details['errors']) ? implode(', ', $details['errors']) : $e->getMessage();
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.theme.upload.errors.invalid_manifest'),
                $errors
            ));
        } catch (ThemeSecurityException $e) {
            $this->addFlash('danger', $this->translator->trans('pteroca.theme.upload.errors.security_critical'));
        } catch (\Exception $e) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.theme.upload.errors.generic'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator->setRoute('admin_theme_upload')->generateUrl());
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
                'label' => sprintf(
                    $this->translator->trans('pteroca.crud.theme.set_as_default_in_context'),
                    ucfirst($themeContext)
                ),
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

        // Delete action (only if not active/default theme and not the system default theme)
        if (!$theme->isActive() && $theme->getName() !== 'default' && $this->getUser()?->hasPermission(PermissionEnum::DELETE_THEME)) {
            $actions[] = [
                'name' => 'delete',
                'label' => $this->translator->trans('pteroca.crud.theme.delete_theme'),
                'icon' => 'fa fa-trash',
                'url' => '#',
                'class' => 'danger',
                'data_attrs' => [
                    'bs-toggle' => 'modal',
                    'bs-target' => '#deleteThemeModal',
                    'theme-name' => $theme->getName(),
                    'theme-display-name' => $theme->getDisplayName(),
                    'theme-context' => $themeContext,
                ],
            ];
        }

        return $actions;
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }
}
