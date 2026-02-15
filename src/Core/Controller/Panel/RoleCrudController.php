<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\Role;
use App\Core\Enum\PermissionEnum;
use App\Core\Service\Security\RoleManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Service\Crud\PanelCrudService;
use RuntimeException;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;

class RoleCrudController extends AbstractPanelController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly RoleManager $roleManager,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Role::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [];

        if ($pageName !== Crud::PAGE_NEW) {
            $fields[] = NumberField::new('id')
                ->setDisabled()
                ->setColumns(2);
            $fields[] = DateField::new('createdAt', $this->translator->trans('pteroca.crud.role.created_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = DateField::new('updatedAt', $this->translator->trans('pteroca.crud.role.updated_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = FormField::addRow();
        }

        $fields = array_merge($fields, [
            TextField::new('name', $this->translator->trans('pteroca.crud.role.name'))
                ->setHelp($this->translator->trans('pteroca.crud.role.name_help'))
                ->setRequired(true)
                ->setColumns(6)
                ->setDisabled($pageName === Crud::PAGE_EDIT)
                ->hideOnIndex(),
            TextField::new('displayName', $this->translator->trans('pteroca.crud.role.display_name'))
                ->setRequired(true)
                ->setColumns(6),
            FormField::addRow(),
            TextareaField::new('description', $this->translator->trans('pteroca.crud.role.description'))
                ->setRequired(false)
                ->setColumns(12)
                ->hideOnIndex(),
        ]);

        if ($pageName === Crud::PAGE_EDIT || $pageName === Crud::PAGE_NEW) {
            $fields[] = FormField::addRow();
            $fields[] = AssociationField::new('permissions', $this->translator->trans('pteroca.crud.role.permissions'))
                ->setFormTypeOption('by_reference', false)
                ->setFormTypeOption('choice_label', function ($permission) {
                    return sprintf('[%s] %s', $permission->getSection(), $permission->getName());
                })
                ->setFormTypeOption('group_by', function ($permission) {
                    return $this->translator->trans('pteroca.permission.section.' . $permission->getSection());
                })
                ->setHelp($this->translator->trans('pteroca.crud.role.permissions_help'))
                ->setColumns(12);
        }

        if ($pageName === Crud::PAGE_DETAIL) {
            $fields[] = FormField::addRow();
            $fields[] = AssociationField::new('permissions', $this->translator->trans('pteroca.crud.role.permissions'))
                ->setTemplatePath('panel/admin/field/role_permissions.html.twig')
                ->setColumns(12);
            $fields[] = FormField::addRow();
            $fields[] = AssociationField::new('users', $this->translator->trans('pteroca.crud.role.users'))
                ->setTemplatePath('panel/admin/field/role_users.html.twig')
                ->setColumns(12);
        }

        if ($pageName === Crud::PAGE_INDEX) {
            $fields[] = NumberField::new('permissions.count', $this->translator->trans('pteroca.crud.role.permissions_count'))
                ->formatValue(function ($value, $entity) {
                    return $entity->getPermissions()->count();
                });
            $fields[] = NumberField::new('users.count', $this->translator->trans('pteroca.crud.role.users_count'))
                ->formatValue(function ($value, $entity) {
                    $activeCount = 0;
                    foreach ($entity->getUsers() as $user) {
                        if ($user->getDeletedAt() === null && !$user->isBlocked()) {
                            $activeCount++;
                        }
                    }
                    return $activeCount;
                });
        }

        $this->fields = $fields;

        return parent::configureFields($pageName);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);

        $actions = $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.role.add')))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.role.add')))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.role.save')))
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_DETAIL, Action::EDIT, fn (Action $action) => $action->displayIf(
                fn ($entity) =>
                    $entity instanceof Role &&
                    $this->getUser()?->hasPermission(PermissionEnum::EDIT_ROLE) &&
                    !$entity->isSystem()
            ))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action->displayIf(
                fn ($entity) =>
                    $entity instanceof Role &&
                    $this->getUser()?->hasPermission(PermissionEnum::EDIT_ROLE) &&
                    !$entity->isSystem()
            ))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, fn (Action $action) => $action->displayIf(
                fn ($entity) =>
                    $entity instanceof Role &&
                    $this->getUser()?->hasPermission(PermissionEnum::DELETE_ROLE) &&
                    !$entity->isSystem()
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => $action->displayIf(
                fn ($entity) =>
                    $entity instanceof Role &&
                    $this->getUser()?->hasPermission(PermissionEnum::DELETE_ROLE) &&
                    !$entity->isSystem()
            ));

        return $actions;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.role.role'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.role.roles'))
            ->setDefaultSort(['isSystem' => 'DESC', 'displayName' => 'ASC']);

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('name')
            ->add('displayName')
            ->add('isSystem')
            ->add('createdAt')
            ->add('updatedAt')
        ;
        return parent::configureFilters($filters);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Role) {
            try {
                $permissions = $entityInstance->getPermissions()->toArray();

                $normalizedName = strtolower($entityInstance->getName());
                $normalizedName = preg_replace('/[^a-z0-9_]+/', '_', $normalizedName);
                $normalizedName = trim($normalizedName, '_');

                $role = $this->roleManager->createRole(
                    name: $normalizedName,
                    displayName: $entityInstance->getDisplayName(),
                    description: $entityInstance->getDescription(),
                    permissions: [],
                    isSystem: false
                );

                if (!empty($permissions)) {
                    $this->roleManager->assignPermissions($role, $permissions);
                }

                $this->addFlash('success', $this->translator->trans('pteroca.crud.role.created_successfully'));

                $entityInstance = $role;
            } catch (RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
                return;
            }
        }

        // Don't call parent::persistEntity as we already persisted via RoleManager
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Role) {
            try {
                if ($entityInstance->isSystem()) {
                    $this->addFlash('danger', $this->translator->trans('pteroca.crud.role.cannot_update_system_role'));
                    return;
                }

                $this->roleManager->updateRole(
                    role: $entityInstance,
                    displayName: $entityInstance->getDisplayName(),
                    description: $entityInstance->getDescription()
                );

                $permissions = $entityInstance->getPermissions()->toArray();
                $this->roleManager->assignPermissions($entityInstance, $permissions);

                $this->addFlash('success', $this->translator->trans('pteroca.crud.role.updated_successfully'));
            } catch (RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
                return;
            }
        }

        // Don't call parent::updateEntity as we already updated via RoleManager
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Role) {
            try {
                if ($entityInstance->isSystem()) {
                    $this->addFlash('danger', $this->translator->trans('pteroca.crud.role.cannot_delete_system_role'));
                    return;
                }

                $this->roleManager->deleteRole($entityInstance);
                $this->addFlash('success', $this->translator->trans('pteroca.crud.role.deleted_successfully'));
            } catch (RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
                return;
            }
        }

        // Don't call parent::deleteEntity as we already deleted via RoleManager
    }

    public function edit(AdminContext $context)
    {
        $entity = $context->getEntity()->getInstance();

        if ($entity instanceof Role && $entity->isSystem()) {
            $this->addFlash('danger', $this->translator->trans('pteroca.crud.role.cannot_edit_system_role'));
            return $this->redirect($context->getReferrer());
        }

        return parent::edit($context);
    }

    public function delete(AdminContext $context)
    {
        $entity = $context->getEntity()->getInstance();

        if ($entity instanceof Role && $entity->isSystem()) {
            $this->addFlash('danger', $this->translator->trans('pteroca.crud.role.cannot_delete_system_role'));
            return $this->redirect($context->getReferrer());
        }

        return parent::delete($context);
    }
}
