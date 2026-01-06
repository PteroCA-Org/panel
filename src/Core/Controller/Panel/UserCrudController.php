<?php

namespace App\Core\Controller\Panel;

use Exception;
use App\Core\Entity\User;
use App\Core\Entity\Server;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\PermissionEnum;
use App\Core\Contract\UserInterface;
use App\Core\Service\Logs\LogService;
use App\Core\Service\User\UserService;
use App\Core\Service\User\RegeneratePterodactylApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Service\Crud\PanelCrudService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use App\Core\Exception\PterodactylUserNotFoundException;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;

class UserCrudController extends AbstractPanelController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly UserService $userService,
        private readonly TranslatorInterface $translator,
        private readonly LogService $logService,
        private readonly RegeneratePterodactylApiKeyService $regeneratePterodactylApiKeyService,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $uploadDirectory = str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->getParameter('avatar_directory'),
        );

        $fields = [];

        if ($pageName !== Crud::PAGE_NEW) {
            $fields[] = NumberField::new('id')
                ->setDisabled()
                ->setColumns(2);
            $fields[] = NumberField::new('pterodactylUserId', $this->translator->trans('pteroca.crud.user.pterodactyl_user_id'))
                ->setDisabled()
                ->setColumns(2);
            $fields[] = DateField::new('createdAt', $this->translator->trans('pteroca.crud.user.created_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = DateField::new('updatedAt', $this->translator->trans('pteroca.crud.user.updated_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = DateField::new('deletedAt', $this->translator->trans('pteroca.crud.user.deleted_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss')
                ->setDisabled()
                ->setColumns(2)
                ->hideOnIndex();
            $fields[] = FormField::addRow();
        }

        $fields = array_merge($fields, [
            ImageField::new('avatarPath', $this->translator->trans('pteroca.crud.user.avatar'))
                ->setBasePath($this->getParameter('avatar_base_path'))
                ->setUploadDir($uploadDirectory)
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false)
                ->setColumns(4),
            AssociationField::new('userRoles', $this->translator->trans('pteroca.crud.user.roles'))
                ->setFormTypeOption('by_reference', false)
                ->setFormTypeOption('choice_label', 'displayName')
                ->setHelp($this->translator->trans('pteroca.crud.user.roles_help'))
                ->onlyOnForms(),
            FormField::addRow(),
            TextField::new('email', $this->translator->trans('pteroca.crud.user.email'))
                ->setColumns(6),
            NumberField::new('balance', $this->translator->trans('pteroca.crud.user.balance'))
                ->setNumDecimals(2)
                ->setDecimalSeparator('.'),
            FormField::addRow(),
            TextField::new('name', $this->translator->trans('pteroca.crud.user.name'))
                ->setMaxLength(255)
                ->setColumns(4),
            TextField::new('surname', $this->translator->trans('pteroca.crud.user.surname'))
                ->setMaxLength(255)
                ->setColumns(4),
            FormField::addRow(),
            BooleanField::new('isVerified', $this->translator->trans('pteroca.crud.user.verified'))
                ->hideOnIndex()
                ->setColumns(2),
            BooleanField::new('isBlocked', $this->translator->trans('pteroca.crud.user.blocked'))
                ->hideOnIndex()
                ->setColumns(2),
            FormField::addRow(),
            TextField::new('plainPassword', $this->translator->trans('pteroca.crud.user.password'))
                ->setFormType(RepeatedType::class)
                ->setFormTypeOptions([
                    'type' => PasswordType::class,
                    'first_options' => [
                        'label' => $this->translator->trans('pteroca.crud.user.password'),
                        'attr' => ['style' => 'max-width: 400px;'],
                        'help' => $pageName === Crud::PAGE_EDIT ? $this->translator->trans('pteroca.crud.user.password_hint') : null,
                    ],
                    'second_options' => [
                        'label' => $this->translator->trans('pteroca.crud.user.repeat_password'),
                        'attr' => ['style' => 'max-width: 400px;'],
                    ],
                    'invalid_message' => $this->translator->trans('pteroca.crud.user.passwords_must_match'),
                ])
                ->onlyOnForms()
                ->setRequired($pageName === Crud::PAGE_NEW),
                FormField::addRow(),
        ]);

        if ($pageName === Crud::PAGE_DETAIL) {
            $fields[] = AssociationField::new('userRoles', $this->translator->trans('pteroca.crud.user.roles'))
                ->formatValue(function ($value, $entity) {
                    $roleNames = [];
                    foreach ($entity->getUserRoles() as $role) {
                        $roleNames[] = $role->getDisplayName();
                    }
                    return !empty($roleNames) ? implode(', ', $roleNames) : '-';
                });
        }

        if ($pageName === Crud::PAGE_INDEX) {
            $fields[] = AssociationField::new('userRoles', $this->translator->trans('pteroca.crud.user.roles'))
                ->formatValue(function ($value, $entity) {
                    $roleNames = [];
                    foreach ($entity->getUserRoles() as $role) {
                        $roleNames[] = $role->getDisplayName();
                    }
                    return !empty($roleNames) ? implode(', ', $roleNames) : '-';
                });
            $fields[] = DateField::new('createdAt', $this->translator->trans('pteroca.crud.user.created_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss');
            $fields[] = DateField::new('updatedAt', $this->translator->trans('pteroca.crud.user.updated_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss');
            $fields[] = DateField::new('deletedAt', $this->translator->trans('pteroca.crud.user.deleted_at'))
                ->setFormat('dd.MM.yyyy HH:mm:ss');
        }

        if ($pageName === Crud::PAGE_EDIT || $pageName === Crud::PAGE_DETAIL) {
            $fields[] = TextField::new('pterodactylUserApiKey', $this->translator->trans('pteroca.crud.user.pterodactyl_api_key'))
                ->setFormTypeOption('attr', [
                    'readonly' => true,
                ])
                ->setDisabled()
                ->setColumns(12)
                ->setHelp($this->translator->trans('pteroca.crud.user.pterodactyl_api_key_help'));
        }

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.user.add')))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.user.add')))
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, fn (Action $action) => $action->setLabel($this->translator->trans('pteroca.crud.user.save')))
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, fn (Action $action) => $action->displayIf(
                fn ($entity) =>
                    $entity instanceof UserInterface &&
                    $this->getUser()?->hasPermission(PermissionEnum::DELETE_USER) &&
                    !$entity->isDeleted()
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => $action->displayIf(
                fn ($entity) =>
                    $entity instanceof UserInterface &&
                    $this->getUser()?->hasPermission(PermissionEnum::DELETE_USER) &&
                    !$entity->isDeleted()
            ));

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::USER->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.user.user'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.user.users'))
            ->setDefaultSort(['createdAt' => 'DESC']);

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('email')
            ->add('userRoles')
            ->add('balance')
            ->add('name')
            ->add('surname')
            ->add('pterodactylUserId')
            ->add('isVerified')
            ->add('isBlocked')
            ->add('createdAt')
            ->add('updatedAt')
            ->add('deletedAt')
        ;
        return parent::configureFilters($filters);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof UserInterface) {
            try {
                $result = $this->userService->createOrRestoreUser($entityInstance, $entityInstance->getPlainPassword());

                if ($result['action'] === 'restored') {
                    $this->addFlash('success', $this->translator->trans('pteroca.crud.user.account_restored'));
                    $entityInstance = $result['user'];
                } else {
                    $this->addFlash('success', $this->translator->trans('pteroca.crud.user.created_successfully'));
                }
            } catch (Exception $e) {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.user.create_error', ['%error%' => $e->getMessage()]));
                return;
            }
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof UserInterface) {
            try {
                $this->userService->updateUserInPterodactyl($entityInstance, $entityInstance->getPlainPassword());

                // Force Doctrine to recompute the changeset because the password
                // was modified inside the service method, outside normal entity lifecycle tracking
                $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
                    $entityManager->getClassMetadata(User::class),
                    $entityInstance
                );
            } catch (Exception $e) {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.user.update_error', ['%error%' => $e->getMessage()]));
                throw $e;
            }
        }

        parent::updateEntity($entityManager, $entityInstance);

        $this->addFlash('success', $this->translator->trans('pteroca.crud.user.updated_successfully'));
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof UserInterface) {
            $activeServersCount = $entityManager->getRepository(Server::class)->count([
                'user' => $entityInstance,
                'deletedAt' => null
            ]);

            if ($activeServersCount > 0) {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.user.cannot_delete_user_with_active_servers', ['count' => $activeServersCount]));
                return;
            }

            $pterodactylUserNotFound = false;

            try {
                $this->userService->deleteUserFromPterodactyl($entityInstance);
            } catch (PterodactylUserNotFoundException) {
                $pterodactylUserNotFound = true;
            } catch (Exception $e) {
                $this->addFlash('danger', $this->translator->trans('pteroca.crud.user.delete_error', ['%error%' => $e->getMessage()]));
                return;
            }

            // Clear Pterodactyl mapping before soft delete to prevent issues during re-registration
            $entityInstance->setPterodactylUserId(null);
            $entityInstance->setPterodactylUserApiKey(null);
            $entityInstance->softDelete();
            $entityManager->persist($entityInstance);
            $entityManager->flush();

            $this->logService->logAction($entityInstance, LogActionEnum::ENTITY_DELETE, [
                'deleted_by' => $this->getUser()->getEmail(),
            ]);

            if ($pterodactylUserNotFound) {
                $this->addFlash('warning', $this->translator->trans('pteroca.crud.user.pterodactyl_user_not_found'));
            } else {
                $this->addFlash('success', $this->translator->trans('pteroca.crud.user.deleted_successfully'));
            }

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function regenerateApiKey(AdminContext $context): JsonResponse
    {
        if (!$this->getUser()?->hasPermission(PermissionEnum::EDIT_USER)) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('pteroca.error.no_permission')
            ], 403);
        }

        $user = $context->getEntity()->getInstance();

        if (!$user instanceof UserInterface) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('pteroca.crud.user.invalid_user')
            ], 400);
        }

        $result = $this->regeneratePterodactylApiKeyService->regenerateApiKey($user, $this->getUser());

        if (isset($result['message'])) {
            $result['message'] = $this->translator->trans($result['message'], [
                '%error%' => $result['error'] ?? ''
            ]);
            unset($result['error']);
        }

        $statusCode = 200;
        if (!$result['success']) {
            if (str_contains($result['message'], 'not found')) {
                $statusCode = 404;
            } else {
                $statusCode = 500;
            }
        }

        return new JsonResponse($result, $statusCode);
    }
}
