<?php

namespace App\Core\Controller;

use App\Core\Controller\Panel\AbstractPanelController;
use App\Core\Entity\Panel\UserAccount;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Event\User\Account\PterodactylAccountSyncedEvent;
use App\Core\Event\User\Account\UserAccountUpdateRequestedEvent;
use App\Core\Event\User\Account\UserAccountUpdatedEvent;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Trait\CrudFlashMessagesTrait;
use Doctrine\ORM\EntityManagerInterface;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserAccountCrudController extends AbstractPanelController
{
    use CrudFlashMessagesTrait;

    protected bool $useConventionBasedPermissions = false;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return UserAccount::class;
    }

    protected function getPermissionMapping(): array
    {
        return [
            Action::INDEX => PermissionEnum::EDIT_USER_ACCOUNT->value,
            Action::EDIT  => PermissionEnum::EDIT_USER_ACCOUNT->value,
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->disable(Action::NEW, Action::DELETE, Action::DETAIL);
        $actions->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);

        return parent::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        $uploadDirectory = str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->getParameter('avatar_directory'),
        );

        return [
            FormField::addRow(),
            IdField::new('id')->hideOnForm(),
            EmailField::new('email', $this->translator->trans('pteroca.crud.user.email'))
                ->setFormTypeOption('disabled', true)
                ->setColumns(6),
            ImageField::new('avatarPath', $this->translator->trans('pteroca.crud.user.avatar'))
                ->setBasePath($this->getParameter('avatar_base_path'))
                ->setUploadDir($uploadDirectory)
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false)
                ->setFileConstraints(new Image([
                    'maxSize' => $this->getParameter('avatar_max_size'),
                    'mimeTypes' => $this->getParameter('avatar_allowed_extensions'),
                ]))
                ->setColumns(6),

            FormField::addRow(),
            TextField::new('name', $this->translator->trans('pteroca.crud.user.name'))
                ->setMaxLength(255)
                ->setColumns(6),
            TextField::new('surname', $this->translator->trans('pteroca.crud.user.surname'))
                ->setMaxLength(255)
                ->setColumns(6),

            FormField::addRow(),
            TextField::new('plainPassword', $this->translator->trans('pteroca.crud.user.password'))
                ->setFormType(RepeatedType::class)
                ->setFormTypeOptions([
                    'type' => PasswordType::class,
                    'first_options' => [
                        'label' => $this->translator->trans('pteroca.crud.user.password'),
                        'help' => $this->translator->trans('pteroca.crud.user.password_hint'),
                    ],
                    'second_options' => [
                        'label' => $this->translator->trans('pteroca.crud.user.repeat_password'),
                        'help' => $this->translator->trans('pteroca.crud.user.repeat_password_hint'),
                    ],
                    'invalid_message' => $this->translator->trans('pteroca.crud.user.passwords_must_match'),
                ])
                ->onlyOnForms()
                ->setRequired(false)
                ->setColumns(6),

        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::USER_ACCOUNT->value);

        $crud
            ->setEntityPermission(PermissionEnum::EDIT_USER_ACCOUNT->value)
            ->setEntityLabelInSingular($this->translator->trans('pteroca.dashboard.account_settings'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.dashboard.account_settings'))
            ->setHelp('edit', $this->translator->trans('pteroca.crud.user_account.description'))
            ->setSearchFields(null);

        return parent::configureCrud($crud);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $queryBuilder->andWhere('entity.email = :email');
        $queryBuilder->setParameter('email', $this->getUser()->getEmail());

        return $queryBuilder;
    }

    public function index(AdminContext $context): KeyValueStore|RedirectResponse|Response
    {
        $user = $this->getUser();

        if (empty($user)) {
            return $this->redirectToRoute('app_login');
        }

        return $this->redirectToRoute('panel', [
            'crudAction' => 'edit',
            'crudControllerFqcn' => UserAccountCrudController::class,
            'entityId' => $this->getUser()->getId(),
        ]);
    }

    public function edit(AdminContext $context): KeyValueStore|RedirectResponse|Response
    {
        return $this->validateAccount($context);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof UserAccount) {
            try {
                // Get request and build context
                $request = $this->container->get('request_stack')->getCurrentRequest();
                $eventContext = $request ? $this->buildMinimalEventContext($request) : [];
                $plainPassword = $entityInstance->getPlainPassword();

                // Dispatch UserAccountUpdateRequestedEvent
                $updateRequestedEvent = new UserAccountUpdateRequestedEvent(
                    $entityInstance,
                    $plainPassword,
                    $eventContext
                );
                $updateRequestedEvent = $this->dispatchEvent($updateRequestedEvent);

                if ($updateRequestedEvent->isPropagationStopped()) {
                    throw new RuntimeException($this->translator->trans('pteroca.crud.user.update_blocked'));
                }

                $passwordWasChanged = false;
                if ($plainPassword) {
                    $hashedPassword = $this->passwordHasher->hashPassword($entityInstance, $plainPassword);
                    $entityInstance->setPassword($hashedPassword);
                    $passwordWasChanged = true;
                }

                $pterodactylAccount = $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->users()
                    ->getUser($entityInstance->getPterodactylUserId());
                if (!empty($pterodactylAccount->username)) {
                    $pterodactylAccountDetails = [
                        'username' => $pterodactylAccount->username,
                        'email' => $entityInstance->getEmail(),
                        'first_name' => $entityInstance->getName(),
                        'last_name' => $entityInstance->getSurname(),
                    ];
                    if ($plainPassword) {
                        $pterodactylAccountDetails['password'] = $plainPassword;
                    }
                    $this->pterodactylApplicationService
                        ->getApplicationApi()
                        ->users()
                        ->updateUser(
                            $entityInstance->getPterodactylUserId(),
                            $pterodactylAccountDetails
                        );

                    // Dispatch PterodactylAccountSyncedEvent
                    $pterodactylSyncedEvent = new PterodactylAccountSyncedEvent(
                        $entityInstance,
                        $entityInstance->getPterodactylUserId(),
                        $plainPassword !== null,
                        $eventContext
                    );
                    $this->dispatchEvent($pterodactylSyncedEvent);
                }

                // Call parent to handle the CrudEntity events
                parent::updateEntity($entityManager, $entityInstance);

                // Dispatch UserAccountUpdatedEvent
                $accountUpdatedEvent = new UserAccountUpdatedEvent(
                    $entityInstance,
                    $passwordWasChanged,
                    $eventContext
                );
                $this->dispatchEvent($accountUpdatedEvent);

                $this->addFlash('success', $this->translator->trans('pteroca.crud.user_account.updated_successfully'));
            } catch (Exception $e) {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.user_account.update_error', ['%error%' => $e->getMessage()]));
                throw $e;
            }
        } else {
            parent::updateEntity($entityManager, $entityInstance);
        }
    }

    private function validateAccount(AdminContext $context): KeyValueStore|RedirectResponse|Response
    {
        $user = $this->getUser();
        if (empty($user)) {
            return $this->redirectToRoute('app_login');
        }

        $entityId = $context->getRequest()->get('entityId');
        if (empty($entityId) || !is_numeric($entityId) || $user->getId() !== (int)$entityId) {
            return $this->redirectToRoute('panel', [
                'crudAction' => 'edit',
                'crudControllerFqcn' => UserAccountCrudController::class,
                'entityId' => $user->getId(),
            ]);
        }

        return parent::edit($context);
    }
}
