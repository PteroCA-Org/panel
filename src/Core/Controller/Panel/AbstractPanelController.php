<?php

namespace App\Core\Controller\Panel;

use App\Core\Enum\LogActionEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Event\Crud\CrudActionsConfiguredEvent;
use App\Core\Event\Crud\CrudConfiguredEvent;
use App\Core\Event\Crud\CrudEntityDeletedEvent;
use App\Core\Event\Crud\CrudEntityDeletingEvent;
use App\Core\Event\Crud\CrudEntityPersistedEvent;
use App\Core\Event\Crud\CrudEntityPersistingEvent;
use App\Core\Event\Crud\CrudEntityUpdatedEvent;
use App\Core\Event\Crud\CrudEntityUpdatingEvent;
use App\Core\Event\Crud\CrudFieldsConfiguredEvent;
use App\Core\Event\Crud\CrudFiltersConfiguredEvent;
use App\Core\Event\Crud\CrudIndexQueryBuiltEvent;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Trait\EventContextTrait;
use App\Core\Trait\GetUserTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Inflector\EnglishInflector;

abstract class AbstractPanelController extends AbstractCrudController
{
    use GetuserTrait;
    use EventContextTrait;

    private array $crudTemplateContext = [];
    protected bool $useConventionBasedPermissions = true;
    protected array $fields = [];

    public function __construct(
        private readonly PanelCrudService $panelCrudService,
        protected readonly RequestStack $requestStack,
    ) {
    }

    public function appendCrudTemplateContext(string $templateContext): void
    {
        $this->crudTemplateContext[] = $templateContext;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // Auto-set entity permission if convention-based permissions are enabled
        if ($this->shouldUseConventionBasedPermissions()) {
            $entityPluralSlug = $this->getEntityPluralSlug();
            $entityPermission = 'access_' . $entityPluralSlug;
            $crud->setEntityPermission($entityPermission);
        }

        $crud->overrideTemplates($this->panelCrudService->getTemplatesToOverride($this->crudTemplateContext));

        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $event = new CrudConfiguredEvent(
            static::getEntityFqcn(),
            $crud,
            $this->getUser(),
            $context
        );

        $event = $this->dispatchEvent($event);
        $crud = $event->getCrud();

        return parent::configureCrud($crud);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);

        // Apply convention-based permissions if enabled
        if ($this->shouldUseConventionBasedPermissions()) {
            $actions = $this->applyConventionBasedPermissions($actions);
            $actions = $this->applyPermissionBasedVisibility($actions);
        }

        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $event = new CrudActionsConfiguredEvent(
            static::getEntityFqcn(),
            $actions,
            $this->getUser(),
            $context
        );

        $event = $this->dispatchEvent($event);
        $actions = $event->getActions();

