<?php

namespace App\Core\Service\Server;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Server;
use App\Core\Entity\ServerSubuser;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use App\Core\Enum\ServerLogActionEnum;
use App\Core\Repository\UserRepository;
use App\Core\Service\Logs\ServerLogService;
use App\Core\Enum\EmailVerificationValueEnum;
use App\Core\Repository\ServerSubuserRepository;
use Exception;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Event\Server\User\ServerSubuserCreatedEvent;
use App\Core\Event\Server\User\ServerSubuserCreationFailedEvent;
use App\Core\Event\Server\User\ServerSubuserCreationRequestedEvent;
use App\Core\Event\Server\User\ServerSubuserDeletedEvent;
use App\Core\Event\Server\User\ServerSubuserDeletionRequestedEvent;
use App\Core\Event\Server\User\ServerSubuserPermissionsUpdateRequestedEvent;
use App\Core\Event\Server\User\ServerSubuserPermissionsUpdatedEvent;
use App\Core\Service\Event\EventContextService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class ServerUserService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private ServerLogService              $serverLogService,
        private ServerSubuserRepository       $serverSubuserRepository,
        private UserRepository                $userRepository,
        private TranslatorInterface           $translator,
        private SettingService                $settingService,
        private EventDispatcherInterface      $eventDispatcher,
        private RequestStack                  $requestStack,
        private EventContextService           $eventContextService,
    ) {}

    public function getAllSubusers(Server $server, UserInterface $user): array
    {
        return $this->pterodactylApplicationService
            ->getClientApi($user)
            ->users()
            ->getUsers($server->getPterodactylServerIdentifier())
            ->toArray();
    }

    /**
     * @throws Exception
     */
    public function addExistingUserToServer(
        Server $server,
        UserInterface $user,
        string $email,
        array $permissions = []
    ): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerSubuserCreationRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $email,
            $permissions,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Subuser creation was blocked';
            throw new RuntimeException($reason);
        }

        $pterodactylClientApi = $this->pterodactylApplicationService
            ->getClientApi($user);

        try {
            $existingPterodactylUsers = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->users()
                ->getAllUsers(['filter[email]' => $email]);
            $existingPterocaUser = $this->userRepository->findOneBy(['email' => $email]);

            if (count($existingPterodactylUsers->toArray()) === 0 || !$existingPterocaUser) {
                throw new Exception($this->translator->trans('pteroca.api.server_user.user_not_exist', ['email' => $email]));
            }

            $verificationSetting = $this->settingService->getSetting(SettingEnum::REQUIRE_EMAIL_VERIFICATION->value);
            if ($verificationSetting === EmailVerificationValueEnum::REQUIRED->value && !$existingPterocaUser->isVerified()) {
                throw new Exception($this->translator->trans('pteroca.api.server_user.user_not_verified', ['email' => $email]));
            }

            $currentSubusers = $this->getAllSubusers($server, $user);
            foreach ($currentSubusers['data'] ?? [] as $subuser) {
                if (isset($subuser['attributes']['email']) && $subuser['attributes']['email'] === $email) {
                    throw new Exception($this->translator->trans('pteroca.api.server_user.user_already_added', ['email' => $email]));
                }
            }

            $result = $pterodactylClientApi->users()
                ->createUser($server->getPterodactylServerIdentifier(), $email, $permissions);

            $this->syncServerSubuser($server, $existingPterocaUser, $permissions);

            $this->serverLogService->logServerAction(
                $user,
                $server,
                ServerLogActionEnum::CREATE_SUBUSER,
                [
                    'email' => $email,
                    'permissions_count' => count($permissions),
                ]
            );

            $resultArray = $result->toArray();
            $subuserUuid = $resultArray['attributes']['uuid'] ?? '';

            $createdEvent = new ServerSubuserCreatedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $email,
                $subuserUuid,
                $permissions,
                $context
            );
            $this->eventDispatcher->dispatch($createdEvent);

            return $resultArray;

        } catch (Exception $e) {
            $failedEvent = new ServerSubuserCreationFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $email,
                $permissions,
                $e->getMessage(),
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            if (str_contains($e->getMessage(), 'No user found') ||
                str_contains($e->getMessage(), 'does not exist')) {
                throw new Exception($this->translator->trans('pteroca.api.server_user.user_not_exist', ['email' => $email]));
            }

            if (str_contains($e->getMessage(), 'already assigned') ||
                str_contains($e->getMessage(), 'already exists')) {
                throw new Exception($this->translator->trans('pteroca.api.server_user.user_already_added', ['email' => $email]));
            }

            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    public function updateSubuserPermissions(
        Server $server,
        UserInterface $user,
        string $subuserUuid,
        string $email,
        array $permissions
    ): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $existingPterocaUser = $this->userRepository->findOneBy(['email' => $email]);

        if (!$existingPterocaUser) {
            throw new Exception($this->translator->trans('pteroca.api.server_user.user_not_exist', ['email' => $email]));
        }

        $this->validateSubuserModification($server, $user, $email, $this->translator->trans('pteroca.api.server_user.modify_permissions'));

        $subuserData = $this->getSubuser($server, $user, $subuserUuid);
        $oldPermissions = $subuserData['attributes']['permissions'] ?? [];

        $requestedEvent = new ServerSubuserPermissionsUpdateRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $email,
            $subuserUuid,
            $oldPermissions,
            $permissions,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Subuser permissions update was blocked';
            throw new RuntimeException($reason);
        }

        $result = $this->pterodactylApplicationService
            ->getClientApi($user)
            ->users()
            ->updateUserPermissions($server->getPterodactylServerIdentifier(), $subuserUuid, $permissions);

        $this->syncServerSubuser($server, $existingPterocaUser, $permissions);

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::UPDATE_SUBUSER,
            [
                'subuser_uuid' => $subuserUuid,
                'subuser_email' => $email,
                'permissions_count' => count($permissions),
            ]
        );

        $updatedEvent = new ServerSubuserPermissionsUpdatedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $email,
            $subuserUuid,
            $oldPermissions,
            $permissions,
            $context
        );
        $this->eventDispatcher->dispatch($updatedEvent);

        return $result->toArray();
    }

    /**
     * @throws Exception
     */
    public function deleteSubuser(
        Server $server,
        UserInterface $user,
        string $subuserUuid,
        string $email
    ): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $existingPterocaUser = $this->userRepository->findOneBy(['email' => $email]);

        if (!$existingPterocaUser) {
            throw new Exception($this->translator->trans('pteroca.api.server_user.user_not_exist', ['email' => $email]));
        }

        $this->validateSubuserModification($server, $user, $email, $this->translator->trans('pteroca.api.server_user.delete_yourself_from_server'));

        $requestedEvent = new ServerSubuserDeletionRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $email,
            $subuserUuid,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Subuser deletion was blocked';
            throw new RuntimeException($reason);
        }

        $this->pterodactylApplicationService
            ->getClientApi($user)
            ->users()
            ->deleteUser($server->getPterodactylServerIdentifier(), $subuserUuid);

        $existingSubuser = $this->serverSubuserRepository->findSubuserByServerAndUser($server, $existingPterocaUser);
        if ($existingSubuser) {
            $this->serverSubuserRepository->delete($existingSubuser);
        }

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::DELETE_SUBUSER,
            [
                'subuser_uuid' => $subuserUuid,
                'subuser_email' => $email,
            ]
        );

        $deletedEvent = new ServerSubuserDeletedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $email,
            $subuserUuid,
            $context
        );
        $this->eventDispatcher->dispatch($deletedEvent);
    }

    public function getSubuser(
        Server $server,
        UserInterface $user,
        string $subuserUuid
    ): array
    {
        return $this->pterodactylApplicationService
            ->getClientApi($user)
            ->users()
            ->getUser($server->getPterodactylServerIdentifier(), $subuserUuid)
            ->toArray();
    }

    private function syncServerSubuser(Server $server, UserInterface $subuserEntity, array $permissions): void
    {
        $existingSubuser = $this->serverSubuserRepository->findSubuserByServerAndUser($server, $subuserEntity);
        
        if ($existingSubuser) {
            $existingSubuser->setPermissions($permissions);
            $this->serverSubuserRepository->save($existingSubuser);
        } else {
            $serverSubuser = new ServerSubuser();
            $serverSubuser->setServer($server);
            $serverSubuser->setUser($subuserEntity);
            $serverSubuser->setPermissions($permissions);
            $this->serverSubuserRepository->save($serverSubuser);
        }
    }

    /**
     * @throws Exception
     */
    private function validateSubuserModification(Server $server, UserInterface $user, string $targetEmail, string $action): void
    {
        $isServerOwner = $server->getUser()->getId() === $user->getId();
        $isAdmin = $user->hasPermission(PermissionEnum::EDIT_SERVER);

        if (!$isServerOwner && !$isAdmin && $user->getEmail() === $targetEmail) {
            throw new Exception($this->translator->trans('pteroca.api.server_user.cannot_modify_yourself', ['action' => $action]));
        }
    }
}
