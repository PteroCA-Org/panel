<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\Product;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Form\ProductPriceDynamicFormType;
use App\Core\Form\ProductPriceFixedFormType;
use App\Core\Form\ProductPriceSlotFormType;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Crud\ProductCopyService;
use App\Core\Service\Product\NestEggsCacheService;
use App\Core\Service\Product\ProductHealthStatusFormatter;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\SettingService;
use App\Core\Trait\ExperimentalFeatureMessageTrait;
use App\Core\Trait\ProductCrudControllerTrait;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\HiddenField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProductCrudController extends AbstractPanelController
{
    use ProductCrudControllerTrait;
    use ExperimentalFeatureMessageTrait;

    public function __construct(
        PanelCrudService $panelCrudService,
        private readonly RequestStack $requestStack,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly SettingService $settingService,
        private readonly TranslatorInterface $translator,
        private readonly ProductCopyService $productCopyService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly NestEggsCacheService $nestEggsCacheService,
        private readonly ProductHealthStatusFormatter $productHealthStatusFormatter,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    protected function getPermissionMapping(): array
    {
        return [
            Action::INDEX  => PermissionEnum::ACCESS_PRODUCTS->value,
            Action::DETAIL => PermissionEnum::VIEW_PRODUCT->value,
            Action::NEW    => PermissionEnum::CREATE_PRODUCT->value,
            Action::EDIT   => PermissionEnum::EDIT_PRODUCT->value,
            Action::DELETE => PermissionEnum::DELETE_PRODUCT->value,
            'copyProduct' => PermissionEnum::COPY_PRODUCT->value,
        ];
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_EDIT) {
            $context = $this->getContext();
            $product = $context?->getEntity()?->getInstance();

            if ($product instanceof Product && $product->getSanitizedEggsCount() > 0) {
                $this->addFlash('warning', $this->translator->trans(
                    'pteroca.crud.product.eggs_auto_removed_warning',
                    ['%count%' => $product->getSanitizedEggsCount()]
                ));
            }
        }

        Product::registerVirtualField('healthStatus');

        $nests = $this->getNestsChoices();

        $uploadDirectory = str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->getParameter('products_directory'),
        );
        $internalCurrency = $this->settingService
            ->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);

        $fields = [
            FormField::addTab($this->translator->trans('pteroca.crud.product.details'))
                ->setIcon('fa fa-info-circle'),
            TextField::new('name', $this->translator->trans('pteroca.crud.product.name'))
                ->setColumns(6),
            AssociationField::new('category', $this->translator->trans('pteroca.crud.product.category'))
                ->setColumns(6),
            TextareaField::new('description', $this->translator->trans('pteroca.crud.product.description'))
                ->setColumns(6)
                ->hideOnIndex(),
            BooleanField::new('isActive', $this->translator->trans('pteroca.crud.product.is_active'))
                ->setColumns(6),
            NumberField::new('priority', $this->translator->trans('pteroca.crud.product.priority'))
                ->setHelp($this->translator->trans('pteroca.crud.product.priority_hint'))
                ->setColumns(6),
            FormField::addRow(),
            ImageField::new('imagePath', $this->translator->trans('pteroca.crud.product.image'))
                ->setBasePath($this->getParameter('products_base_path'))
                ->setUploadDir($uploadDirectory)
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false)
                ->setHelp($this->translator->trans('pteroca.crud.product.image_help'))
                ->setColumns(6),
            ImageField::new('bannerPath', $this->translator->trans('pteroca.crud.product.banner'))
                ->setBasePath($this->getParameter('products_base_path'))
                ->setUploadDir($uploadDirectory)
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false)
                ->setHelp($this->translator->trans('pteroca.crud.product.banner_help'))
                ->setColumns(6),
            $this->getProductHelpPanel(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.server_resources'))
                ->setIcon('fa fa-server'),
            NumberField::new('diskSpace', sprintf('%s (MB)', $this->translator->trans('pteroca.crud.product.disk_space')))
                ->setHelp($this->translator->trans('pteroca.crud.product.disk_space_hint'))
                ->setColumns(4),
            NumberField::new('memory', sprintf('%s (MB)', $this->translator->trans('pteroca.crud.product.memory')))
                ->setHelp($this->translator->trans('pteroca.crud.product.memory_hint'))
                ->setColumns(4),
            NumberField::new('swap', sprintf('%s (MB)', $this->translator->trans('pteroca.crud.product.swap')))
                ->setHelp($this->translator->trans('pteroca.crud.product.swap_hint'))
                ->setColumns(4),
            FormField::addRow(),
            NumberField::new('io', $this->translator->trans('pteroca.crud.product.io'))
                ->setHelp($this->translator->trans('pteroca.crud.product.io_hint'))
                ->setColumns(4),
            NumberField::new('cpu', sprintf('%s (%%)', $this->translator->trans('pteroca.crud.product.cpu')))
                ->setHelp($this->translator->trans('pteroca.crud.product.cpu_hint'))
                ->setColumns(4),
            TextField::new('threads', $this->translator->trans('pteroca.crud.product.threads'))
                ->hideOnIndex()
                ->setHelp($this->translator->trans('pteroca.crud.product.threads_hint'))
                ->setColumns(4)
                ->setRequired(false),
            FormField::addRow(),
            NumberField::new('dbCount', $this->translator->trans('pteroca.crud.product.db_count'))
                ->setHelp($this->translator->trans('pteroca.crud.product.db_count_hint'))
                ->setColumns(4)
                ->hideOnIndex(),
            NumberField::new('backups', $this->translator->trans('pteroca.crud.product.backups'))
                ->setHelp($this->translator->trans('pteroca.crud.product.backups_hint'))
                ->setColumns(4)
                ->hideOnIndex(),
            NumberField::new('ports', $this->translator->trans('pteroca.crud.product.ports'))
                ->setHelp($this->translator->trans('pteroca.crud.product.ports_hint'))
                ->setColumns(4),
            FormField::addRow(),
            NumberField::new('schedules', $this->translator->trans('pteroca.crud.product.schedules'))
                ->hideOnIndex()
                ->setHelp($this->translator->trans('pteroca.crud.product.schedules_hint'))
                ->setColumns(4),
            $this->getProductHelpPanel(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.pricing'))
                ->setIcon('fa fa-money'),
            CollectionField::new('staticPrices', sprintf('%s (%s)', $this->translator->trans('pteroca.crud.product.price_static_plan'), $internalCurrency))
                ->setEntryType(ProductPriceFixedFormType::class)
                ->allowAdd()
                ->allowDelete()
                ->onlyOnForms()
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.crud.product.price_static_plan_hint'))
                ->setRequired(true)
                ->setEntryIsComplex(),
            CollectionField::new('dynamicPrices', sprintf('%s (%s)', $this->translator->trans('pteroca.crud.product.price_dynamic_plan'), $internalCurrency))
                ->setEntryType(ProductPriceDynamicFormType::class)
                ->allowAdd()
                ->allowDelete()
                ->setSortable(true)
                ->onlyOnForms()
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.crud.product.price_dynamic_plan_hint') . $this->getExperimentalFeatureMessage())
                ->setRequired(true)
                ->setEntryIsComplex(),
            CollectionField::new('slotPrices', sprintf('%s (%s)', $this->translator->trans('pteroca.crud.product.price_slot_plan'), $internalCurrency))
                ->setEntryType(ProductPriceSlotFormType::class)
                ->allowAdd()
                ->allowDelete()
                ->onlyOnForms()
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.crud.product.price_slot_plan_hint'))
                ->setRequired(true)
                ->setEntryIsComplex(),
            $this->getProductHelpPanel(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.product_connections'))
                ->setIcon('fa fa-link'),
            ChoiceField::new('nodes', $this->translator->trans('pteroca.crud.product.nodes'))
                ->setHelp($this->translator->trans('pteroca.crud.product.nodes_hint'))
                ->setChoices(fn () => $this->getNodesChoices())
                ->allowMultipleChoices()
                ->setRequired(true)
                ->onlyOnForms()
                ->setColumns(6),
            ChoiceField::new('nest', $this->translator->trans('pteroca.crud.product.nest'))
                ->setHelp($this->translator->trans('pteroca.crud.product.nest_hint'))
                ->setChoices(fn () => $nests)
                ->onlyOnForms()
                ->setRequired(true)
                ->setFormTypeOption('attr', ['class' => 'nest-selector'])
                ->setColumns(6),
            HiddenField::new('eggsConfiguration')->onlyOnForms(),
            BooleanField::new('allowChangeEgg', $this->translator->trans('pteroca.crud.product.egg_allow_change'))
                ->setRequired(false)
                ->hideOnIndex(),
            ChoiceField::new('eggs', $this->translator->trans('pteroca.crud.product.eggs'))
                ->setHelp($this->translator->trans('pteroca.crud.product.eggs_hint'))
                ->setChoices(fn() => $this->getEggsChoices(array_values($nests)))
                ->allowMultipleChoices()
                ->onlyOnForms()
                ->setRequired(true)
                ->setFormTypeOption('attr', ['class' => 'egg-selector'])
                ->setColumns(12),
            Field::new('healthStatus', $this->translator->trans('pteroca.crud.product.health_status'))
                ->onlyOnIndex()
                ->setColumns(2)
                ->setSortable(false)
                ->formatValue(fn($value, Product $entity) =>
                    $this->productHealthStatusFormatter->getHealthBadgeHtml($entity, $this->translator)
                ),
            DateTimeField::new('createdAt', $this->translator->trans('pteroca.crud.product.created_at'))->onlyOnDetail(),
            DateTimeField::new('updatedAt', $this->translator->trans('pteroca.crud.product.updated_at'))->onlyOnDetail(),
            DateTimeField::new('deletedAt', $this->translator->trans('pteroca.crud.product.deleted_at'))->onlyOnDetail(),
            $this->getProductHelpPanel(),
        ];

        if (!empty($this->flashMessages)) {
            $flashMessages = implode(PHP_EOL, $this->flashMessages);
            $this->addFlash('danger', $flashMessages);
        }

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        $copyAction = Action::new('copyProduct', $this->translator->trans('pteroca.crud.product.copy'))
            ->linkToCrudAction('copyProduct')
            ->setCssClass('action-copy-product')
            ->setIcon('fa fa-copy')
            ->displayIf(fn (Product $entity) =>
                $this->getUser()?->hasPermission(PermissionEnum::COPY_PRODUCT) &&
                empty($entity->getDeletedAt())
            );

        $actions = $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.product.add')))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.product.add')))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.product.save')))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action->displayIf(
                fn (Product $entity) =>
                    $this->getUser()?->hasPermission(PermissionEnum::EDIT_PRODUCT) &&
                    empty($entity->getDeletedAt())
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => $action->displayIf(
                fn (Product $entity) =>
                    $this->getUser()?->hasPermission(PermissionEnum::DELETE_PRODUCT) &&
                    empty($entity->getDeletedAt())
            ))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $copyAction)
            ->reorder(Crud::PAGE_INDEX, [Action::EDIT, Action::DETAIL, 'copyProduct', Action::DELETE])
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::PRODUCT->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.product.product'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.product.products'))
            ->setDefaultSort(['priority' => 'ASC', 'createdAt' => 'DESC'])
        ;

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('name')
            ->add('priority')
            ->add('description')
            ->add('isActive')
            ->add('category')
            ->add('diskSpace')
            ->add('memory')
            ->add('io')
            ->add('cpu')
            ->add('dbCount')
            ->add('swap')
            ->add('backups')
            ->add('ports')
            ->add('schedules')
            ->add('threads')
            ->add('allowChangeEgg')
            ->add('createdAt')
            ->add('updatedAt')
            ->add('deletedAt')
        ;
        return parent::configureFilters($filters);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Product) {
            $entityInstance->setEggsConfiguration(json_encode($this->getEggsConfigurationFromRequest()));
            $entityInstance->setCreatedAtValue();
            $entityInstance->setUpdatedAtValue();

            $this->validateProductEggs($entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Product) {
            $entityInstance->setEggsConfiguration(json_encode($this->getEggsConfigurationFromRequest()));
            $entityInstance->setUpdatedAtValue();

            $this->validateProductEggs($entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Product) {
            $entityInstance->setDeletedAtValue();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function copyProduct(AdminContext $context): RedirectResponse
    {
        /** @var Product $originalProduct */
        $originalProduct = $context->getEntity()->getInstance();

        $user = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();
        $eventContext = $this->buildMinimalEventContext($request);

        $copiedProduct = $this->productCopyService->copyProduct(
            $originalProduct,
            $user->getId(),
            $eventContext
        );

        $this->addFlash('success', $this->translator->trans('pteroca.crud.product.copy_success'));

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($copiedProduct->getId())
            ->generateUrl();

        return new RedirectResponse($url);
    }

    private function validateProductEggs(Product $product): void
    {
        $this->nestEggsCacheService->validateProductEggs(
            $product,
            $this->translator->trans('pteroca.crud.product.no_eggs_selected'),
            $this->translator->trans('pteroca.crud.product.invalid_eggs_selected'),
            $this->translator->trans('pteroca.crud.product.egg_validation_error')
        );
    }
}
