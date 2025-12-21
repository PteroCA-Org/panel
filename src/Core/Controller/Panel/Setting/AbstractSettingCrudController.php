<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Controller\Panel\AbstractPanelController;
use App\Core\Entity\Setting;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingContextEnum;
use App\Core\Enum\SettingTypeEnum;
use App\Core\Repository\SettingRepository;
use App\Core\Repository\SettingOptionRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\LocaleService;
use App\Core\Service\SettingService;
use App\Core\Service\SettingTypeMapperService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractSettingCrudController extends AbstractPanelController
{
    protected bool $useConventionBasedPermissions = false;

    protected ?Setting $currentEntity = null;

    abstract protected function getSettingContext(): SettingContextEnum;

    public function __construct(
        PanelCrudService $panelCrudService,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly SettingRepository $settingRepository,
        private readonly SettingOptionRepository $settingOptionRepository,
        private readonly SettingService $settingService,
        private readonly LocaleService $localeService,
        private readonly SettingTypeMapperService $typeMapper,
    ) {
        parent::__construct($panelCrudService, $requestStack);
        $this->currentEntity = $this->getSettingEntity();
    }

    public static function getEntityFqcn(): string
    {
        return Setting::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            TextField::new('name', $this->translator->trans('pteroca.crud.setting.name'))
                ->setDisabled($pageName === Crud::PAGE_EDIT)
                ->formatValue(function ($value) {
                    $hintIndex = "pteroca.crud.setting.hints.$value";
                    $hint = $this->translator->trans($hintIndex);
                    if ($hint !== $hintIndex) {
                        return sprintf("%s<br><small>(%s)</small>", $hint, $value);
                    }
                    return $value;
                })
                ->setHelp($this->getHelpText($this->currentEntity?->getName()))
                ->setColumns(6),
            ChoiceField::new('type', $this->translator->trans('pteroca.crud.setting.type'))
                ->setChoices(SettingTypeEnum::getValues())
                ->setDisabled()
                ->setColumns(6)
                ->hideOnIndex(),
        ];

        $valueLabel = $this->translator->trans('pteroca.crud.setting.value');
        $normalizedType = $this->typeMapper->toDisplayType($this->currentEntity?->getType() ?? 'text');

        if ($pageName === Crud::PAGE_INDEX) {
            $valueField = TextareaField::new('value', $valueLabel)
                ->formatValue(function ($value, $entity) {
                    if (!$entity) {
                        return $value;
                    }
                    return match ($entity->getType()) {
                        SettingTypeEnum::SECRET->value => '********',
                        SettingTypeEnum::BOOLEAN->value => $value
                            ? $this->translator->trans('pteroca.crud.setting.yes')
                            : $this->translator->trans('pteroca.crud.setting.no'),
                        default => $value,
                    };
                });
        } else {
            $valueField = match ($normalizedType) {
                SettingTypeEnum::COLOR->value => TextField::new('value', $valueLabel)
                    ->setFormType(ColorType::class),
                SettingTypeEnum::BOOLEAN->value => ChoiceField::new('value', $valueLabel)
                    ->setChoices([
                        $this->translator->trans('pteroca.crud.setting.yes') => '1',
                        $this->translator->trans('pteroca.crud.setting.no') => '0',
                    ])
                    ->formatValue(fn ($value) => $value ? '1' : '0'),
                SettingTypeEnum::NUMBER->value => NumberField::new('value', $valueLabel),
                SettingTypeEnum::TEXT->value => TextField::new('value', $valueLabel),
                SettingTypeEnum::TWIG->value => CodeEditorField::new('value', $valueLabel)
                    ->setLanguage('twig')
                    ->setNumOfRows(20),
                SettingTypeEnum::LOCALE->value => ChoiceField::new('value', $valueLabel)
                    ->setChoices(array_flip($this->localeService->getAvailableLocales(false))),
                SettingTypeEnum::URL->value => UrlField::new('value', $valueLabel),
                SettingTypeEnum::EMAIL->value => EmailField::new('value', $valueLabel),
                SettingTypeEnum::IMAGE->value => ImageField::new('value', $valueLabel)
                    ->setUploadDir('public/uploads/settings')
                    ->setBasePath('/uploads/settings')
                    ->setUploadedFileNamePattern('[randomhash].[extension]'),
                SettingTypeEnum::SELECT->value => ChoiceField::new('value', $valueLabel)
                    ->setChoices($this->getSelectOptions($this->currentEntity?->getName())),
                default => TextareaField::new('value', $valueLabel)
                    ->formatValue(function ($value, $entity) {
                        if (!$entity) {
                            return $value;
                        }
                        return match ($entity->getType()) {
                            SettingTypeEnum::BOOLEAN->value => $value
                                ? $this->translator->trans('pteroca.crud.setting.yes')
                                : $this->translator->trans('pteroca.crud.setting.no'),
                            default => $value,
                        };
                    }),
            };
        }

        $isNullable = $this->currentEntity?->isNullable() ?? false;

        $valueField
            ->setRequired(!$isNullable)
            ->setColumns(6);
        $fields[] = $valueField;

        if ($isNullable) {
            $isCurrentlyEmpty = $this->currentEntity?->getValue() === null;
            $fields[] = BooleanField::new('setAsEmpty', $this->translator->trans('pteroca.crud.setting.set_as_empty'))
                ->setHelp($this->translator->trans('pteroca.crud.setting.set_as_empty_help'))
                ->setColumns(6)
                ->setFormTypeOption('mapped', false)
                ->setFormTypeOption('data', $isCurrentlyEmpty)
                ->hideOnIndex();
        }

        $fields[] = ChoiceField::new('context', $this->translator->trans('pteroca.crud.setting.context'))
                ->setChoices(SettingContextEnum::getValues())
                ->setRequired(true)
                ->setColumns(6)
                ->hideOnIndex()
                ->hideOnForm();
        $fields[] = NumberField::new('hierarchy', $this->translator->trans('pteroca.crud.setting.hierarchy'))
                ->setRequired(true)
                ->setColumns(6)
                ->hideOnIndex()
                ->hideOnForm();

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.setting.add')))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.setting.add')))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.setting.save')))
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->remove(Crud::PAGE_INDEX, Action::DELETE)
            ->remove(Crud::PAGE_INDEX, Action::NEW);

        $actions = parent::configureActions($actions);

        // Settings use custom permission mapping
        $actions = $this->applyConventionBasedPermissions($actions);
        $actions = $this->applyPermissionBasedVisibility($actions);

        return $actions;
    }

    protected function getPermissionMapping(): array
    {
        $context = $this->getSettingContext();

        $accessPermission = match($context) {
            SettingContextEnum::GENERAL => PermissionEnum::ACCESS_SETTINGS_GENERAL->value,
            SettingContextEnum::PTERODACTYL => PermissionEnum::ACCESS_SETTINGS_PTERODACTYL->value,
            SettingContextEnum::SECURITY => PermissionEnum::ACCESS_SETTINGS_SECURITY->value,
            SettingContextEnum::PAYMENT => PermissionEnum::ACCESS_SETTINGS_PAYMENT->value,
            SettingContextEnum::EMAIL => PermissionEnum::ACCESS_SETTINGS_EMAIL->value,
            SettingContextEnum::THEME => PermissionEnum::ACCESS_SETTINGS_THEME->value,
            SettingContextEnum::PLUGIN => PermissionEnum::ACCESS_SETTINGS_PLUGIN->value,
        };

        $editPermission = match($context) {
            SettingContextEnum::GENERAL => PermissionEnum::EDIT_SETTINGS_GENERAL->value,
            SettingContextEnum::PTERODACTYL => PermissionEnum::EDIT_SETTINGS_PTERODACTYL->value,
            SettingContextEnum::SECURITY => PermissionEnum::EDIT_SETTINGS_SECURITY->value,
            SettingContextEnum::PAYMENT => PermissionEnum::EDIT_SETTINGS_PAYMENT->value,
            SettingContextEnum::EMAIL => PermissionEnum::EDIT_SETTINGS_EMAIL->value,
            SettingContextEnum::THEME => PermissionEnum::EDIT_SETTINGS_THEME->value,
            SettingContextEnum::PLUGIN => PermissionEnum::EDIT_SETTINGS_PLUGIN->value,
        };

        return [
            Action::INDEX  => $accessPermission,  // Viewing list - requires access
            Action::DETAIL => $accessPermission,  // Viewing details (not used in UI)
            Action::NEW    => $editPermission,    // Creating (removed from UI, but map correctly)
            Action::EDIT   => $editPermission,    // Editing settings - requires edit permission
            Action::DELETE => $editPermission,    // Deleting (removed from UI, but map correctly)
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        $context = $this->getSettingContext();
        $contextLabel = ucfirst(strtolower($context->name));
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SETTING->value);
        if (!empty($this->currentEntity)) {
            $this->appendCrudTemplateContext($this->currentEntity->getName());
        }

        $contextName = strtolower($context->name);
        $entityPermission = 'access_settings_' . $contextName;

        $crud
            ->setEntityLabelInSingular(sprintf('%s %s', $contextLabel, $this->translator->trans('pteroca.crud.setting.setting')))
            ->setEntityLabelInPlural(sprintf('%s %s', $contextLabel, $this->translator->trans('pteroca.crud.setting.settings')))
            ->setEntityPermission($entityPermission);

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('name')
            ->add('value')
            ->add('type')
            ->add('context')
            ->add('hierarchy')
        ;

        return parent::configureFilters($filters);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleSetAsEmpty($entityInstance);
        $this->settingService->saveSettingInCache($entityInstance->getName(), $entityInstance->getValue());
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleSetAsEmpty($entityInstance);
        $this->settingService->saveSettingInCache($entityInstance->getName(), $entityInstance->getValue());
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->settingService->deleteSettingFromCache($entityInstance->getName());
        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $context = $this->getSettingContext();
        $qb->andWhere('entity.context = :context')
            ->orderBy('entity.hierarchy', 'ASC')
            ->setParameter('context', $context->value);

        return $qb;
    }

    private function handleSetAsEmpty(Setting $setting): void
    {
        if (!$setting->isNullable()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $formData = $request->request->all();

        $setAsEmpty = $formData['Setting']['setAsEmpty'] ?? false;

        if ($setAsEmpty) {
            $setting->setValue(null);
        }
    }

    private function getSettingEntity(): ?Setting
    {
        $request = $this->requestStack->getCurrentRequest();
        $crudAction = $request->query->get('crudAction');
        if ($crudAction === 'index') {
            return null;
        }

        $id = $request->query->get('entityId');
        if ($id) {
            return $this->settingRepository->find($id);
        }
        return null;
    }

    private function getHelpText(?string $name): string
    {
        if (empty ($name)) {
            return '';
        }

        $hintIndex = "pteroca.crud.setting.hints.$name";
        $hint = $this->translator->trans($hintIndex);

        return $hint !== $hintIndex ? $hint : '';
    }

    private function getSelectOptions(?string $settingName): array
    {
        if (empty($settingName)) {
            return [];
        }

        return $this->settingOptionRepository->getOptionsForSetting($settingName);
    }
}
