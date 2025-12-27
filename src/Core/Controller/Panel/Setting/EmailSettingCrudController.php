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
use App\Core\Service\System\WebConfigurator\EmailConnectionVerificationService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailSettingCrudController extends AbstractSettingCrudController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        SettingRepository $settingRepository,
        SettingOptionRepository $settingOptionRepository,
        SettingService $settingService,
        LocaleService $localeService,
        SettingTypeMapperService $typeMapper,
        private readonly EmailConnectionVerificationService $emailConnectionVerificationService,
    ) {
        parent::__construct($panelCrudService, $requestStack, $translator, $settingRepository, $settingOptionRepository, $settingService, $localeService, $typeMapper);
    }

    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::EMAIL;
    }

    public function configureActions(Actions $actions): Actions
    {
        $testSmtpAction = Action::new('testSmtpConnection', $this->translator->trans('pteroca.crud.setting.test_smtp_connection'))
            ->linkToRoute('admin_email_test_smtp')
            ->setIcon('fa fa-envelope-circle-check')
            ->setCssClass('btn-info')
            ->displayIf(fn () => $this->getUser()?->hasPermission(PermissionEnum::EDIT_SETTINGS_EMAIL))
            ->createAsGlobalAction();

        $actions = parent::configureActions($actions);
        $actions->add(Crud::PAGE_INDEX, $testSmtpAction);

        return $actions;
    }

    #[Route('/panel/email-settings/test-smtp', name: 'admin_email_test_smtp')]
    public function testSmtpConnection(): RedirectResponse
    {
        try {
            $result = $this->emailConnectionVerificationService->validateExistingConnection();

            if ($result->isVerificationSuccessful) {
                $this->addFlash('success', $this->translator->trans('pteroca.crud.setting.smtp_connection_success'));
            } else {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.setting.smtp_connection_failed'));
            }

        } catch (Exception) {
            $this->addFlash('danger', $this->translator->trans('pteroca.crud.setting.smtp_connection_failed'));
        }

        return $this->redirectToRoute('panel', [
            'crudAction' => 'index',
            'crudControllerFqcn' => self::class,
        ]);
    }
}
