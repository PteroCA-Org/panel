<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\Permission;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Service\Crud\PanelCrudService;

class PermissionCrudController extends AbstractPanelController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Permission::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [];

        if ($pageName !== Crud::PAGE_NEW) {
            $fields[] = NumberField::new('id')
                ->setDisabled()
                ->setColumns(2);
            $fields[] = DateField::new('createdAt', $this->translator->trans('pteroca.crud.permission.created_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = DateField::new('updatedAt', $this->translator->trans('pteroca.crud.permission.updated_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = FormField::addRow();
        }

        $fields = array_merge($fields, [
            TextField::new('code', $this->translator->trans('pteroca.crud.permission.code'))
                ->setRequired(true)
                ->setColumns(6)
                ->setDisabled($pageName === Crud::PAGE_EDIT),
            TextField::new('name', $this->translator->trans('pteroca.crud.permission.name'))
                ->setRequired(true)
                ->setColumns(6),
            FormField::addRow(),
            TextField::new('section', $this->translator->trans('pteroca.crud.permission.section'))
                ->setRequired(true)
                ->setColumns(6)
                ->formatValue(function ($value) {
                    return $this->translator->trans('pteroca.permission.section.' . $value);
                }),
            TextField::new('pluginName', $this->translator->trans('pteroca.crud.permission.plugin_name'))
                ->setColumns(6)
                ->hideOnIndex(),
            FormField::addRow(),
            TextareaField::new('description', $this->translator->trans('pteroca.crud.permission.description'))
                ->setRequired(false)
                ->setColumns(12)
                ->hideOnIndex(),
            FormField::addRow(),
            BooleanField::new('isSystem', $this->translator->trans('pteroca.crud.permission.is_system'))
                ->setHelp($this->translator->trans('pteroca.crud.permission.is_system_help'))
                ->setDisabled()
                ->setColumns(3)
                ->hideOnIndex(),
        ]);

        if ($pageName === Crud::PAGE_DETAIL) {
            $fields[] = FormField::addRow();
            $fields[] = AssociationField::new('roles', $this->translator->trans('pteroca.crud.permission.roles'))
                ->setTemplatePath('panel/admin/field/permission_roles.html.twig')
                ->setColumns(12);
        }

        if ($pageName === Crud::PAGE_INDEX) {
            $fields[] = NumberField::new('roles.count', $this->translator->trans('pteroca.crud.permission.roles_count'))
                ->formatValue(function ($value, $entity) {
                    return $entity->getRoles()->count();
                });
        }

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_DETAIL, Action::EDIT)
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->remove(Crud::PAGE_DETAIL, Action::DELETE)
            ->remove(Crud::PAGE_INDEX, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, 'access_permissions')
            ->setPermission(Action::DETAIL, 'view_permission');

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.permission.permission'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.permission.permissions'))
            ->setDefaultSort(['section' => 'ASC', 'name' => 'ASC']);

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('code')
            ->add('name')
            ->add('section')
            ->add('pluginName')
            ->add('isSystem')
            ->add('createdAt')
            ->add('updatedAt')
        ;
        return parent::configureFilters($filters);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Permissions are managed by the system, not manually created
        $this->addFlash('danger', $this->translator->trans('pteroca.crud.permission.cannot_create'));
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Permissions are managed by the system, not manually updated
        $this->addFlash('danger', $this->translator->trans('pteroca.crud.permission.cannot_update'));
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Permissions are managed by the system, not manually deleted
        $this->addFlash('danger', $this->translator->trans('pteroca.crud.permission.cannot_delete'));
    }
}
