<?php

namespace App\Core\Service\Authorization;

use DateTimeInterface;
use Exception;
use Lcobucci\JWT\Token\Plain;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\SystemRoleEnum;
use Lcobucci\JWT\Configuration;
use App\Core\Enum\LogActionEnum;
use App\Core\Contract\UserInterface;
use App\Core\Service\SettingService;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use App\Core\Service\Logs\LogService;
use Lcobucci\JWT\Signer\Key\InMemory;
use App\Core\Service\User\UserService;
use App\Core\Repository\UserRepository;
use App\Core\Event\User\Registration\UserRegisteredEvent;
use App\Core\Enum\EmailVerificationValueEnum;
use App\Core\Event\User\Registration\UserEmailVerifiedEvent;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Core\Event\User\Registration\UserRegistrationFailedEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Event\User\Registration\UserRegistrationRequestedEvent;
use App\Core\Event\User\Registration\UserRegistrationValidatedEvent;
use App\Core\DTO\Action\Result\RegisterUserActionResult;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class RegistrationService
{
    private Configuration $jwtConfiguration;

    private const JWT_ISSUER = 'pteroca';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
        private readonly LogService $logService,
        private readonly SettingService $settingService,
        private readonly UserService $userService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
    ) {
        $this->jwtConfiguration = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($_ENV['APP_SECRET']),
        );
    }

    public function registerUser(
        UserInterface $user,
        string $plainPassword,
        array $roles = [SystemRoleEnum::ROLE_USER->value],
        bool $isVerified = false
    ): RegisterUserActionResult
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = [
            'ip' => $request?->getClientIp(),
            'userAgent' => $request?->headers->get('User-Agent'),
            'locale' => $request?->getLocale(),
            'source' => 'web',
        ];

        $requestedEvent = new UserRegistrationRequestedEvent($user->getEmail(), $context);
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isRejected()) {
            return new RegisterUserActionResult(
                success: false,
                error: $requestedEvent->getRejectionReason(),
            );
        }

        $existingDeletedUser = $this->userRepository->findDeletedByEmail($user->getEmail());
        
        if ($existingDeletedUser) {
            return $this->reactivateUser($existingDeletedUser, $plainPassword, $roles, $isVerified);
        }

        $validatedEvent = new UserRegistrationValidatedEvent(
            $user->getEmail(),
            strtolower($user->getEmail()),
            $roles,
            $context
        );
        $this->eventDispatcher->dispatch($validatedEvent);

        if ($validatedEvent->isRejected()) {
            return new RegisterUserActionResult(
                success: false,
                error: $validatedEvent->getRejectionReason(),
            );
        }

        // Plugins may have modified roles
        $user->setIsVerified($isVerified);
        $user->setRoles($validatedEvent->getRoles());

        try {
            $this->userService->createUserWithPterodactylAccount($user, $plainPassword);
        } catch (Exception $exception) {
            $failedEvent = new UserRegistrationFailedEvent(
                $user->getEmail(),
                $exception->getMessage(),
                'user_creation',
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            return new RegisterUserActionResult(
                success: false,
                error: $exception->getMessage(),
            );
        }

        // UserAboutToBeCreatedEvent, UserCreatedEvent and UserRegisteredEvent
        // are emitted automatically by UserEventSubscriber
        // Email sending is handled by UserRegistrationSubscriber
        $this->userRepository->save($user);
        $this->logService->logAction($user, LogActionEnum::USER_REGISTERED);

        return new RegisterUserActionResult(
            success: true,
            user: $user,
        );
    }

    private function reactivateUser(
        UserInterface $deletedUser,
        string $plainPassword,
        array $roles,
        bool $isVerified
    ): RegisterUserActionResult
    {
        try {
            $deletedUser->restore();
            $deletedUser->setIsVerified($isVerified);
            $deletedUser->setRoles($roles);
            
            if (!empty($plainPassword)) {
                $deletedUser->setPlainPassword($plainPassword);
                $this->userService->createOrRestoreUser($deletedUser, $plainPassword);
            }

            // NOTE: For reactivation, UserEventSubscriber does NOT emit events automatically,
            // because this is an UPDATE, not INSERT (prePersist/postPersist are not triggered)
            $this->userRepository->save($deletedUser);
            $this->logService->logAction($deletedUser, LogActionEnum::USER_REGISTERED);

            // Manually emit UserRegisteredEvent for reactivated user
            $request = $this->requestStack->getCurrentRequest();
            $registeredEvent = new UserRegisteredEvent(
                $deletedUser->getId(),
                $deletedUser->getEmail(),
                $deletedUser->isVerified(),
                [
                    'ip' => $request?->getClientIp(),
                    'userAgent' => $request?->headers->get('User-Agent'),
                    'locale' => $request?->getLocale(),
                    'source' => 'reactivation',
                ]
            );
            $this->eventDispatcher->dispatch($registeredEvent);

            return new RegisterUserActionResult(
                success: true,
                user: $deletedUser,
            );
        } catch (Exception $exception) {
            return new RegisterUserActionResult(
                success: false,
                error: $exception->getMessage(),
            );
        }
    }

    public function verifyEmail(string $token): void
    {
        try {
            $token = $this->jwtConfiguration->parser()->parse($token);
        } catch (Exception) {
            throw new RuntimeException($this->translator->trans('pteroca.register.verification_token_invalid'));
        }

        assert($token instanceof Plain);
        $constraints = [
            new IssuedBy(self::JWT_ISSUER),
        ];

        if (!$this->jwtConfiguration->validator()->validate($token, ...$constraints)) {
            throw new RuntimeException($this->translator->trans('pteroca.register.verification_token_invalid'));
        }

        if ($token->claims()->has('exp')) {
            $expiry = $token->claims()->get('exp');
            $expiryTimestamp = $expiry instanceof DateTimeInterface ? $expiry->getTimestamp() : (int) $expiry;
            if ($expiryTimestamp < time()) {
                throw new RuntimeException($this->translator->trans('pteroca.register.verification_token_invalid'));
            }
        }

        if (!$this->jwtConfiguration->signer()->verify(
            $token->signature()->hash(),
            $token->payload(),
            $this->jwtConfiguration->signingKey()
        )) {
            throw new RuntimeException($this->translator->trans('pteroca.register.verification_token_invalid'));
        }

        $userId = $token->claims()->get('uid');
        $user = $this->userRepository->find($userId);
        if (empty($user) || $user->isVerified()) {
            throw new RuntimeException($this->translator->trans('pteroca.register.verification_token_invalid'));
        }

        $user->setIsVerified(true);
        $this->userRepository->save($user);
        $this->logService->logAction($user, LogActionEnum::USER_VERIFY_EMAIL);

        $request = $this->requestStack->getCurrentRequest();
        $verifiedEvent = new UserEmailVerifiedEvent(
            $user->getId(),
            $user->getEmail(),
            [
                'ip' => $request?->getClientIp(),
                'userAgent' => $request?->headers->get('User-Agent'),
            ]
        );
        $this->eventDispatcher->dispatch($verifiedEvent);
    }

    public function getEmailVerificationMode(): string
    {
        return EmailVerificationValueEnum::tryFrom(
            $this->settingService->getSetting(SettingEnum::REQUIRE_EMAIL_VERIFICATION->value)
        )?->value ?? EmailVerificationValueEnum::DISABLED->value;
    }
}
