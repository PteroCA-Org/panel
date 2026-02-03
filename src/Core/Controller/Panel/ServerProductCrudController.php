<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\Product;
use App\Core\Entity\ServerProduct;
use App\Core\Entity\User;
use App\Core\Repository\ProductRepository;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Form\ServerProductPriceDynamicFormType;
use App\Core\Form\ServerProductPriceFixedFormType;
use App\Core\Form\ServerProductPriceSlotFormType;
use App\Core\Repository\ServerProductRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Product\NestEggsCacheService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylRedirectService;
use App\Core\Service\Server\AdminServerCreationService;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\HiddenField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use DateTime;
use Exception;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
        RequestStack $requestStack,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly UpdateServerService $updateServerService,
        private readonly SettingService $settingService,
        private readonly ServerProductRepository $serverProductRepository,
        private readonly DeleteServerService $deleteServerService,
        private readonly TranslatorInterface $translator,
        private readonly PterodactylRedirectService $pterodactylRedirectService,
        private readonly NestEggsCacheService $nestEggsCacheService,
        private readonly UserRepository $userRepository,
        private readonly ProductRepository $productRepository,
        private readonly AdminServerCreationService $adminServerCreationService,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return ServerProduct::class;
    }

    protected function getPermissionMapping(): array
    {
        $mapping = parent::getPermissionMapping();
        $mapping[Action::NEW] = PermissionEnum::CREATE_SERVER;
        return $mapping;
    }

    public function configureFields(string $pageName): iterable
    {
        $isNewPage = ($pageName === Crud::PAGE_NEW);

        $nests = $this->getNestsChoices();

        $internalCurrency = $this->settingService
            ->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);

        $productChoices = [];
        if ($isNewPage) {
            $products = $this->productRepository
                ->createQueryBuilder('p')
                ->where('p.deletedAt IS NULL')
                ->andWhere('p.isActive = true')
                ->orderBy('p.name', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($products as $product) {
                $productChoices[$product->getName()] = $product->getId();
            }
        }

        return [
            FormField::addTab($this->translator->trans('pteroca.crud.product.server_details'))
                ->setIcon('fa fa-info-circle'),

            Field::new('user', $this->translator->trans('pteroca.crud.server.user'))
                ->setFormType(EntityType::class)
                ->setFormTypeOptions([
                    'class' => User::class,
                    'choice_label' => 'email',
                    'query_builder' => function ($repository) {
                        return $repository->createQueryBuilder('u')
                            ->where('u.deletedAt IS NULL')
                            ->orderBy('u.email', 'ASC');
                    },
                    'mapped' => false,
                ])
                ->onlyOnForms()
                ->setRequired(true)
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.admin.server_create.select_user_help'))
                ->hideWhenUpdating(),

            ChoiceField::new('baseProduct', $this->translator->trans('pteroca.crud.server.base_product'))
                ->onlyOnForms()
                ->setChoices($productChoices)
                ->setRequired(false)
                ->setColumns(6)
                ->setHelp($this->translator->trans('pteroca.admin.server_create.base_product_help'))
                ->setFormTypeOption('mapped', false)
                ->hideWhenUpdating(),

            BooleanField::new('freeServer', $this->translator->trans('pteroca.admin.server_create.free_server'))
                ->onlyOnForms()
                ->setColumns(12)
                ->setHelp($this->translator->trans('pteroca.admin.server_create.free_server_help'))
                ->setFormTypeOption('mapped', false)
                ->hideWhenUpdating(),

            IdField::new('server.id')
                ->hideOnForm()
                ->setColumns(3),
            IntegerField::new('server.pterodactylServerId', $this->translator->trans('pteroca.crud.server.pterodactyl_server_id'))
                ->setDisabled()
                ->hideWhenCreating()
                ->setColumns(3),
            TextField::new('server.pterodactylServerIdentifier', $this->translator->trans('pteroca.crud.server.pterodactyl_server_identifier'))
                ->setDisabled()
                ->hideWhenCreating()
                ->setColumns(3),
            TextField::new('server.user', $this->translator->trans('pteroca.crud.server.user'))
                ->setDisabled()
                ->hideWhenCreating()
                ->setColumns(3),
            TextField::new($isNewPage ? 'newServerName' : 'server.name', $this->translator->trans('pteroca.crud.server.name'))
                ->setRequired(true)
                ->setColumns(12)
                ->setFormTypeOptions($isNewPage ? ['mapped' => false] : []),

            DateTimeField::new('server.createdAt', $this->translator->trans('pteroca.crud.server.created_at'))
                ->setDisabled()
                ->hideWhenCreating()
                ->setColumns(3),
            DateTimeField::new('server.deletedAt', $this->translator->trans('pteroca.crud.server.deleted_at'))
                ->setDisabled()
                ->hideWhenCreating()
                ->setColumns(3),

            DateTimeField::new($isNewPage ? 'newServerExpiresAt' : 'server.expiresAt', $this->translator->trans('pteroca.crud.server.expires_at'))
                ->setRequired(true)
                ->setColumns(3)
                ->setFormTypeOptions($isNewPage ? ['mapped' => false] : []),

            BooleanField::new($isNewPage ? 'newServerIsSuspended' : 'server.isSuspended', $this->translator->trans('pteroca.crud.server.is_suspended'))
                ->setColumns(3)
                ->setFormTypeOptions($isNewPage ? ['mapped' => false] : []),

            BooleanField::new($isNewPage ? 'newServerAutoRenewal' : 'server.autoRenewal', $this->translator->trans('pteroca.crud.server.auto_renewal'))
                ->setColumns(3)
                ->setFormTypeOptions($isNewPage ? ['mapped' => false] : [])
                ->hideOnIndex(),

            FormField::addTab($this->translator->trans('pteroca.crud.product.build_details'))
                ->setIcon('fa fa-asterisk'),
            TextField::new('name', $this->translator->trans('pteroca.crud.product.build_name'))
                ->setColumns(7),
            FormField::addRow(),
            AssociationField::new('originalProduct', $this->translator->trans('pteroca.crud.product.original_product'))
                ->setColumns(7)
                ->setDisabled()
                ->hideWhenCreating(),
            AssociationField::new('server', $this->translator->trans('pteroca.crud.product.server'))
                ->setColumns(5)
                ->setDisabled()
                ->hideWhenCreating(),

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
            BooleanField::new('allowAutoRenewal', $this->translator->trans('pteroca.crud.product.allow_auto_renewal'))
                ->setHelp($this->translator->trans('pteroca.crud.product.allow_auto_renewal_hint'))
                ->setRequired(false)
                ->setColumns(6)
                ->hideOnIndex(),
            BooleanField::new('allowUserSelectLocation', $this->translator->trans('pteroca.crud.product.allow_user_select_location'))
                ->setHelp($this->translator->trans('pteroca.crud.product.allow_user_select_location_hint'))
                ->setDisabled()
                ->setColumns(6)
                ->hideOnIndex()
                ->hideWhenCreating(),
            NumberField::new('selectedNodeId', $this->translator->trans('pteroca.crud.server_product.selected_node_id'))
                ->setDisabled()
                ->setColumns(6)
                ->hideOnIndex()
                ->hideWhenCreating(),
            ChoiceField::new('eggs', $this->translator->trans('pteroca.crud.product.eggs'))
                ->setHelp($this->translator->trans('pteroca.crud.product.eggs_hint'))
                ->setChoices(fn() => $this->getEggsChoices(array_values($nests)))
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
            ->add(Crud::PAGE_EDIT, $this->getShowServerLogsAction())
            ->add(Crud::PAGE_EDIT, $this->getShowServerInPterodactylAction())
            ->add(Crud::PAGE_DETAIL, $this->getShowServerInPterodactylAction());

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
        if (!$entityInstance instanceof ServerProduct) {
            parent::persistEntity($entityManager, $entityInstance);
            return;
        }

        $this->createNewServer($entityInstance);
    }

    private function createNewServer(ServerProduct $serverProduct): void
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            $formData = $request->request->all()['ServerProduct'] ?? [];

            $user = $this->loadUserFromForm($formData);

            $baseProduct = $this->loadBaseProductFromForm($formData);
            if ($baseProduct) {
                $serverProduct->setOriginalProduct($baseProduct);
            }

            $serverProduct->setEggsConfiguration(json_encode($this->getEggsConfigurationFromRequest()));

            $startingEggId = $this->extractStartingEggId($formData);

            $this->adminServerCreationService->createServerForUser(
                user: $user,
                serverProduct: $serverProduct,
                serverName: $formData['newServerName'] ?? 'New Server',
                expiresAt: $this->parseExpiresAt($formData),
                autoRenewal: isset($formData['newServerAutoRenewal']) && $formData['newServerAutoRenewal'],
                isSuspended: isset($formData['newServerIsSuspended']) && $formData['newServerIsSuspended'],
                eggId: $startingEggId,
                freeServer: isset($formData['freeServer']) && $formData['freeServer'],
                createdByAdmin: $this->getUser()
            );

            $this->addFlash('success', $this->translator->trans(
                'pteroca.admin.server_create.success',
                ['%user%' => $user->getEmail()]
            ));
        } catch (Exception $e) {
            $this->addFlash('danger', $e->getMessage());
            throw $e;
        }
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

    private function loadUserFromForm(array $formData): User
    {
        $userId = $formData['user'] ?? null;
        $user = $this->userRepository->find($userId);

        if (!$user) {
            throw new Exception($this->translator->trans('pteroca.error.user_not_found'));
        }

        return $user;
    }

    private function loadBaseProductFromForm(array $formData): ?Product
    {
        $baseProductId = $formData['baseProduct'] ?? null;

        if (!$baseProductId) {
            return null;
        }

        return $this->productRepository->find($baseProductId);
    }

    private function parseExpiresAt(array $formData): DateTime
    {
        $expiresAtString = $formData['newServerExpiresAt'] ?? null;
        return $expiresAtString ? new DateTime($expiresAtString) : new DateTime('+30 days');
    }

    private function extractStartingEggId(array $formData): int
    {
        $request = $this->requestStack->getCurrentRequest();
        $allRequestData = $request->request->all();
        $startingEggId = $allRequestData['starting_egg_id'] ?? null;

        if ($startingEggId !== null) {
            return (int) $startingEggId;
        }

        $eggs = $formData['eggs'] ?? [];

        if (empty($eggs)) {
            throw new Exception($this->translator->trans('pteroca.admin.server_create.eggs_required'));
        }

        return (int) $eggs[0];
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
            NumberField::new('diskSpace', sprintf('%s (MiB)', $this->translator->trans('pteroca.crud.product.disk_space')))
                ->setHelp($this->translator->trans('pteroca.crud.product.disk_space_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('memory', sprintf('%s (MiB)', $this->translator->trans('pteroca.crud.product.memory')))
                ->setHelp($this->translator->trans('pteroca.crud.product.memory_hint'))
                ->setColumns(4)
                ->setDisabled($this->isServerOffline),
            NumberField::new('swap', sprintf('%s (MiB)', $this->translator->trans('pteroca.crud.product.swap')))
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
