<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Enum\SettingContextEnum;
use App\Core\Repository\SettingOptionRepository;
use App\Core\Repository\SettingRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\LocaleService;
use App\Core\Service\SettingService;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class PluginSettingCrudController extends AbstractSettingCrudController
{
    private ?string $pluginName = null;
    private RequestStack $localRequestStack;
    private TranslatorInterface $translator;

    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        TranslatorInterface $translator,
        SettingRepository $settingRepository,
        SettingOptionRepository $settingOptionRepository,
        SettingService $settingService,
        LocaleService $localeService,
    ) {
        parent::__construct(
            $panelCrudService,
            $requestStack,
            $translator,
            $settingRepository,
            $settingOptionRepository,
            $settingService,
            $localeService
        );
        $this->translator = $translator;
        $this->localRequestStack = $requestStack;
    }

    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::PLUGIN;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // Get plugin name from request
        $request = $this->localRequestStack->getCurrentRequest();
        $this->pluginName = $request->query->get('pluginName');

        if ($this->pluginName) {
            // Specific plugin settings
            $pluginLabel = ucfirst(str_replace(['_', '-'], ' ', $this->pluginName));
            $crud
                ->setEntityLabelInSingular(sprintf($this->translator->trans('pteroca.crud.plugin.plugin_setting_with_name'), $pluginLabel))
                ->setEntityLabelInPlural(sprintf($this->translator->trans('pteroca.crud.plugin.plugin_settings_with_name'), $pluginLabel));
        } else {
            // All plugin settings
            $crud
                ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.plugin.plugin_setting'))
                ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.plugin.plugin_settings'));
        }

        return parent::configureCrud($crud);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if ($this->pluginName) {
            $pluginContext = 'plugin_' . $this->pluginName;
            $qb->setParameter('context', $pluginContext);
        } else {
            $qb->andWhere('entity.context LIKE :pluginPrefix')
               ->setParameter('pluginPrefix', 'plugin_%');
        }

        return $qb;
    }
}
