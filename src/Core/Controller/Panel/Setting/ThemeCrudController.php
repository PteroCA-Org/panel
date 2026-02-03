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
use App\Core\Event\Theme\ThemeCopiedEvent;
use App\Core\Event\Theme\ThemeCopyFailedEvent;
use App\Core\Event\Theme\ThemeCopyRequestedEvent;
use App\Core\Event\Theme\ThemeDefaultChangedEvent;
use App\Core\Event\Theme\ThemeDefaultChangeFailedEvent;
use App\Core\Event\Theme\ThemeDefaultChangeRequestedEvent;
use App\Core\Event\Theme\ThemeDeletedEvent;
use App\Core\Event\Theme\ThemeDeletingEvent;
use App\Core\Event\Theme\ThemeDeletionFailedEvent;
use App\Core\Event\Theme\ThemeDetailsDataLoadedEvent;
use App\Core\Event\Theme\ThemeDetailsPageAccessedEvent;
use App\Core\Event\Theme\ThemeExportedEvent;
use App\Core\Event\Theme\ThemeExportFailedEvent;
use App\Core\Event\Theme\ThemeExportRequestedEvent;
use App\Core\Event\Theme\ThemeIndexDataLoadedEvent;
use App\Core\Event\Theme\ThemeIndexPageAccessedEvent;
use App\Core\Event\Theme\ThemeUploadedEvent;
use App\Core\Event\Theme\ThemeUploadFailedEvent;
use App\Core\Event\Theme\ThemeUploadPageAccessedEvent;
use App\Core\Event\Theme\ThemeUploadRequestedEvent;
use App\Core\Exception\Theme\InvalidTemplateManifestException;
use App\Core\Exception\Theme\InvalidThemeStructureException;
use App\Core\Exception\Theme\ThemeAlreadyExistsException;
use App\Core\Exception\Theme\ThemeSecurityException;
use App\Core\Form\ThemeUploadFormType;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\SettingService;
use App\Core\Service\Template\TemplateService;
use App\Core\Service\Template\ThemeCopyService;
use App\Core\Service\Template\ThemeExportService;
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
        private readonly ThemeCopyService $themeCopyService,
        private readonly ThemeExportService $themeExportService,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Setting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->overrideTemplate('crud/index', 'panel/crud/theme/index.html.twig')
            ->setSearchFields(null);
    }

    protected function getPermissionMapping(): array
    {
        return [
            Action::INDEX  => PermissionEnum::ACCESS_THEMES->value,
            'viewDetails' => PermissionEnum::VIEW_THEME->value,
            'setDefaultTheme' => PermissionEnum::SET_DEFAULT_THEME->value,
            'uploadTheme' => PermissionEnum::UPLOAD_THEME->value,
            'processUpload' => PermissionEnum::UPLOAD_THEME->value,
            'copyTheme' => PermissionEnum::COPY_THEME->value,
            'exportTheme' => PermissionEnum::EXPORT_THEME->value,
            'deleteTheme' => PermissionEnum::DELETE_THEME->value,
        ];
    }

    public function index(AdminContext $context): Response
    {
        $request = $context->getRequest();

        // Backward compatibility: Accept but ignore legacy context parameter
        $legacyContext = $request->query->get('context');

        $this->dispatchDataEvent(
            ThemeIndexPageAccessedEvent::class,
            $request,
            [null]
        );

        $panelTheme = $this->settingService->getSetting(SettingEnum::PANEL_THEME->value);
        $landingTheme = $this->settingService->getSetting(SettingEnum::LANDING_THEME->value);
        $emailTheme = $this->settingService->getSetting(SettingEnum::EMAIL_THEME->value);

        $themes = $this->templateService->getAllThemesWithActiveContexts(
            $panelTheme,
            $landingTheme,
            $emailTheme
        );

        $this->dispatchDataEvent(
            ThemeIndexDataLoadedEvent::class,
            $request,
            [$themes, count($themes), $panelTheme, $landingTheme, $emailTheme, null]
        );

        $themeActions = [];
        foreach ($themes as $theme) {
            $themeActions[$theme->getName()] = $this->getThemeActions($theme);
        }

        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        $this->appendCrudTemplateContext('theme');

        $viewData = [
            'themes' => $themes,
            'theme_actions' => $themeActions,
            'page_title' => $this->translator->trans('pteroca.crud.menu.manage_themes'),
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

        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        $this->dispatchDataEvent(
            ThemeDetailsPageAccessedEvent::class,
            $request,
            [$themeName, $themeContext]
        );

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

        $activeThemeSetting = match($themeContext) {
            'panel' => SettingEnum::PANEL_THEME->value,
            'landing' => SettingEnum::LANDING_THEME->value,
            'email' => SettingEnum::EMAIL_THEME->value,
        };
        $activeThemeName = $this->settingService->getSetting($activeThemeSetting);

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

        $theme = $this->templateService->getThemeDTO($themeName, $themeContext, $themeName === $activeThemeName);
        $themeInfo = $this->templateService->getTemplateInfo($themeName);
        $themeActions = $this->getThemeActions($theme);

        $this->dispatchDataEvent(
            ThemeDetailsDataLoadedEvent::class,
            $request,
            [$themeName, $themeContext, $theme, $themeInfo, $activeContexts]
        );

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

        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

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
            $settingName = match($themeContext) {
                'panel' => SettingEnum::PANEL_THEME->value,
                'landing' => SettingEnum::LANDING_THEME->value,
                'email' => SettingEnum::EMAIL_THEME->value,
            };

            $this->dispatchDataEvent(
                ThemeDefaultChangeRequestedEvent::class,
                $request,
                [$themeName, $themeContext, $settingName]
            );

            $this->settingService->saveSettingInCache($settingName, $themeName);

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::ENTITY_EDIT,
                [
                    'setting' => $settingName,
                    'value' => $themeName,
                    'context' => $themeContext,
                ]
            );

            $themeMetadata = $this->templateService->getRawTemplateInfo($themeName);
            $displayName = $themeMetadata['name'] ?? $themeName;

            $this->dispatchDataEvent(
                ThemeDefaultChangedEvent::class,
                $request,
                [$themeName, $displayName, $themeContext, $settingName]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.theme.set_as_default_success'),
                $displayName,
                $themeContext
            ));
        } catch (\Exception $e) {
            $this->dispatchDataEvent(
                ThemeDefaultChangeFailedEvent::class,
                $request,
                [$themeName, $themeContext, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.set_as_default_error'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
            ->generateUrl());
    }

    #[Route('/admin/theme/delete', name: 'admin_theme_delete', methods: ['POST'])]
    public function deleteTheme(AdminContext $context): RedirectResponse
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::DELETE_THEME)) {
            throw $this->createAccessDeniedException('You do not have permission to delete themes.');
        }

        $request = $context->getRequest();
        $themeName = $request->request->get('themeName');
        $themeContext = $request->request->get('context', 'panel');

        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        if ($themeName === 'default') {
            $this->addFlash('danger', $this->translator->trans('pteroca.crud.theme.cannot_delete_system_default'));

            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('index')
                ->set('context', $themeContext)
                ->generateUrl());
        }

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
            $themeMetadata = $this->templateService->getRawTemplateInfo($themeName);
            $displayName = $themeMetadata['name'] ?? $themeName;

            $event = $this->dispatchDataEvent(
                ThemeDeletingEvent::class,
                $request,
                [$themeName, $displayName, $themeContext]
            );

            if ($event->isPropagationStopped()) {
                $this->addFlash('warning', $this->translator->trans('pteroca.crud.theme.deletion_prevented'));

                return $this->redirect($this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('index')
                    ->set('context', $themeContext)
                    ->generateUrl());
            }

            $themePath = $this->getParameter('kernel.project_dir') . '/themes/' . $themeName;
            $assetsPath = $this->getParameter('kernel.project_dir') . '/public/assets/theme/' . $themeName;

            if (is_dir($themePath)) {
                $this->deleteDirectory($themePath);
            }

            if (is_dir($assetsPath)) {
                $this->deleteDirectory($assetsPath);
            }

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::THEME_DELETED,
                [
                    'theme' => $themeName,
                    'context' => $themeContext,
                ]
            );

            $this->dispatchDataEvent(
                ThemeDeletedEvent::class,
                $request,
                [$themeName, $displayName, $themeContext]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.theme.delete_success'),
                $displayName
            ));
        } catch (\Exception $e) {
            $this->dispatchDataEvent(
                ThemeDeletionFailedEvent::class,
                $request,
                [$themeName, $themeContext, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.delete_error'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
            ->generateUrl());
    }

    #[Route('/admin/theme/copy', name: 'admin_theme_copy', methods: ['POST'])]
    public function copyTheme(AdminContext $context): RedirectResponse
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::COPY_THEME)) {
            throw $this->createAccessDeniedException('You do not have permission to copy themes.');
        }

        $request = $context->getRequest();
        $sourceThemeName = $request->request->get('sourceThemeName');
        $newThemeName = trim($request->request->get('newThemeName'));
        $themeContext = $request->request->get('context', 'panel');

        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

        $newThemeName = strtolower($newThemeName);
        $newThemeName = preg_replace('/[^a-z0-9\-_]/', '', $newThemeName);

        if (!$this->templateService->themeSupportsContext($sourceThemeName, $themeContext)) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.theme_not_found'),
                $sourceThemeName
            ));
            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('index')
                ->set('context', $themeContext)
                ->generateUrl());
        }

        try {
            $validationErrors = $this->themeCopyService->validateThemeName($newThemeName);
            if (!empty($validationErrors)) {
                $this->addFlash('danger', implode(' ', $validationErrors));
                return $this->redirect($this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('index')
                    ->set('context', $themeContext)
                    ->generateUrl());
            }

            $sourceMetadata = $this->templateService->getRawTemplateInfo($sourceThemeName);
            $sourceDisplayName = $sourceMetadata['name'] ?? $sourceThemeName;

            $this->dispatchDataEvent(
                ThemeCopyRequestedEvent::class,
                $request,
                [$sourceThemeName, $newThemeName, $themeContext]
            );

            $this->themeCopyService->copyTheme($sourceThemeName, $newThemeName);

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::THEME_COPIED,
                [
                    'source_theme' => $sourceThemeName,
                    'new_theme' => $newThemeName,
                    'context' => $themeContext,
                ]
            );

            $this->dispatchDataEvent(
                ThemeCopiedEvent::class,
                $request,
                [$sourceThemeName, $sourceDisplayName, $newThemeName, $themeContext]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.theme.copy_success'),
                $sourceDisplayName,
                $newThemeName
            ));
        } catch (\Exception $e) {
            $this->dispatchDataEvent(
                ThemeCopyFailedEvent::class,
                $request,
                [$sourceThemeName, $newThemeName, $themeContext, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.copy_error'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
            ->generateUrl());
    }

    #[Route('/admin/theme/export', name: 'admin_theme_export', methods: ['GET'])]
    public function exportTheme(AdminContext $context): Response
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::EXPORT_THEME)) {
            throw $this->createAccessDeniedException('You do not have permission to export themes.');
        }

        $request = $context->getRequest();
        $themeName = $request->query->get('themeName');
        $themeContext = $request->query->get('context', 'panel');

        if (!in_array($themeContext, ['panel', 'landing', 'email'], true)) {
            $themeContext = 'panel';
        }

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
            $this->dispatchDataEvent(
                ThemeExportRequestedEvent::class,
                $request,
                [$themeName, $themeContext]
            );

            $zipFilePath = $this->themeExportService->exportTheme($themeName);

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::THEME_EXPORTED,
                [
                    'theme' => $themeName,
                    'context' => $themeContext,
                ]
            );

            $this->dispatchDataEvent(
                ThemeExportedEvent::class,
                $request,
                [$themeName, $themeContext, $zipFilePath]
            );

            return $this->themeExportService->createDownloadResponse($zipFilePath, $themeName);
        } catch (\Exception $e) {
            $this->dispatchDataEvent(
                ThemeExportFailedEvent::class,
                $request,
                [$themeName, $themeContext, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.theme.export_error'),
                $e->getMessage()
            ));
            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('index')
                ->set('context', $themeContext)
                ->generateUrl());
        }
    }

    #[Route('/admin/theme/upload', name: 'admin_theme_upload')]
    public function uploadTheme(AdminContext $context): Response
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::UPLOAD_THEME)) {
            throw $this->createAccessDeniedException('You do not have permission to upload themes.');
        }

        $request = $context->getRequest();

        $this->dispatchSimpleEvent(ThemeUploadPageAccessedEvent::class, $request);

        $form = $this->createForm(ThemeUploadFormType::class);

        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        $this->appendCrudTemplateContext('theme');

        $backUrl = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('index')
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
        if (!$this->getUser()?->hasPermission(PermissionEnum::UPLOAD_THEME)) {
            throw $this->createAccessDeniedException('You do not have permission to upload themes.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $form = $this->createForm(ThemeUploadFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $this->translator->trans('pteroca.theme.upload.errors.invalid_form'));
            return $this->redirect($this->adminUrlGenerator->setRoute('admin_theme_upload')->generateUrl());
        }

        try {
            $file = $form->get('themeFile')->getData();

            $this->dispatchDataEvent(
                ThemeUploadRequestedEvent::class,
                $request,
                [$file->getClientOriginalName()]
            );

            $result = $this->themeUploadService->uploadTheme($file, true);

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::THEME_UPLOADED,
                [
                    'theme' => $result->manifest->name,
                    'version' => $result->manifest->version,
                ]
            );

            $this->dispatchDataEvent(
                ThemeUploadedEvent::class,
                $request,
                [$result->manifest->name, $result->manifest->version, $result->hasWarnings(), count($result->warnings)]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.theme.upload.success'),
                $result->manifest->name,
                $result->manifest->version
            ));

            if ($result->hasWarnings()) {
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

                foreach ($groupedWarnings as $type => $data) {
                    $warningMessage = $this->translator->trans('pteroca.theme.upload.warning.' . $type);

                    if ($data['count'] > 1) {
                        $warningMessage .= sprintf(' (%d %s)',
                            $data['count'],
                            $this->translator->trans('pteroca.theme.upload.occurrences')
                        );
                    }

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
            $this->dispatchDataEvent(
                ThemeUploadFailedEvent::class,
                $request,
                [$e->getMessage(), get_class($e)]
            );

            $this->addFlash('danger', $e->getMessage());
        } catch (InvalidThemeStructureException $e) {
            $this->dispatchDataEvent(
                ThemeUploadFailedEvent::class,
                $request,
                [$e->getMessage(), get_class($e)]
            );

            $this->addFlash('danger', $this->translator->trans('pteroca.theme.upload.errors.invalid_structure'));
        } catch (InvalidTemplateManifestException $e) {
            $details = $e->getDetails();
            $errors = isset($details['errors']) ? implode(', ', $details['errors']) : $e->getMessage();

            $this->dispatchDataEvent(
                ThemeUploadFailedEvent::class,
                $request,
                [$errors, get_class($e)]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.theme.upload.errors.invalid_manifest'),
                $errors
            ));
        } catch (ThemeSecurityException $e) {
            $this->dispatchDataEvent(
                ThemeUploadFailedEvent::class,
                $request,
                [$e->getMessage(), get_class($e)]
            );

            $this->addFlash('danger', $this->translator->trans('pteroca.theme.upload.errors.security_critical'));
        } catch (\Exception $e) {
            $this->dispatchDataEvent(
                ThemeUploadFailedEvent::class,
                $request,
                [$e->getMessage(), get_class($e)]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.theme.upload.errors.generic'),
                $e->getMessage()
            ));
        }

        return $this->redirect($this->adminUrlGenerator->setRoute('admin_theme_upload')->generateUrl());
    }

    private function getThemeActions(ThemeDTO $theme): array
    {
        $actions = [];

        // View Details
        if ($this->getUser()?->hasPermission(PermissionEnum::VIEW_THEME)) {
            $actions[] = [
                'name' => 'details',
                'label' => $this->translator->trans('pteroca.crud.theme.show_details'),
                'icon' => 'fa fa-eye',
                'url' => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('viewDetails')
                    ->set('themeName', $theme->getName())
                    ->set('context', 'panel')
                    ->generateUrl(),
                'class' => 'info',
            ];
        }

        // Set as Default actions for each supported context
        if ($this->getUser()?->hasPermission(PermissionEnum::SET_DEFAULT_THEME)) {
            foreach ($theme->getContexts() as $context) {
                if (!$theme->isActiveInContext($context)) {
                    $contextLabel = match($context) {
                        'panel' => $this->translator->trans('pteroca.crud.theme.context_panel'),
                        'landing' => $this->translator->trans('pteroca.crud.theme.context_landing'),
                        'email' => $this->translator->trans('pteroca.crud.theme.context_email'),
                        default => ucfirst($context),
                    };

                    $actions[] = [
                        'name' => 'set_default_' . $context,
                        'label' => sprintf(
                            $this->translator->trans('pteroca.crud.theme.set_as_default_in'),
                            $contextLabel
                        ),
                        'icon' => 'fa fa-check',
                        'url' => '#',
                        'class' => 'success',
                        'data_attrs' => [
                            'bs-toggle' => 'modal',
                            'bs-target' => '#setDefaultThemeModal',
                            'theme-name' => $theme->getName(),
                            'theme-display-name' => $theme->getDisplayName(),
                            'theme-context' => $context,
                        ],
                    ];
                }
            }
        }

        // Copy Theme
        if ($this->getUser()?->hasPermission(PermissionEnum::COPY_THEME)) {
            $actions[] = [
                'name' => 'copy',
                'label' => $this->translator->trans('pteroca.crud.theme.copy_theme'),
                'icon' => 'fa fa-copy',
                'url' => '#',
                'class' => 'primary',
                'data_attrs' => [
                    'bs-toggle' => 'modal',
                    'bs-target' => '#copyThemeModal',
                    'theme-name' => $theme->getName(),
                    'theme-display-name' => $theme->getDisplayName(),
                    'theme-context' => 'panel',
                ],
            ];
        }

        // Export Theme
        if ($this->getUser()?->hasPermission(PermissionEnum::EXPORT_THEME)) {
            $actions[] = [
                'name' => 'export',
                'label' => $this->translator->trans('pteroca.crud.theme.export_theme'),
                'icon' => 'fa fa-download',
                'url' => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('exportTheme')
                    ->set('themeName', $theme->getName())
                    ->set('context', 'panel')
                    ->generateUrl(),
                'class' => 'secondary',
            ];
        }

        // Delete Theme (only if not active in ANY context)
        if (!$theme->isActiveInAnyContext()
            && $theme->getName() !== 'default'
            && $this->getUser()?->hasPermission(PermissionEnum::DELETE_THEME)) {
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
                    'theme-context' => 'panel',
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
