<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\Category;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Service\Crud\PanelCrudService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;


class CategoryCrudController extends AbstractPanelController
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
        return Category::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $uploadDirectory = str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->getParameter('categories_directory')
        );

        return [
            NumberField::new('id', 'ID')->onlyOnIndex(),
            TextField::new('name', $this->translator->trans('pteroca.crud.category.name'))
                ->setColumns(6),
            ImageField::new('imagePath', $this->translator->trans('pteroca.crud.category.image'))
                ->setBasePath($this->getParameter('categories_base_path'))
                ->setUploadDir($uploadDirectory)
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false),
            TextareaField::new('description', $this->translator->trans('pteroca.crud.category.description'))
                ->setColumns(6),

        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.category.add')))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.category.add')))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.category.save')))
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::CATEGORY->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.category.category'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.category.categories'))
            ;

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('id')
            ->add('name')
            ->add('description')
        ;
        return parent::configureFilters($filters);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleFileUpload($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleFileUpload($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->removeFile($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function handleFileUpload($entityInstance): void
    {
        /** @var Category $entityInstance */
        $file = $entityInstance->getImageFile();
        if ($file instanceof File) {
            $fileName = uniqid() . '.' . $file->guessExtension();
            $file->move($this->getParameter('categories_directory'), $fileName);
            $entityInstance->setImagePath($fileName);
        }
    }

    private function removeFile($entityInstance): void
    {
        /** @var Category $entityInstance */
        $imagePath = $entityInstance->getImagePath();
        if ($imagePath) {
            $filePath = $this->getParameter('categories_directory') . '/' . $imagePath;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
}
