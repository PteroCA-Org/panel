<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingContextEnum;
use App\Core\Repository\SettingRepository;
use App\Core\Repository\SettingOptionRepository;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\LocaleService;
use App\Core\Service\SettingService;
use App\Core\Service\SettingTypeMapperService;
use App\Core\Service\System\WebConfigurator\PterodactylConnectionVerificationService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class PterodactylSettingCrudController extends AbstractSettingCrudController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly SettingRepository $settingRepository,
        SettingOptionRepository $settingOptionRepository,
        SettingService $settingService,
        LocaleService $localeService,
        SettingTypeMapperService $typeMapper,
        private readonly PterodactylConnectionVerificationService $pterodactylConnectionVerificationService,
    ) {
        parent::__construct($panelCrudService, $requestStack, $translator, $settingRepository, $settingOptionRepository, $settingService, $localeService, $typeMapper);
    }

    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::PTERODACTYL;
    }

    public function configureActions(Actions $actions): Actions
    {
        $testPterodactylAction = Action::new('testPterodactylConnection', $this->translator->trans('pteroca.crud.setting.test_pterodactyl_connection'))
            ->linkToRoute('admin_pterodactyl_test_connection')
            ->setIcon('fa fa-network-wired')
            ->setCssClass('btn-info')
            ->displayIf(fn () => $this->getUser()?->hasPermission(PermissionEnum::EDIT_SETTINGS_PTERODACTYL))
            ->createAsGlobalAction();

        $actions = parent::configureActions($actions);
        $actions->add(Crud::PAGE_INDEX, $testPterodactylAction);

        return $actions;
    }

    #[Route('/panel/pterodactyl-settings/test-connection', name: 'admin_pterodactyl_test_connection')]
    public function testPterodactylConnection(): RedirectResponse
    {
        try {
            $result = $this->pterodactylConnectionVerificationService->validateExistingConnection();

            if ($result->isVerificationSuccessful) {
                $this->addFlash('success', $this->translator->trans('pteroca.crud.setting.pterodactyl_connection_success'));
            } else {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.setting.pterodactyl_connection_failed'));
            }

        } catch (Exception) {
            $this->addFlash('danger', $this->translator->trans('pteroca.crud.setting.pterodactyl_connection_failed'));
        }

        return $this->redirectToRoute('panel', [
            'crudAction' => 'index',
            'crudControllerFqcn' => self::class,
        ]);
    }
}
