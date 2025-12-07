<?php

namespace App\Core\Service\User;

use App\Core\Contract\UserInterface;
use App\Core\Enum\PermissionEnum;
use App\Core\Exception\CouldNotCreatePterodactylClientApiKeyException;
use App\Core\Exception\PterodactylUserNotFoundException;
use App\Core\Repository\UserRepository;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylClientApiKeyService;
use App\Core\Service\Pterodactyl\PterodactylUsernameService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class UserService
{
    public function __construct(
        private UserPasswordHasherInterface    $passwordHasher,
        private PterodactylApplicationService  $pterodactylApplicationService,
        private PterodactylUsernameService     $usernameService,
        private PterodactylClientApiKeyService $pterodactylClientApiKeyService,
        private UserRepository                 $userRepository,
        private TranslatorInterface            $translator,
        private LoggerInterface                $logger,
    ) {
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws Exception
     */
    public function createUserWithPterodactylAccount(UserInterface $user, string $plainPassword): void
    {
        if ($plainPassword) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        }

        try {
            $createdUser = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->users()
                ->createUser([
                    'email' => $user->getEmail(),
                    'username' => $this->usernameService->generateUsername($user->getEmail()),
                    'first_name' => $user->getName(),
                    'last_name' => $user->getSurname(),
                    'password' => $plainPassword,
                    'root_admin' => $user->hasPermission(PermissionEnum::PTERODACTYL_ROOT_ADMIN),
            ]);
            $user->setPterodactylUserId($createdUser->get('id'));
        } catch (Exception $exception) {
            $this->logger->error('Failed to create Pterodactyl account during user creation', [
                'exception' => $exception,
                'user' => $user,
            ]);
            throw new Exception($this->translator->trans('pteroca.system.pterodactyl_error'));
        }

        try {
            $pterodactylClientApiKey = $this->pterodactylClientApiKeyService->createClientApiKey($user);
            $user->setPterodactylUserApiKey($pterodactylClientApiKey);
        } catch (CouldNotCreatePterodactylClientApiKeyException|Exception $exception) {
            $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->users()
                    ->deleteUser($createdUser->get('id'));
            $this->logger->error('Failed to create Pterodactyl client API key during user creation', [
                'exception' => $exception,
                'user' => $user,
            ]);
            throw new Exception($this->translator->trans('pteroca.system.pterodactyl_error'));
        }
    }

    /**
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function createOrRestoreUser(UserInterface $user, string $plainPassword): array
    {
        $existingDeletedUser = $this->userRepository->findDeletedByEmail($user->getEmail());

        if ($existingDeletedUser) {
            $existingDeletedUser->restore();
            $existingDeletedUser->setName($user->getName());
            $existingDeletedUser->setSurname($user->getSurname());
            $existingDeletedUser->setRoles($user->getRoles());
            $existingDeletedUser->setIsVerified($user->isVerified());
            $existingDeletedUser->setIsBlocked($user->isBlocked());
            $existingDeletedUser->setBalance($user->getBalance());

            if ($plainPassword) {
                $existingDeletedUser->setPlainPassword($plainPassword);
                $hashedPassword = $this->passwordHasher->hashPassword($existingDeletedUser, $plainPassword);
                $existingDeletedUser->setPassword($hashedPassword);
            }

            try {
                if ($existingDeletedUser->getPterodactylUserId()) {
                    $this->updateUserInPterodactyl($existingDeletedUser, $plainPassword);

                    return ['action' => 'restored', 'user' => $existingDeletedUser];
                }
            } catch (Exception) {}

            try {
                $this->createUserWithPterodactylAccount($existingDeletedUser, $plainPassword);

                return ['action' => 'restored', 'user' => $existingDeletedUser];
            } catch (Exception $exception) {
                $this->logger->error('Failed to restore user in Pterodactyl', [
                    'exception' => $exception,
                    'user' => $existingDeletedUser,
                ]);
                throw new Exception($this->translator->trans('pteroca.system.pterodactyl_error'));
            }
        } else {
            $this->createUserWithPterodactylAccount($user, $plainPassword);
            return ['action' => 'created', 'user' => $user];
        }
    }

    /**
     * @throws Exception
     */
    public function updateUserInPterodactyl(UserInterface $user, ?string $plainPassword = null): void
    {
        if ($plainPassword) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        }

        if ($user->getPterodactylUserId() === null) {
            $this->createUserWithPterodactylAccount($user, $plainPassword);
            return;
        }

        try {
            $pterodactylAccount = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->users()
                ->getUser($user->getPterodactylUserId());

            if (!empty($pterodactylAccount->username)) {
                $pterodactylAccountDetails = [
                    'username' => $pterodactylAccount->username,
                    'email' => $user->getEmail(),
                    'first_name' => $user->getName(),
                    'last_name' => $user->getSurname(),
                    'root_admin' => $user->hasPermission(PermissionEnum::PTERODACTYL_ROOT_ADMIN),
                ];

                if ($plainPassword) {
                    $pterodactylAccountDetails['password'] = $plainPassword;
                }

                $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->users()
                    ->updateUser(
                        $user->getPterodactylUserId(),
                        $pterodactylAccountDetails,
                    );
            }
        } catch (Exception $exception) {
            if (str_contains($exception->getMessage(), 'The resource you are looking for could not be found')) {
                $this->logger->warning('Pterodactyl user not found during update, creating new account', [
                    'old_pterodactyl_user_id' => $user->getPterodactylUserId(),
                    'user_email' => $user->getEmail(),
                ]);

                $user->setPterodactylUserId(null);
                $user->setPterodactylUserApiKey(null);

                $this->createUserWithPterodactylAccount($user, $plainPassword);
                return;
            }

            $this->logger->error('Failed to update Pterodactyl account', [
                'exception' => $exception,
                'user' => $user,
            ]);
            throw new Exception($this->translator->trans('pteroca.system.pterodactyl_error'));
        }
    }

    /**
     * @throws PterodactylUserNotFoundException
     * @throws Exception
     */
    public function deleteUserFromPterodactyl(UserInterface $user): void
    {
        try {
            $this->pterodactylApplicationService
                ->getApplicationApi()
                ->users()
                ->deleteUser($user->getPterodactylUserId());
        } catch (Exception $exception) {
            $this->logger->error('Failed to delete Pterodactyl account', [
                'exception' => $exception,
                'user' => $user,
            ]);

            if (str_contains($exception->getMessage(), 'The resource you are looking for could not be found')) {
                throw new PterodactylUserNotFoundException('User not found in Pterodactyl', 0, $exception);
            }

            throw new Exception($this->translator->trans('pteroca.system.pterodactyl_error'));
        }
    }
}