        return $actions;
    }

    public function configureFilters(Filters $filters): Filters
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $event = new CrudFiltersConfiguredEvent(
            static::getEntityFqcn(),
            $filters,
            $this->getUser(),
            $context
        );

        $event = $this->dispatchEvent($event);
        $filters = $event->getFilters();

        return parent::configureFilters($filters);
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = $this->fields;

        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $event = new CrudFieldsConfiguredEvent(
            static::getEntityFqcn(),
            $pageName,
            $fields,
            $this->getUser(),
            $context
        );

        $event = $this->dispatchEvent($event);

        return $event->getFields();
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $appliedFilters = $searchDto->getAppliedFilters();

        if ($entityDto->hasProperty('deletedAt')) {
            if (!array_key_exists('deletedAt', $appliedFilters)) {
                $qb->andWhere('entity.deletedAt IS NULL');
            }
        }

        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $event = new CrudIndexQueryBuiltEvent(
            static::getEntityFqcn(),
            $qb,
            $searchDto,
            $entityDto,
            $fields,
            $filters,
            $this->getUser(),
            $context
        );

        $event = $this->dispatchEvent($event);

        return $event->getQueryBuilder();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $persistingEvent = new CrudEntityPersistingEvent(
            static::getEntityFqcn(),
            $entityInstance,
            $this->getUser(),
            $context
        );

        $persistingEvent = $this->dispatchEvent($persistingEvent);

        if (!$persistingEvent->isPropagationStopped()) {
            parent::persistEntity($entityManager, $entityInstance);

            $persistedEvent = new CrudEntityPersistedEvent(
                static::getEntityFqcn(),
                $entityInstance,
                $this->getUser(),
                $context
            );

            $this->dispatchEvent($persistedEvent);
            $this->logEntityAction(LogActionEnum::ENTITY_ADD, $entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $updatingEvent = new CrudEntityUpdatingEvent(
            static::getEntityFqcn(),
            $entityInstance,
            $this->getUser(),
            $context
        );

        $updatingEvent = $this->dispatchEvent($updatingEvent);

        if (!$updatingEvent->isPropagationStopped()) {
            parent::updateEntity($entityManager, $entityInstance);

            $updatedEvent = new CrudEntityUpdatedEvent(
                static::getEntityFqcn(),
                $entityInstance,
                $this->getUser(),
                $context
            );

            $this->dispatchEvent($updatedEvent);
            $this->logEntityAction(LogActionEnum::ENTITY_EDIT, $entityInstance);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->buildMinimalEventContext($request) : [];

        $deletingEvent = new CrudEntityDeletingEvent(
            static::getEntityFqcn(),
            $entityInstance,
            $this->getUser(),
            $context
        );

        $deletingEvent = $this->dispatchEvent($deletingEvent);

        if (!$deletingEvent->isPropagationStopped()) {
            parent::deleteEntity($entityManager, $entityInstance);

            $deletedEvent = new CrudEntityDeletedEvent(
                static::getEntityFqcn(),
                $entityInstance,
                $this->getUser(),
                $context
            );

            $this->dispatchEvent($deletedEvent);
            $this->logEntityAction(LogActionEnum::ENTITY_DELETE, $entityInstance);
        }
    }

    private function logEntityAction(LogActionEnum $action, $entityInstance): void
    {
        $this->panelCrudService
            ->logEntityAction($action, $entityInstance, $this->getUser(), $this->getEntityFqcn());
    }

    protected function shouldUseConventionBasedPermissions(): bool
    {
        // Opt-out check
        if (!$this->useConventionBasedPermissions) {
            return false;
        }

        // Exclude plugins (namespace detection)
        $reflection = new \ReflectionClass($this);
        $namespace = $reflection->getNamespaceName();
        if (str_contains($namespace, '\\Plugin\\')) {
            return false;
        }

        return true;
    }

    protected function applyConventionBasedPermissions(Actions $actions): Actions
    {
        $permissionMapping = $this->getPermissionMapping();

        foreach ($permissionMapping as $actionName => $permissionCode) {
            // Skip actions with null permission (e.g., link actions that don't need permissions)
            if ($permissionCode === null) {
                continue;
            }

            // Convert enum to string for EasyAdmin
            $codeString = $permissionCode instanceof PermissionEnum
                ? $permissionCode->value
                : $permissionCode;

            // Apply permission for each action
            // If child controllers set permissions manually, they will override these
            try {
                $actions->setPermission($actionName, $codeString);
            } catch (\Exception $e) {
                // Action doesn't exist (e.g., removed action) - skip silently
                continue;
            }
        }

        return $actions;
    }

    protected function getPermissionMapping(): array
    {
        $entitySlug = $this->getEntitySlug();
        $entityPluralSlug = $this->getEntityPluralSlug();

        return [
            Action::INDEX  => 'access_' . $entityPluralSlug,
            Action::DETAIL => 'view_' . $entitySlug,
            Action::NEW    => 'create_' . $entitySlug,
            Action::EDIT   => 'edit_' . $entitySlug,
            Action::DELETE => 'delete_' . $entitySlug,
        ];
    }

    protected function getEntitySlug(): string
    {
        $fqcn = static::getEntityFqcn();
        $shortName = (new \ReflectionClass($fqcn))->getShortName();

        // Convert PascalCase to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }

    protected function getEntityPluralSlug(): string
    {
        $singular = $this->getEntitySlug();

        // Use Symfony's English inflector for intelligent pluralization
        $inflector = new EnglishInflector();
        $plurals = $inflector->pluralize($singular);

        // Return first plural form (most common)
        return $plurals[0] ?? $singular . 's';
    }

    protected function isStandardCrudAction(string $actionName): bool
    {
        return in_array($actionName, [
            Action::INDEX,
            Action::NEW,
            Action::EDIT,
            Action::DELETE,
            Action::DETAIL,
        ], true);
    }

    protected function applyPermissionBasedVisibility(Actions $actions): Actions
    {
        if (!$this->shouldUseConventionBasedPermissions()) {
            return $actions;
        }

        $permissionMapping = $this->getPermissionMapping();

        foreach ($permissionMapping as $actionName => $permissionCode) {
            if ($permissionCode === null || !$this->isStandardCrudAction($actionName)) {
                continue;
            }

            foreach ([Crud::PAGE_INDEX, Crud::PAGE_DETAIL] as $page) {
                try {
                    $actions->update(
                        $page,
                        $actionName,
                        fn (Action $action) => $action->displayIf(
                            fn ($entity) => $this->getUser()?->hasPermission($permissionCode) ?? false
                        )
                    );
                } catch (\Exception $e) {
                    // Action doesn't exist on this page - skip
                    continue;
                }
            }
        }

        return $actions;
    }

    protected function renderWithEvent(
        ViewNameEnum $viewName,
        string $template,
        array $viewData,
        Request $request
    ): Response
    {
        $viewEvent = $this->prepareViewDataEvent($viewName, $viewData, $request);

        return $this->render($template, $viewEvent->getViewData());
    }
}
