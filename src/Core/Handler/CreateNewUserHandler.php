<?php

namespace App\Core\Handler;

use App\Core\Entity\User;
use App\Core\Enum\SystemRoleEnum;
use App\Core\Event\Cli\CreateUser\UserCreationProcessCompletedEvent;
use App\Core\Event\Cli\CreateUser\UserCreationProcessFailedEvent;
use App\Core\Event\Cli\CreateUser\UserCreationProcessStartedEvent;
use App\Core\Exception\CouldNotCreatePterodactylClientApiKeyException;
use App\Core\Exception\PterodactylAccountEmailAlreadyExists;
use App\Core\Repository\UserRepository;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Pterodactyl\PterodactylAccountService;
use App\Core\Service\Pterodactyl\PterodactylClientApiKeyService;
use App\Core\Service\Security\RoleManager;
use DateTimeImmutable;
use Exception;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateNewUserHandler implements HandlerInterface
{
    private string $userEmail;

    private string $userPassword;

    private string $userRoleName;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly PterodactylAccountService $pterodactylAccountService,
        private readonly PterodactylClientApiKeyService $pterodactylClientApiKeyService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EventContextService $eventContextService,
        private readonly RoleManager $roleManager,
    ) {}

    /**
     * @throws CouldNotCreatePterodactylClientApiKeyException
     * @throws PterodactylAccountEmailAlreadyExists
     */
    public function handle(bool $allowToCreateWithNoPterodactylApiKey = false): void
    {
        $startTime = new DateTimeImmutable();

        // Validate credentials first
        if (empty($this->userEmail) || empty($this->userPassword)) {
            $context = $this->eventContextService->buildCliContext('app:create-new-user', [
                'role' => $this->userRoleName ?? 'UNKNOWN',
            ]);

            $this->eventDispatcher->dispatch(
                new UserCreationProcessFailedEvent(
                    'User credentials not set',
                    $this->userEmail ?? 'UNKNOWN',
                    $this->userRoleName ?? 'UNKNOWN',
                    new DateTimeImmutable(),
                    $context
                )
            );

            throw new RuntimeException('User credentials not set');
        }

        $context = $this->eventContextService->buildCliContext('app:create-new-user', [
            'email' => $this->userEmail,
            'role' => $this->userRoleName,
        ]);

        // Emit process started event
        $this->eventDispatcher->dispatch(
            new UserCreationProcessStartedEvent(
                $startTime,
                $this->userEmail,
                $this->userRoleName,
                $context
            )
        );

        try {
            $user = (new User())
                ->setEmail($this->userEmail)
                ->setPassword('')
                ->setBalance(0)
                ->setName('Admin')
                ->setSurname('Admin');

            // Assign role using RoleManager
            $role = $this->roleManager->getRoleByName($this->userRoleName);
            if ($role) {
                $user->addUserRole($role);
            } else {
                // Fallback to user role if specified role doesn't exist
                $defaultRole = $this->roleManager->getRoleByName(SystemRoleEnum::ROLE_USER->value);
                if ($defaultRole) {
                    $user->addUserRole($defaultRole);
                }
            }

            $hashedPassword = $this->passwordHasher->hashPassword($user, $this->userPassword);
            $user->setPassword($hashedPassword);

            $hasPterodactylAccount = false;
            $hasApiKey = false;
            $createdWithoutApiKey = false;

            try {
                $pterodactylAccount = $this->pterodactylAccountService->createPterodactylAccount($user, $this->userPassword);
            } catch (Exception $exception) {
                $message = 'Could not create Pterodactyl account: ' . $exception->getMessage();

                $this->eventDispatcher->dispatch(
                    new UserCreationProcessFailedEvent(
                        $message,
                        $this->userEmail,
                        $this->userRoleName,
                        new DateTimeImmutable(),
                        $context
                    )
                );

                throw new RuntimeException($message);
            }

            if (!empty($pterodactylAccount->id)) {
                $user->setPterodactylUserId($pterodactylAccount->id);
                $hasPterodactylAccount = true;

                try {
                    $pterodactylClientApiKey = $this->pterodactylClientApiKeyService->createClientApiKey($user);
                    $user->setPterodactylUserApiKey($pterodactylClientApiKey);
                    $hasApiKey = true;
                } catch (CouldNotCreatePterodactylClientApiKeyException $exception) {
                    if (!$allowToCreateWithNoPterodactylApiKey) {
                        // Rollback: delete Pterodactyl account
                        try {
                            $this->pterodactylAccountService->deletePterodactylAccount($user);
                        } catch (Exception $rollbackException) {
                            $failureMessage = sprintf(
                                'Could not create API key AND rollback failed: %s. Original error: %s',
                                $rollbackException->getMessage(),
                                $exception->getMessage()
                            );

                            $this->eventDispatcher->dispatch(
                                new UserCreationProcessFailedEvent(
                                    $failureMessage,
                                    $this->userEmail,
                                    $this->userRoleName,
                                    new DateTimeImmutable(),
                                    $context
                                )
                            );

                            throw new RuntimeException($failureMessage);
                        }

                        $this->eventDispatcher->dispatch(
                            new UserCreationProcessFailedEvent(
                                $exception->getMessage(),
                                $this->userEmail,
                                $this->userRoleName,
                                new DateTimeImmutable(),
                                $context
                            )
                        );

                        throw $exception;
                    }

                    // User chose to continue without API key
                    $createdWithoutApiKey = true;
                }
            }

            $this->userRepository->save($user);

            $endTime = new DateTimeImmutable();
            $duration = $endTime->getTimestamp() - $startTime->getTimestamp();

            // Emit process completed event
            $this->eventDispatcher->dispatch(
                new UserCreationProcessCompletedEvent(
                    $user->getId() ?? 0,
                    $this->userEmail,
                    $this->userRoleName,
                    $hasPterodactylAccount,
                    $hasApiKey,
                    $createdWithoutApiKey,
                    $duration,
                    $endTime,
                    $context
                )
            );
        } catch (RuntimeException|CouldNotCreatePterodactylClientApiKeyException $e) {
            // Already emitted FailedEvent, just re-throw
            throw $e;
        } catch (Exception $e) {
            // Unexpected exception
            $this->eventDispatcher->dispatch(
                new UserCreationProcessFailedEvent(
                    $e->getMessage(),
                    $this->userEmail,
                    $this->userRoleName,
                    new DateTimeImmutable(),
                    $context
                )
            );

            throw $e;
        }
    }

    public function setUserCredentials(string $email, string $password, string $userRoleName): void
    {
        $this->userEmail = $email;
        $this->userPassword = $password;
        $this->userRoleName = $userRoleName;
    }
}
