<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Controller\Panel\AbstractPanelController;
use App\Core\Entity\Plugin;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\PluginStateEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Event\Plugin\PluginDeletedEvent;
use App\Core\Event\Plugin\PluginDeletingEvent;
use App\Core\Event\Plugin\PluginDeletionFailedEvent;
use App\Core\Event\Plugin\PluginDetailsDataLoadedEvent;
use App\Core\Event\Plugin\PluginDetailsPageAccessedEvent;
use App\Core\Event\Plugin\PluginDisablementFailedEvent;
use App\Core\Event\Plugin\PluginDisablementRequestedEvent;
use App\Core\Event\Plugin\PluginEnablementFailedEvent;
use App\Core\Event\Plugin\PluginEnablementRequestedEvent;
use App\Core\Event\Plugin\PluginIndexDataLoadedEvent;
use App\Core\Event\Plugin\PluginIndexPageAccessedEvent;
use App\Core\Event\Plugin\PluginResetEvent;
use App\Core\Event\Plugin\PluginResetFailedEvent;
use App\Core\Event\Plugin\PluginResetRequestedEvent;
use App\Core\Event\Plugin\PluginUploadedEvent;
use App\Core\Event\Plugin\PluginUploadFailedEvent;
use App\Core\Event\Plugin\PluginUploadPageAccessedEvent;
use App\Core\Event\Plugin\PluginUploadRequestedEvent;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Plugin\PluginManager;
use App\Core\Service\Plugin\PluginDependencyResolver;
use App\Core\Service\Plugin\PluginHealthCheckService;
use App\Core\Service\Plugin\PluginSecurityValidator;
use App\Core\Service\Plugin\PluginUploadService;
use App\Core\Exception\Plugin\PluginDependencyException;
use App\Core\Form\PluginUploadFormType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Composer\Semver\Semver;
use App\Core\Enum\PermissionEnum;

/**
 * Plugin CRUD Controller
 */
