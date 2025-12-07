<?php

namespace App\Core\Tests\Unit\Service\Authorization;

use App\Core\Entity\User;
use App\Core\Enum\LogActionEnum;
use App\Core\Message\SendEmailMessage;
use App\Core\Repository\UserRepository;
use App\Core\Service\Authorization\RegistrationService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Pterodactyl\PterodactylAccountService;
use App\Core\Service\Pterodactyl\PterodactylClientApiKeyService;
use App\Core\Service\SettingService;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationServiceTest extends TestCase
{
    private UserPasswordHasherInterface $userPasswordHasher;
    private PterodactylAccountService $pterodactylAccountService;
    private PterodactylClientApiKeyService $pterodactylClientApiKeyService;
    private UserRepository $userRepository;
    private TranslatorInterface $translator;
    private LogService $logService;
    private SettingService $settingService;
    private MessageBusInterface $messageBus;
    private LoggerInterface $logger;
    private RegistrationService $registrationService;

    protected function setUp(): void
    {
        $this->userPasswordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->pterodactylAccountService = $this->createMock(PterodactylAccountService::class);
        $this->pterodactylClientApiKeyService = $this->createMock(PterodactylClientApiKeyService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->logService = $this->createMock(LogService::class);
        $this->settingService = $this->createMock(SettingService::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->registrationService = new RegistrationService(
            $this->userPasswordHasher,
            $this->userRepository,
            $this->translator,
            $this->logService,
            $this->settingService,
            $this->pterodactylAccountService,
            $this->pterodactylClientApiKeyService,
            $this->messageBus,
            $this->logger
        );
    }

    public function testRegisterUser(): void
    {
        $userEmail = 'test@test.com';
        $user = (new User())
            ->setEmail($userEmail)
            ->setName('Test User')
            ->setSurname('Test Surname');
        $plainPassword = 'password';
        $hashedPassword = 'hashed_password';
        $pterodactylUserId = 123;

        $this->userPasswordHasher
            ->method('hashPassword')
            ->with($user, $plainPassword)
            ->willReturn($hashedPassword);

        $pterodactylAccount = new \Timdesm\PterodactylPhpApi\Resources\User([]);
        $pterodactylAccount->set('id', $pterodactylUserId);

        $this->pterodactylAccountService
            ->method('createPterodactylAccount')
            ->with($user, $plainPassword)
            ->willReturn($pterodactylAccount);

        $this->pterodactylClientApiKeyService
            ->method('createClientApiKey')
            ->with($user)
            ->willReturn('api_key');

        $this->userRepository
            ->expects($this->once())
            ->method('save')
            ->with($user);

        $this->logService
            ->expects($this->once())
            ->method('logAction')
            ->with($user, LogActionEnum::USER_REGISTERED);

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendEmailMessage::class))
            ->willReturn(new Envelope(new SendEmailMessage(
                'test@example.com',
                'subject',
                'email/registration.html.twig',
                []
            ), [new HandledStamp('result', 'handler_name')]));

        $result = $this->registrationService->registerUser($user, $plainPassword);

        $this->assertSame($hashedPassword, $user->getPassword());
        $this->assertFalse($user->isVerified());
        $this->assertSame([UserRoleEnum::ROLE_USER->name], $user->getRoles());
        $this->assertSame($user, $result->user);
    }

    public function testVerifyEmailWithValidToken(): void
    {
        $user = $this->createMock(User::class);
        $token = $this->createVerificationToken($user);

        $this->userRepository
            ->method('find')
            ->with($user->getId())
            ->willReturn($user);

        $user->method('isVerified')
            ->willReturn(false);

        $this->userRepository
            ->expects($this->once())
            ->method('save')
            ->with($user);

        $this->logService
            ->expects($this->once())
            ->method('logAction')
            ->with($user, LogActionEnum::USER_VERIFY_EMAIL);

        $this->registrationService->verifyEmail($token);
    }

    public function testVerifyEmailWithInvalidToken(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->translator
            ->method('trans')
            ->with('pteroca.register.verification_token_invalid')
            ->willReturn('Invalid token.');

        $this->registrationService->verifyEmail('invalid_token');
    }

    public function testUserExistsReturnsTrue(): void
    {
        $email = 'test@example.com';

        $this->userRepository
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn(new User());

        $result = $this->registrationService->userExists($email);

        $this->assertTrue($result);
    }

    public function testUserExistsReturnsFalse(): void
    {
        $email = 'test@example.com';

        $this->userRepository
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn(null);

        $result = $this->registrationService->userExists($email);

        $this->assertFalse($result);
    }

    private function createVerificationToken(User $user): string
    {
        $jwtConfiguration = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($_ENV['APP_SECRET']),
        );

        $now = new DateTimeImmutable();
        $token = $jwtConfiguration->builder()
            ->issuedBy('pteroca')
            ->issuedAt($now)
            ->withClaim('uid', $user->getId())
            ->getToken($jwtConfiguration->signer(), $jwtConfiguration->signingKey());

        return $token->toString();
    }
}