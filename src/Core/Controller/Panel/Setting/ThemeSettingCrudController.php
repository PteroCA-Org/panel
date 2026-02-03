<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\DTO\TemplateOptionsDTO;
use App\Core\Enum\SettingContextEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Repository\SettingRepository;
use App\Core\Repository\SettingOptionRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\LocaleService;
use App\Core\Service\SettingService;
use App\Core\Service\SettingTypeMapperService;
use App\Core\Service\Template\TemplateManager;
use App\Core\Service\Template\TemplateService;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class ThemeSettingCrudController extends AbstractSettingCrudController
{
    private TemplateOptionsDTO $currentTemplateOptions;

    private bool $disableDarkMode;

    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        SettingRepository $settingRepository,
        SettingOptionRepository $settingOptionRepository,
        SettingService $settingService,
        LocaleService $localeService,
        SettingTypeMapperService $typeMapper,
        private readonly TemplateService $templateService,
        private readonly TemplateManager $templateManager,
        private readonly TranslatorInterface $translator,
    )
    {
        parent::__construct(
            $panelCrudService,
            $requestStack,
            $translator,
            $settingRepository,
            $settingOptionRepository,
            $settingService,
            $localeService,
            $typeMapper
        );

        $this->currentTemplateOptions = $this->templateManager->getCurrentTemplateOptions();
        $this->disableDarkMode = !$this->currentTemplateOptions->isSupportDarkMode()
            || $settingService->getSetting(SettingEnum::THEME_DISABLE_DARK_MODE->value);
    }

    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::THEME;
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = parent::configureFields($pageName);

        if ($pageName === Crud::PAGE_EDIT) {
            switch ($this->currentEntity->getName()) {
                case SettingEnum::PANEL_THEME->value:
                    $valueFieldIndex = $this->findValueFieldIndexByName($fields);
                    $fields[$valueFieldIndex] = ChoiceField::new('value', $this->translator->trans('pteroca.crud.setting.value'))
                        ->setChoices($this->templateService->getAvailableTemplatesForContext('panel'))
                        ->setRequired(true)
                        ->setHelp($this->translator->trans('pteroca.crud.setting.panel_theme.help'));
                    break;
                case SettingEnum::LANDING_THEME->value:
                    $valueFieldIndex = $this->findValueFieldIndexByName($fields);
                    $fields[$valueFieldIndex] = ChoiceField::new('value', $this->translator->trans('pteroca.crud.setting.value'))
                        ->setChoices($this->templateService->getAvailableTemplatesForContext('landing'))
                        ->setRequired(true)
                        ->setHelp($this->translator->trans('pteroca.crud.setting.landing_theme.help'));
                    break;
                case SettingEnum::EMAIL_THEME->value:
                    $valueFieldIndex = $this->findValueFieldIndexByName($fields);
                    $fields[$valueFieldIndex] = ChoiceField::new('value', $this->translator->trans('pteroca.crud.setting.value'))
                        ->setChoices($this->templateService->getAvailableTemplatesForContext('email'))
                        ->setRequired(true)
                        ->setHelp($this->translator->trans('pteroca.crud.setting.email_theme.help'));
                    break;
                case SettingEnum::THEME_DEFAULT_MODE->value:
                    $valueFieldIndex = $this->findValueFieldIndexByName($fields);
                    $fields[$valueFieldIndex] = ChoiceField::new('value', $this->translator->trans('pteroca.crud.setting.value'))
                        ->setChoices([
                            'Light' => ColorScheme::LIGHT,
                            'Dark' => ColorScheme::DARK,
                            'Auto' => ColorScheme::AUTO,
                        ])
                        ->setRequired(true);
            }
        }

        return $fields;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $hiddenSettings = [];

        if (!$this->currentTemplateOptions->isSupportDarkMode()) {
            $hiddenSettings[] = SettingEnum::THEME_DISABLE_DARK_MODE->value;
            $hiddenSettings[] = SettingEnum::DEFAULT_THEME_DARK_MODE_COLOR->value;
        }

        if (!$this->currentTemplateOptions->isSupportCustomColors()) {
            $hiddenSettings[] = SettingEnum::DEFAULT_THEME_LIGHT_MODE_COLOR->value;
            $hiddenSettings[] = SettingEnum::DEFAULT_THEME_DARK_MODE_COLOR->value;
        }

        if ($this->disableDarkMode) {
            $hiddenSettings[] = SettingEnum::THEME_DEFAULT_MODE->value;
        }

        $hiddenSettings = array_unique($hiddenSettings);
        if (!empty($hiddenSettings)) {
            $qb->andWhere('entity.name NOT IN (:hiddenSettings)')
                ->setParameter('hiddenSettings', $hiddenSettings);
        }

        return $qb;
    }

    private function findValueFieldIndexByName(iterable $fields): ?int
    {
        foreach ($fields as $key => $field) {
            if ($field->getAsDto()->getProperty() === 'value') {
                return $key;
            }
        }

        return null;
    }
}
