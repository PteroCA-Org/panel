<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\ServerProduct;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Form\ServerProductPriceDynamicFormType;
use App\Core\Form\ServerProductPriceFixedFormType;
use App\Core\Form\ServerProductPriceSlotFormType;
use App\Core\Repository\ServerProductRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Product\NestEggsCacheService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylClientService;
use App\Core\Service\Pterodactyl\PterodactylRedirectService;
use App\Core\Service\Pterodactyl\PterodactylService;
use App\Core\Service\Server\DeleteServerService;
use App\Core\Service\Server\UpdateServerService;
use App\Core\Service\SettingService;
use App\Core\Trait\CrudFlashMessagesTrait;
use App\Core\Trait\ExperimentalFeatureMessageTrait;
use App\Core\Trait\ManageServerActionTrait;
use App\Core\Trait\ProductCrudControllerTrait;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\HiddenField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class ServerProductCrudController extends AbstractPanelController
{
    use ProductCrudControllerTrait;
    use ManageServerActionTrait;
    use CrudFlashMessagesTrait;
    use ExperimentalFeatureMessageTrait;

    private bool $isServerOffline = false;

    public function __construct(
        PanelCrudService $panelCrudService,
        private readonly RequestStack $requestStack,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly UpdateServerService $updateServerService,
        private readonly SettingService $settingService,
        private readonly ServerProductRepository $serverProductRepository,
        private readonly DeleteServerService $deleteServerService,
        private readonly TranslatorInterface $translator,
        private readonly PterodactylRedirectService $pterodactylRedirectService,
        private readonly NestEggsCacheService $nestEggsCacheService,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return ServerProduct::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $nests = $this->getNestsChoices();
        $internalCurrency = $this->settingService
            ->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);

        return [
            FormField::addTab($this->translator->trans('pteroca.crud.product.server_details'))
                ->setIcon('fa fa-info-circle'),
            IdField::new('server.id')
                ->hideOnForm()
                ->setColumns(3),
            IntegerField::new('server.pterodactylServerId', $this->translator->trans('pteroca.crud.server.pterodactyl_server_id'))
                ->setDisabled()
                ->setColumns(3),
            TextField::new('server.pterodactylServerIdentifier', $this->translator->trans('pteroca.crud.server.pterodactyl_server_identifier'))
                ->setDisabled()
                ->setColumns(3),
            TextField::new('server.user', $this->translator->trans('pteroca.crud.server.user'))
                ->setDisabled()
                ->setColumns(3),
            TextField::new('server.name', $this->translator->trans('pteroca.crud.server.name'))
                ->setDisabled(),
            DateTimeField::new('server.createdAt', $this->translator->trans('pteroca.crud.server.created_at'))
                ->setDisabled()
                ->setColumns(3),
            DateTimeField::new('server.deletedAt', $this->translator->trans('pteroca.crud.server.deleted_at'))
                ->setDisabled()
                ->setColumns(3),
            DateTimeField::new('server.expiresAt', $this->translator->trans('pteroca.crud.server.expires_at'))
                ->setColumns(3),
            BooleanField::new('server.isSuspended', $this->translator->trans('pteroca.crud.server.is_suspended'))
                ->setColumns(3),
            BooleanField::new('server.autoRenewal', $this->translator->trans('pteroca.crud.server.auto_renewal'))
                ->setColumns(3)
                ->hideOnIndex(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.build_details'))
                ->setIcon('fa fa-asterisk'),
            TextField::new('name', $this->translator->trans('pteroca.crud.product.build_name'))
                ->setColumns(7),
            FormField::addRow(),
            AssociationField::new('originalProduct', $this->translator->trans('pteroca.crud.product.original_product'))
                ->setColumns(7)
                ->setDisabled(),
            AssociationField::new('server', $this->translator->trans('pteroca.crud.product.server'))
                ->setColumns(5)
                ->setDisabled(),

            ...$this->getServerBuildFields(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.pricing'))
                ->setIcon('fa fa-money'),
            CollectionField::new('staticPrices', sprintf('%s (%s)', $this->translator->trans('pteroca.crud.product.price_static_plan'), $internalCurrency))
                ->setEntryType(ServerProductPriceFixedFormType::class)
                ->allowAdd()
                ->allowDelete()
                ->onlyOnForms()
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.crud.product.price_static_plan_hint'))
                ->setRequired(true)
                ->setEntryIsComplex(),
            CollectionField::new('dynamicPrices', sprintf('%s (%s)', $this->translator->trans('pteroca.crud.product.price_dynamic_plan'), $internalCurrency))
                ->setEntryType(ServerProductPriceDynamicFormType::class)
                ->allowAdd()
                ->allowDelete()
                ->setSortable(true)
                ->onlyOnForms()
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.crud.product.price_dynamic_plan_hint') . $this->getExperimentalFeatureMessage())
                ->setRequired(true)
                ->setEntryIsComplex(),
            CollectionField::new('slotPrices', sprintf('%s (%s)', $this->translator->trans('pteroca.crud.product.price_slot_plan'), $internalCurrency))
                ->setEntryType(ServerProductPriceSlotFormType::class)
                ->allowAdd()
                ->allowDelete()
                ->onlyOnForms()
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.crud.product.price_slot_plan_hint'))
                ->setRequired(true)
                ->setEntryIsComplex(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.product_connections'))
                ->setIcon('fa fa-link'),
            FormField::addPanel(sprintf(
                '<i class="fa fa-info-circle pe-2"></i> %s',
                $this->translator->trans('pteroca.crud.product.product_connections_note'),
            ))
                ->addCssClass('alert alert-secondary')
                ->onlyOnForms(),
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
            HiddenField::new('eggsConfiguration')
                ->onlyOnForms(),
            BooleanField::new('allowChangeEgg', $this->translator->trans('pteroca.crud.product.egg_allow_change'))
                ->setRequired(false)
                ->setColumns(6)
                ->hideOnIndex(),
            ChoiceField::new('eggs', $this->translator->trans('pteroca.crud.product.eggs'))
                ->setHelp($this->translator->trans('pteroca.crud.product.eggs_hint'))
                ->setChoices(fn() => $this->getEggsChoices($nests))
                ->allowMultipleChoices()
                ->onlyOnForms()
                ->setRequired(true)
                ->setFormTypeOption('attr', ['class' => 'egg-selector'])
                ->setColumns(12),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->disable(Crud::PAGE_INDEX)
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->remove(Crud::PAGE_DETAIL, Action::DELETE)
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            ->update(
                Crud::PAGE_DETAIL,
                Action::EDIT,
                fn (Action $action) => $action->displayIf(
                    fn (ServerProduct $entity) => empty($entity->getServer()->getDeletedAt())
                )
            )
            ->update(
                Crud::PAGE_INDEX,
                Action::DELETE,
                fn (Action $action) => $action->displayIf(
                    fn (ServerProduct $entity) => empty($entity->getServer()->getDeletedAt())
                )
            )
            ->add(Crud::PAGE_EDIT, $this->getServerAction(Crud::PAGE_EDIT))
            ->add(Crud::PAGE_EDIT, $this->getManageServerAction())
            ->add(Crud::PAGE_DETAIL, $this->getManageServerAction())
            ->add(Crud::PAGE_EDIT, $this->getShowServerLogsAction());

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SERVER_PRODUCT->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.product.server_build'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.product.server_builds'))
            ->setSearchFields(null)
        ;

        return parent::configureCrud($crud);
    }

    public function edit(AdminContext $context): KeyValueStore|RedirectResponse|Response
    {
        try {
            $entityId = $context->getRequest()->get('entityId');
            $serverProduct = $this->serverProductRepository->find($entityId);
            $pterodactylServerResources = $this->pterodactylApplicationService
                ->getClientApi($serverProduct->getServer()->getUser())
                ->servers()
                ->getServerResources($serverProduct->getServer()->getPterodactylServerIdentifier());
            $this->isServerOffline = $pterodactylServerResources['current_state'] !== 'running';
        } catch (Exception) {
            $this->isServerOffline = true;
        }

        return parent::edit($context);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ServerProduct) {
            $entityInstance->setEggsConfiguration(json_encode($this->getEggsConfigurationFromRequest()));
        }

        $this->setFlashMessages(
            $this->updateServerService
                ->updateServer($entityInstance)
                ->getMessages()
        );

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ServerProduct) {
            $entityInstance->setEggsConfiguration(json_encode($this->getEggsConfigurationFromRequest()));
        }

        $this->setFlashMessages(
            $this->updateServerService
                ->updateServer($entityInstance)
                ->getMessages()
        );

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ServerProduct) {
            $this->deleteServerService->deleteServer($entityInstance->getServer());
            $entityInstance->getServer()->setDeletedAtValue();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        return $this->redirect($this->generateUrl('panel', [
            'crudControllerFqcn' => ServerCrudController::class,
            'crudAction' => 'index',
        ]));
    }

    private function getServerBuildFields(): array
    {
        $panelFieldLabel = sprintf(
            '<i class="fa fa-info-circle pe-2"></i> %s',
            $this->translator->trans('pteroca.crud.product.server_build_offline_alert')
        );

        $panelField = FormField::addPanel($panelFieldLabel)
            ->addCssClass('alert alert-danger')
            ->onlyOnForms();

        if (!$this->isServerOffline) {
            $panelField->hideOnForm();
        }

        return [
            FormField::addTab($this->translator->trans('pteroca.crud.product.server_resources'))
                ->setIcon('fa fa-server')
                ->setDisabled($this->isServerOffline),
            $panelField,
            NumberField::new('diskSpace', sprintf('%s (MB)', $this->translator->trans('pteroca.crud.product.disk_space')))
                ->setHelp($this->translator->trans('pteroca.crud.product.disk_space_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('memory', sprintf('%s (MB)', $this->translator->trans('pteroca.crud.product.memory')))
                ->setHelp($this->translator->trans('pteroca.crud.product.memory_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('swap', sprintf('%s (MB)', $this->translator->trans('pteroca.crud.product.swap')))
                ->setHelp($this->translator->trans('pteroca.crud.product.swap_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            FormField::addRow(),
            NumberField::new('io', $this->translator->trans('pteroca.crud.product.io'))
                ->setHelp($this->translator->trans('pteroca.crud.product.io_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('cpu', sprintf('%s (%%)', $this->translator->trans('pteroca.crud.product.cpu')))
                ->setHelp($this->translator->trans('pteroca.crud.product.cpu_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            TextField::new('threads', $this->translator->trans('pteroca.crud.product.threads'))
                ->setHelp($this->translator->trans('pteroca.crud.product.threads_hint'))
                ->setColumns(4)
                ->setRequired(false)
                ->setDisabled($this->isServerOffline),
            FormField::addRow(),
            NumberField::new('dbCount', $this->translator->trans('pteroca.crud.product.db_count'))
                ->setHelp($this->translator->trans('pteroca.crud.product.db_count_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('backups', $this->translator->trans('pteroca.crud.product.backups'))
                ->setHelp($this->translator->trans('pteroca.crud.product.backups_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('ports', $this->translator->trans('pteroca.crud.product.ports'))
                ->setHelp($this->translator->trans('pteroca.crud.product.ports_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            FormField::addRow(),
            NumberField::new('schedules', $this->translator->trans('pteroca.crud.product.schedules'))
                ->setHelp($this->translator->trans('pteroca.crud.product.schedules_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
        ];
    }
}