class PluginCrudController extends AbstractPanelController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly PluginManager $pluginManager,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LogService $logService,
        private readonly PluginDependencyResolver $dependencyResolver,
        private readonly PluginHealthCheckService $healthCheckService,
        private readonly PluginSecurityValidator $securityValidator,
        private readonly PluginUploadService $pluginUploadService,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Plugin::class;
    }

    protected function getPermissionMapping(): array
    {
        return [
            Action::INDEX  => PermissionEnum::ACCESS_PLUGINS->value,
            Action::DETAIL => PermissionEnum::VIEW_PLUGIN->value,
            'settingsAction' => null, // Link action - no permission needed
            'enableAction' => PermissionEnum::ENABLE_PLUGIN->value,
            'disableAction' => PermissionEnum::DISABLE_PLUGIN->value,
            'resetAction' => PermissionEnum::ENABLE_PLUGIN->value,
            'uploadPlugin' => PermissionEnum::UPLOAD_PLUGIN->value,
            'deletePlugin' => PermissionEnum::UNINSTALL_PLUGIN->value,
        ];
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            TextField::new('name', $this->translator->trans('pteroca.crud.plugin.name'))
                ->hideOnForm(),
            TextField::new('displayName', $this->translator->trans('pteroca.crud.plugin.display_name'))
                ->hideOnForm(),
            TextField::new('version', $this->translator->trans('pteroca.crud.plugin.version'))
                ->setColumns(2),
            TextField::new('author', $this->translator->trans('pteroca.crud.plugin.author'))
                ->setColumns(2)
                ->hideOnIndex(),
            TextField::new('description', $this->translator->trans('pteroca.crud.plugin.description'))
                ->hideOnIndex(),
            TextField::new('state', $this->translator->trans('pteroca.crud.plugin.state'))
                ->setColumns(2)
                ->formatValue(function ($value) {
                    return sprintf(
                        '<span class="badge bg-%s">%s</span>',
                        $value->getBadgeClass(),
                        $value->getLabel()
                    );
                }),
            ArrayField::new('capabilities', $this->translator->trans('pteroca.crud.plugin.capabilities'))
                ->hideOnForm()
                ->hideOnIndex(),
            TextField::new('pterocaMinVersion', $this->translator->trans('pteroca.crud.plugin.min_pteroca'))
                ->setColumns(2)
                ->hideOnIndex(),
            TextField::new('pterocaMaxVersion', $this->translator->trans('pteroca.crud.plugin.max_pteroca'))
                ->setColumns(2)
                ->hideOnIndex(),
            DateTimeField::new('enabledAt', $this->translator->trans('pteroca.crud.plugin.enabled_at'))
                ->hideOnForm()
                ->hideOnIndex(),
            DateTimeField::new('disabledAt', $this->translator->trans('pteroca.crud.plugin.disabled_at'))
                ->hideOnForm()
                ->hideOnIndex(),
            TextField::new('faultReason', $this->translator->trans('pteroca.crud.plugin.fault_reason'))
                ->hideOnForm()
                ->hideOnIndex(),
            DateTimeField::new('createdAt', $this->translator->trans('pteroca.crud.plugin.created_at'))
                ->hideOnForm()
                ->hideOnIndex(),
        ];

        $fields = array_merge($fields, parent::configureFields($pageName));

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        $settingsAction = Action::new('settings', $this->translator->trans('pteroca.crud.plugin.settings'), 'fa fa-cog')
            ->linkToUrl(function (Plugin $plugin) {
                return $this->adminUrlGenerator
                    ->setController(PluginSettingCrudController::class)
                    ->setAction(Action::INDEX)
                    ->set('pluginName', $plugin->getName())
                    ->generateUrl();
            })
            ->displayIf(function (Plugin $plugin) {
                // Show settings for plugins that have been enabled at least once (have DB entry)
                return $plugin->getId() !== null &&
                    $this->getUser()?->hasPermission(PermissionEnum::ACCESS_PLUGINS);
            });

        $enableAction = Action::new('enable', $this->translator->trans('pteroca.crud.plugin.enable'), 'fa fa-check')
            ->linkToCrudAction('enablePlugin')
            ->displayIf(function (Plugin $plugin) {
                $state = $plugin->getState();
                return $state->canBeEnabled() &&
                    $this->getUser()?->hasPermission(PermissionEnum::ENABLE_PLUGIN);
            });

        $disableAction = Action::new('disable', $this->translator->trans('pteroca.crud.plugin.disable'), 'fa fa-times')
            ->linkToCrudAction('disablePlugin')
            ->displayIf(function (Plugin $plugin) {
                return $plugin->getState() === PluginStateEnum::ENABLED &&
                    $this->getUser()?->hasPermission(PermissionEnum::DISABLE_PLUGIN);
            });

        $resetAction = Action::new('reset', $this->translator->trans('pteroca.crud.plugin.reset'), 'fa fa-redo')
            ->linkToCrudAction('resetPlugin')
            ->displayIf(function (Plugin $plugin) {
                return $plugin->getState() === PluginStateEnum::FAULTED &&
                    $this->getUser()?->hasPermission(PermissionEnum::ENABLE_PLUGIN);
            });

        $deleteAction = Action::new('uninstall', $this->translator->trans('pteroca.crud.plugin.delete'), 'fa fa-trash')
            ->linkToCrudAction('deletePlugin')
            ->displayIf(function (Plugin $plugin) {
                return $plugin->getId() !== null &&
                    $this->getUser()?->hasPermission(PermissionEnum::UNINSTALL_PLUGIN);
            })
            ->addCssClass('btn-danger');

        $actions = $actions
            ->add(Crud::PAGE_INDEX, $settingsAction)
            ->add(Crud::PAGE_INDEX, $enableAction)
            ->add(Crud::PAGE_INDEX, $disableAction)
            ->add(Crud::PAGE_INDEX, $resetAction)
            ->add(Crud::PAGE_DETAIL, $settingsAction)
            ->add(Crud::PAGE_DETAIL, $enableAction)
            ->add(Crud::PAGE_DETAIL, $disableAction)
            ->add(Crud::PAGE_DETAIL, $resetAction)
            ->add(Crud::PAGE_DETAIL, $deleteAction)
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::DELETE)
            ->remove(Crud::PAGE_DETAIL, Action::DELETE)
            ->remove(Crud::PAGE_DETAIL, Action::EDIT);

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        $this->appendCrudTemplateContext('plugin');

        return $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.plugin.plugin'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.plugin.plugins'))
            ->setDefaultSort(['name' => 'ASC'])
            ->setPageTitle(Crud::PAGE_INDEX, $this->translator->trans('pteroca.crud.plugin.plugin_management'))
            ->setPageTitle(Crud::PAGE_DETAIL, fn (Plugin $plugin) => sprintf('%s: %s', $this->translator->trans('pteroca.crud.plugin.plugin'), $plugin->getDisplayName()))
            ->showEntityActionsInlined()
            ->setSearchFields(null);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('name')
            ->add('displayName')
            ->add('state')
            ->add('author');
    }

    public function index(AdminContext $context): Response
    {
        $request = $context->getRequest();

        $this->dispatchSimpleEvent(PluginIndexPageAccessedEvent::class, $request);

        $plugins = $this->pluginManager->getAllPluginsFromFilesystem();
        foreach ($plugins as $plugin) {
            if ($plugin->getId() !== null && $plugin->getState() === PluginStateEnum::UPDATE_PENDING) {
                $this->pluginManager->getPluginByName($plugin->getName()); // This will save the update
            }
        }

        $this->dispatchDataEvent(
            PluginIndexDataLoadedEvent::class,
            $request,
            [$plugins, count($plugins)]
        );

        // Prepare actions for each plugin
        $pluginActions = [];
        foreach ($plugins as $plugin) {
            $pluginActions[$plugin->getName()] = $this->getPluginActions($plugin, Crud::PAGE_INDEX);
        }

        $viewData = [
            'plugins' => $plugins,
            'pageName' => Crud::PAGE_INDEX,
            'plugin_actions' => $pluginActions,
        ];

        return $this->renderWithEvent(
            ViewNameEnum::PLUGIN_INDEX,
            'panel/crud/plugin/index.html.twig',
            $viewData,
            $request
        );
    }

    public function viewDetails(AdminContext $context): Response
    {
        $request = $context->getRequest();
        $pluginName = $request->query->get('pluginName');

        $this->dispatchDataEvent(
            PluginDetailsPageAccessedEvent::class,
            $request,
            [$pluginName, null, null]
        );

        $plugin = $this->pluginManager->getPluginByName($pluginName);

        if ($plugin === null) {
            // Plugin not in DB, try to get from filesystem
            $allPlugins = $this->pluginManager->getAllPluginsFromFilesystem();
            foreach ($allPlugins as $p) {
                if ($p->getName() === $pluginName) {
                    $plugin = $p;
                    break;
                }
            }
        }

        if ($plugin === null) {
            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.plugin.plugin_not_found'),
                $pluginName
            ));
            return $this->redirectToRoute('admin');
        }

        $this->dispatchDataEvent(
            PluginDetailsDataLoadedEvent::class,
            $request,
            [$pluginName, $plugin]
        );

        // Build dependency information for template
        $dependencies = [];
        $requires = $plugin->getRequires();

        foreach ($requires as $depName => $constraint) {
            $depPlugin = $this->pluginManager->getPluginByName($depName);
            $dependencies[] = [
                'name' => $depName,
                'constraint' => $constraint,
                'plugin' => $depPlugin,
                'installed' => $depPlugin !== null,
                'enabled' => $depPlugin && $depPlugin->isEnabled(),
                'version' => $depPlugin?->getVersion(),
                'compatible' => $depPlugin && Semver::satisfies($depPlugin->getVersion(), $constraint),
            ];
        }

        // Get dependents (plugins that depend on this one)
        $dependents = $this->dependencyResolver->getDependents($plugin);

        // Check for circular dependencies
        $hasCircular = $this->dependencyResolver->hasCircularDependency($plugin);
        $circularPath = $hasCircular ? $this->dependencyResolver->getCircularDependencyPath($plugin) : null;

        // Run health check if plugin is enabled or has been enabled before
        $healthCheckResult = null;
        if ($plugin->getId() !== null) {
            try {
                $healthCheckResult = $this->healthCheckService->runHealthCheck($plugin);
            } catch (Exception) {
                // Silently fail if health check fails
            }
        }

        // Run security scan if plugin exists on filesystem
        $securityCheckResult = null;
        try {
            $securityCheckResult = $this->securityValidator->validate($plugin);
        } catch (Exception) {
            // Silently fail if security scan fails
        }

        // Prepare actions for this plugin
        $pluginActions = $this->getPluginActions($plugin, Crud::PAGE_DETAIL);

        $viewData = [
            'plugin' => $plugin,
            'pageName' => Crud::PAGE_DETAIL,
            'dependencies' => $dependencies,
            'dependents' => $dependents,
            'hasCircularDependency' => $hasCircular,
            'circularDependencyPath' => $circularPath,
            'healthCheckResult' => $healthCheckResult,
            'securityCheckResult' => $securityCheckResult,
            'plugin_actions' => $pluginActions,
        ];

        return $this->renderWithEvent(
            ViewNameEnum::PLUGIN_DETAILS,
            'panel/crud/plugin/detail.html.twig',
            $viewData,
            $request
        );
    }

    public function enablePlugin(AdminContext $context): RedirectResponse
    {
        $request = $context->getRequest();
        $pluginName = $request->query->get('pluginName');

        $this->dispatchDataEvent(
            PluginEnablementRequestedEvent::class,
            $request,
            [$pluginName, null]
        );

        try {
            $pluginEntity = $this->pluginManager->getOrCreatePlugin($pluginName);

            $this->pluginManager->enablePlugin($pluginEntity);

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::PLUGIN_ENABLED,
                ['plugin' => $pluginName]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.plugin.plugin_enabled_successfully'),
                $pluginEntity->getDisplayName()
            ));
        } catch (PluginDependencyException $e) {
            // Specialized handling for dependency errors
            $this->dispatchDataEvent(
                PluginEnablementFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                '%s:<br>%s',
                $this->translator->trans('pteroca.crud.plugin.dependency_error'),
                nl2br(htmlspecialchars($e->getMessage()))
            ));
        } catch (Exception $e) {
            $this->dispatchDataEvent(
                PluginEnablementFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.plugin.failed_to_enable_plugin'),
                $e->getMessage()
            ));
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return new RedirectResponse($url);
    }

    public function disablePlugin(AdminContext $context): RedirectResponse
    {
        $request = $context->getRequest();
        $pluginName = $request->query->get('pluginName');

        $this->dispatchDataEvent(
            PluginDisablementRequestedEvent::class,
            $request,
            [$pluginName, null]
        );

        try {
            $pluginEntity = $this->pluginManager->getPluginByName($pluginName);

            if ($pluginEntity === null) {
                throw new RuntimeException('Plugin not found in database');
            }

            $this->pluginManager->disablePlugin($pluginEntity); // cascade=false by default in UI

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::PLUGIN_DISABLED,
                ['plugin' => $pluginName]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.plugin.plugin_disabled_successfully'),
                $pluginEntity->getDisplayName()
            ));
        } catch (PluginDependencyException $e) {
            // Specialized handling for dependency errors (dependents exist)
            $this->dispatchDataEvent(
                PluginDisablementFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('warning', sprintf(
                '%s',
                nl2br(htmlspecialchars($e->getMessage()))
            ));
        } catch (Exception $e) {
            $this->dispatchDataEvent(
                PluginDisablementFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.plugin.failed_to_disable_plugin'),
                $e->getMessage()
            ));
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return new RedirectResponse($url);
    }

    public function resetPlugin(AdminContext $context): RedirectResponse
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::ENABLE_PLUGIN)) {
            throw $this->createAccessDeniedException('You do not have permission to reset plugins.');
        }

        $request = $context->getRequest();
        $pluginName = $request->query->get('pluginName');

        try {
            $pluginEntity = $this->pluginManager->getPluginByName($pluginName);

            if ($pluginEntity === null) {
                throw new RuntimeException('Plugin not found in database');
            }

            $oldFaultReason = $pluginEntity->getFaultReason();

            $this->dispatchDataEvent(
                PluginResetRequestedEvent::class,
                $request,
                [$pluginName, $oldFaultReason]
            );

            // Check if plugin is faulted
            if (!$pluginEntity->getState()->isFaulted()) {
                $this->addFlash('warning', sprintf(
                    $this->translator->trans('pteroca.crud.plugin.plugin_not_faulted'),
                    $pluginEntity->getDisplayName()
                ));
            } else {
                $this->pluginManager->resetPlugin($pluginEntity);

                $this->logService->logAction(
                    $this->getUser(),
                    LogActionEnum::PLUGIN_RESET,
                    [
                        'plugin' => $pluginName,
                        'previous_fault_reason' => $oldFaultReason,
                    ]
                );

                $this->dispatchDataEvent(
                    PluginResetEvent::class,
                    $request,
                    [$pluginName, $oldFaultReason]
                );

                $this->addFlash('success', sprintf(
                    $this->translator->trans('pteroca.crud.plugin.plugin_reset_successfully'),
                    $pluginEntity->getDisplayName()
                ));
            }
        } catch (Exception $e) {
            $this->dispatchDataEvent(
                PluginResetFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.plugin.failed_to_reset_plugin'),
                $e->getMessage()
            ));
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return new RedirectResponse($url);
    }

    public function deletePlugin(AdminContext $context): RedirectResponse
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::UNINSTALL_PLUGIN)) {
            throw $this->createAccessDeniedException('You do not have permission to delete plugins.');
        }

        $request = $context->getRequest();
        $pluginName = $request->request->get('pluginName');

        try {
            $pluginEntity = $this->pluginManager->getPluginByName($pluginName);

            if ($pluginEntity === null) {
                throw new RuntimeException('Plugin not found in database');
            }

            $displayName = $pluginEntity->getDisplayName();

            $event = $this->dispatchDataEvent(
                PluginDeletingEvent::class,
                $request,
                [$pluginName, $pluginEntity]
            );

            if ($event->isPropagationStopped()) {
                $this->addFlash('warning', $this->translator->trans('pteroca.crud.plugin.deletion_prevented'));

                $url = $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction(Action::INDEX)
                    ->generateUrl();

                return new RedirectResponse($url);
            }

            $this->pluginManager->deletePlugin($pluginEntity);

            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::PLUGIN_DELETED,
                ['plugin' => $pluginName]
            );

            $this->dispatchDataEvent(
                PluginDeletedEvent::class,
                $request,
                [$pluginName, $displayName]
            );

            $this->addFlash('success', sprintf(
                $this->translator->trans('pteroca.crud.plugin.plugin_deleted_successfully'),
                $displayName
            ));
        } catch (PluginDependencyException $e) {
            $this->dispatchDataEvent(
                PluginDeletionFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                '%s',
                nl2br(htmlspecialchars($e->getMessage()))
            ));
        } catch (Exception $e) {
            $this->dispatchDataEvent(
                PluginDeletionFailedEvent::class,
                $request,
                [$pluginName, $e->getMessage()]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.crud.plugin.failed_to_delete_plugin'),
                $e->getMessage()
            ));
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $response = new RedirectResponse($url);

        // Send the response immediately and terminate to prevent profiler from running
        // The profiler would try to collect Doctrine metadata which references deleted plugin paths
        $response->send();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit(0);
    }

    public function uploadPlugin(): Response
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::UPLOAD_PLUGIN)) {
            throw $this->createAccessDeniedException('You do not have permission to upload plugins.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $this->dispatchSimpleEvent(PluginUploadPageAccessedEvent::class, $request);

        $form = $this->createForm(PluginUploadFormType::class);

        return $this->render('panel/crud/plugin/upload.html.twig', [
            'form' => $form->createView(),
            'page_title' => $this->translator->trans('pteroca.plugin.upload.page_title'),
        ]);
    }

    public function processUpload(AdminContext $context): Response
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::UPLOAD_PLUGIN)) {
            throw $this->createAccessDeniedException('You do not have permission to upload plugins.');
        }

        $request = $context->getRequest();
        $form = $this->createForm(PluginUploadFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('panel/crud/plugin/upload.html.twig', [
                'form' => $form->createView(),
                'page_title' => $this->translator->trans('pteroca.plugin.upload.page_title'),
            ]);
        }

        try {
            $file = $form->get('pluginFile')->getData();
            $enableAfterUpload = $form->get('enableAfterUpload')->getData();

            $this->dispatchDataEvent(
                PluginUploadRequestedEvent::class,
                $request,
                [$file->getClientOriginalName(), $enableAfterUpload]
            );

            // Upload plugin
            $result = $this->pluginUploadService->uploadPlugin($file);
            $manifest = $result['manifest'];
            $securityIssues = $result['security_issues'];

            // Register in database
            $plugin = $this->pluginManager->getOrCreatePlugin($manifest->name);

            // Log upload
            $this->logService->logAction(
                $this->getUser(),
                LogActionEnum::PLUGIN_UPLOADED,
                ['plugin' => $plugin->getName(), 'version' => $plugin->getVersion()]
            );

            $this->dispatchDataEvent(
                PluginUploadedEvent::class,
                $request,
                [$plugin->getName(), $plugin->getVersion(), !empty($securityIssues)]
            );

            // Handle plugin state based on "enable immediately" checkbox
            if ($enableAfterUpload) {
                try {
                    $this->pluginManager->enablePlugin($plugin);

                    $this->addFlash('success', sprintf(
                        $this->translator->trans('pteroca.plugin.upload.success_enabled'),
                        $plugin->getDisplayName(),
                        $plugin->getVersion()
                    ));
                } catch (Exception $e) {
                    $this->addFlash('warning', sprintf(
                        $this->translator->trans('pteroca.plugin.upload.uploaded_but_failed_to_enable'),
                        $plugin->getDisplayName(),
                        $e->getMessage()
                    ));
                }
            } else {
                // If plugin was previously enabled (e.g., re-upload after folder deletion), disable it
                if ($plugin->getState() === PluginStateEnum::ENABLED) {
                    try {
                        $this->pluginManager->disablePlugin($plugin);
                    } catch (Exception) {
                        // If disabling fails (e.g., due to dependencies), continue
                        // Plugin will stay enabled, user can manually disable it later
                    }
                }

                // Show success message with security warnings if any
                $warningMessage = sprintf(
                    $this->translator->trans('pteroca.plugin.upload.success'),
                    $plugin->getDisplayName(),
                    $plugin->getVersion()
                );

                if (!empty($securityIssues)) {
                    $highIssues = array_filter($securityIssues, fn($i) => $i['severity'] === 'HIGH');
                    if (!empty($highIssues)) {
                        $warningMessage .= ' ' . $this->translator->trans('pteroca.plugin.upload.security_warnings_detected');
                    }
                }

                $this->addFlash('success', $warningMessage);
            }

        } catch (Exception $e) {
            $this->dispatchDataEvent(
                PluginUploadFailedEvent::class,
                $request,
                [$e->getMessage(), get_class($e)]
            );

            $this->addFlash('danger', sprintf(
                $this->translator->trans('pteroca.plugin.upload.failed'),
                $e->getMessage()
            ));

            return $this->render('panel/crud/plugin/upload.html.twig', [
                'form' => $form->createView(),
                'page_title' => $this->translator->trans('pteroca.plugin.upload.page_title'),
            ]);
        }

        // Redirect to plugin list
        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return new RedirectResponse($url);
    }

    /**
     * Get visible actions for a plugin based on configured actions
     * This method is exposed to Twig templates for dynamic action rendering
     */
    public function getPluginActions(Plugin $plugin, string $pageName = Crud::PAGE_INDEX): array
    {
        $actions = [];

        // Settings action
        if ($plugin->getId() !== null && $this->getUser()?->hasPermission(PermissionEnum::ACCESS_PLUGINS)) {
            $actions[] = [
                'name' => 'settings',
                'label' => $this->translator->trans('pteroca.crud.plugin.settings'),
                'icon' => 'fa fa-cog',
                'url' => $this->adminUrlGenerator
                    ->setController(PluginSettingCrudController::class)
                    ->setAction(Action::INDEX)
                    ->set('pluginName', $plugin->getName())
                    ->generateUrl(),
                'class' => 'secondary',
            ];
        }

        // Enable action
        $state = $plugin->getState();
        if ($state->canBeEnabled() && $this->getUser()?->hasPermission(PermissionEnum::ENABLE_PLUGIN)) {
            $actions[] = [
                'name' => 'enable',
                'label' => $this->translator->trans('pteroca.crud.plugin.enable'),
                'icon' => 'fa fa-check',
                'url' => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('enablePlugin')
                    ->set('pluginName', $plugin->getName())
                    ->generateUrl(),
                'class' => 'success',
            ];
        }

        // Disable action
        if ($state->canBeDisabled() && $this->getUser()?->hasPermission(PermissionEnum::DISABLE_PLUGIN)) {
            $actions[] = [
                'name' => 'disable',
                'label' => $this->translator->trans('pteroca.crud.plugin.disable'),
                'icon' => 'fa fa-times',
                'url' => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('disablePlugin')
                    ->set('pluginName', $plugin->getName())
                    ->generateUrl(),
                'class' => 'warning',
            ];
        }

        // Reset action
        if ($state === PluginStateEnum::FAULTED && $this->getUser()?->hasPermission(PermissionEnum::ENABLE_PLUGIN)) {
            $actions[] = [
                'name' => 'reset',
                'label' => $this->translator->trans('pteroca.crud.plugin.reset'),
                'icon' => 'fa fa-redo',
                'url' => $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction('resetPlugin')
                    ->set('pluginName', $plugin->getName())
                    ->generateUrl(),
                'class' => 'info',
            ];
        }

        // Delete action (modal trigger) - only on detail page
        if ($pageName === Crud::PAGE_DETAIL && $plugin->getId() !== null && $this->getUser()?->hasPermission(PermissionEnum::UNINSTALL_PLUGIN)) {
            $actions[] = [
                'name' => 'uninstall',
                'label' => $this->translator->trans('pteroca.crud.plugin.delete'),
                'icon' => 'fa fa-trash',
                'url' => '#',
                'class' => 'danger',
                'data_attrs' => [
                    'bs-toggle' => 'modal',
                    'bs-target' => '#deletePluginModal',
                    'plugin-name' => $plugin->getName(),
                    'plugin-display-name' => $plugin->getDisplayName(),
                ],
            ];
        }

        return $actions;
    }
}
