<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Enum\SettingContextEnum;
use App\Core\Repository\SettingOptionRepository;
use App\Core\Repository\SettingRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\LocaleService;
use App\Core\Service\SettingService;
use App\Core\Service\SettingTypeMapperService;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class PluginSettingCrudController extends AbstractSettingCrudController
{
    private ?string $pluginName = null;
    private RequestStack $localRequestStack;
    private TranslatorInterface $translator;
    private SettingRepository $settingRepository;

    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        TranslatorInterface $translator,
        SettingRepository $settingRepository,
        SettingOptionRepository $settingOptionRepository,
        SettingService $settingService,
        LocaleService $localeService,
        SettingTypeMapperService $typeMapper,
    ) {
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
        $this->translator = $translator;
        $this->localRequestStack = $requestStack;
        $this->settingRepository = $settingRepository;
    }

    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::PLUGIN;
    }

    public function index(AdminContext $context): KeyValueStore|Response
    {
        $request = $this->localRequestStack->getCurrentRequest();
        if (!$request->query->has('pluginName') && $request->getSession()->has('plugin_settings_return_plugin')) {
            $pluginName = $request->getSession()->get('plugin_settings_return_plugin');
            $request->getSession()->remove('plugin_settings_return_plugin');

            $url = $this->generateUrl('panel', [
                'crudAction' => 'index',
                'crudControllerFqcn' => self::class,
                'pluginName' => $pluginName
            ]);

            return $this->redirect($url);
        }

        return parent::index($context);
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

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);

        $request = $this->localRequestStack->getCurrentRequest();
        $entityId = $request->query->get('entityId');

        if ($entityId && !$this->pluginName) {
            $setting = $this->settingRepository->find($entityId);
            if ($setting && str_starts_with($setting->getContext(), 'plugin:')) {
                $extractedPluginName = substr($setting->getContext(), 7); // Remove 'plugin:' prefix

                $request->getSession()->set('plugin_settings_return_plugin', $extractedPluginName);

                $url = $this->generateUrl('panel', [
                    'crudAction' => 'index',
                    'crudControllerFqcn' => self::class,
                    'pluginName' => $extractedPluginName
                ]);

                $indexAction = Action::new(Action::INDEX)
                    ->linkToUrl($url);

                $actions->add(Crud::PAGE_EDIT, $indexAction);
            }
        }

        return $actions;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if ($this->pluginName) {
            $pluginContext = 'plugin:' . $this->pluginName;
            $qb->setParameter('context', $pluginContext);
        } else {
            $qb->andWhere('entity.context LIKE :pluginPrefix')
               ->setParameter('pluginPrefix', 'plugin:%');
        }

        return $qb;
    }
}
