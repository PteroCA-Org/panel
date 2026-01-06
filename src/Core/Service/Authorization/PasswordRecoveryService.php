<?php

namespace App\Core\Service\Authorization;

use App\Core\Contract\UserInterface;
use App\Core\Entity\PasswordResetRequest;
use App\Core\Enum\EmailTypeEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Event\PasswordRecovery\PasswordAboutToBeChangedEvent;
use App\Core\Event\PasswordRecovery\PasswordChangedEvent;
use App\Core\Event\PasswordRecovery\PasswordResetCompletedEvent;
use App\Core\Event\PasswordRecovery\PasswordResetEmailSentEvent;
use App\Core\Event\PasswordRecovery\PasswordResetFailedEvent;
use App\Core\Event\PasswordRecovery\PasswordResetRequestedEvent;
use App\Core\Event\PasswordRecovery\PasswordResetTokenGeneratedEvent;
use App\Core\Event\PasswordRecovery\PasswordResetValidatedEvent;
use App\Core\Message\SendEmailMessage;
use App\Core\Repository\PasswordResetRequestRepository;
use App\Core\Repository\UserRepository;
use App\Core\Service\Email\EmailNotificationService;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\SettingService;
use App\Core\Service\User\UserService;
use DateTime;
use DateTimeInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\ByteString;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PasswordRecoveryService
{
    private const PASSWORD_RESET_TOKEN_LIFETIME_HOURS = 1;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetRequestRepository $passwordResetRequestRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly SettingService $settingService,
        private readonly LoggerInterface $logger,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EventContextService $eventContextService,
        private readonly RequestStack $requestStack,
        private readonly UserService $userService,
    ) {}

    /**
     * @throws Exception
     * @throws ExceptionInterface
     */
    public function createRecoveryRequest(string $email): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $this->eventContextService->buildNullableContext($request);

        try {
            $userAccount = $this->userRepository->findOneBy(['email' => $email]);

            // Emit PasswordResetRequestedEvent (pre-event)
            $this->eventDispatcher->dispatch(new PasswordResetRequestedEvent(
                $userAccount?->getId(),
                $email,
                $context
            ));

            if (empty($userAccount)) {
                return;
            }

            if ($this->passwordResetRequestRepository->hasActiveRequest($userAccount)) {
                return;
            }

            $token = ByteString::fromRandom(32)->toString();
            $expiresAt = (new DateTime())->modify(sprintf('+%d hours', self::PASSWORD_RESET_TOKEN_LIFETIME_HOURS));

            // Emit PasswordResetTokenGeneratedEvent (post-event)
            $this->eventDispatcher->dispatch(new PasswordResetTokenGeneratedEvent(
                $userAccount->getId(),
                $userAccount->getEmail(),
                hash('sha256', $token), // Hash token for security
                $expiresAt,
                $context
            ));

            $this->saveRecoveryRequest($userAccount, $token, $expiresAt);
            $this->sendRecoveryEmail($userAccount, $token);

            // Emit PasswordResetEmailSentEvent (post-commit)
            $this->eventDispatcher->dispatch(new PasswordResetEmailSentEvent(
                $userAccount->getId(),
                $userAccount->getEmail(),
                $context
            ));
        } catch (Exception $e) {
            // Emit PasswordResetFailedEvent (error)
            $this->eventDispatcher->dispatch(new PasswordResetFailedEvent(
                $email,
                $e->getMessage(),
                'create_recovery_request',
                $context
            ));

            $this->logger->error('Password reset request failed', [
                'email' => $email,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    public function validateRecoveryToken(string $token): bool
    {
        $passwordResetRequest = $this->passwordResetRequestRepository->findOneBy(['token' => $token]);
        if (empty($passwordResetRequest) || $passwordResetRequest->getIsUsed()) {
            return false;
        }

        if ($passwordResetRequest->getExpiresAt() < new DateTime()) {
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function updateUserPassword(string $token, string $password): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $this->eventContextService->buildNullableContext($request);

        $passwordResetRequest = null;
        try {
            $passwordResetRequest = $this->passwordResetRequestRepository->findOneBy(['token' => $token]);
            if (empty($passwordResetRequest)) {
                // Emit PasswordResetValidatedEvent with invalid token
                $this->eventDispatcher->dispatch(new PasswordResetValidatedEvent(
                    0, // Unknown user
                    false,
                    $context
                ));
                return false;
            }

            $user = $passwordResetRequest->getUser();

            // Emit PasswordResetValidatedEvent (pre-event)
            $this->eventDispatcher->dispatch(new PasswordResetValidatedEvent(
                $user->getId(),
                true,
                $context
            ));

            // Emit PasswordAboutToBeChangedEvent (pre, stoppable)
            $aboutToChangeEvent = new PasswordAboutToBeChangedEvent(
                $user->getId(),
                $user->getEmail(),
                $context
            );
            $this->eventDispatcher->dispatch($aboutToChangeEvent);

            // Check if plugin stopped the password change
            if ($aboutToChangeEvent->isPropagationStopped()) {
                $this->logger->warning('Password change stopped by plugin', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);
                return false;
            }

            // Update user password and sync with Pterodactyl using UserService
            $this->userService->updateUserInPterodactyl($user, $password);
            $this->userRepository->save($user);

            // Emit PasswordChangedEvent (post-commit)
            $this->eventDispatcher->dispatch(new PasswordChangedEvent(
                $user->getId(),
                $user->getEmail(),
                $context
            ));

            // Mark token as used
            $passwordResetRequest->setIsUsed(true);
            $this->passwordResetRequestRepository->save($passwordResetRequest);

            // Emit PasswordResetCompletedEvent (post-commit)
            $this->eventDispatcher->dispatch(new PasswordResetCompletedEvent(
                $user->getId(),
                $user->getEmail(),
                $passwordResetRequest->getId(),
                $context
            ));

            return true;
        } catch (Exception $e) {
            // Emit PasswordResetFailedEvent (error)
            $this->eventDispatcher->dispatch(new PasswordResetFailedEvent(
                $passwordResetRequest->getUser()?->getEmail() ?? null,
                $e->getMessage(),
                'update_user_password',
                $context
            ));

            $this->logger->error('Password update failed', [
                'token' => hash('sha256', $token),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function saveRecoveryRequest(UserInterface $user, string $token, DateTimeInterface $expiresAt): void
    {
        $passwordResetRequest = new PasswordResetRequest();
        $passwordResetRequest->setUser($user);
        $passwordResetRequest->setToken($token);
        $passwordResetRequest->setExpiresAt($expiresAt);
        $this->passwordResetRequestRepository->save($passwordResetRequest);
    }

    /**
     * @throws ExceptionInterface
     */
    private function sendRecoveryEmail(UserInterface $user, string $token): void
    {
        $emailMessage = new SendEmailMessage(
            $user->getEmail(),
            $this->translator->trans('pteroca.email.recovery.subject'),
            'email/reset_password.html.twig',
            ['recoveryUrl' => $this->generateRecoveryUrl($token), 'user' => $user],
        );
        try {
            $this->messageBus->dispatch($emailMessage);
            
            $this->emailNotificationService->logEmailSent(
                $user,
                EmailTypeEnum::RESET_PASSWORD,
                null,
                $this->translator->trans('pteroca.email.recovery.subject')
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to send recovery email', ['exception' => $e]);
        }
    }

    private function generateRecoveryUrl(string $token): string
    {
        return sprintf(
            '%s/reset-password/%s',
            $this->settingService->getSetting(SettingEnum::SITE_URL->value),
            $token,
        );
    }
}
