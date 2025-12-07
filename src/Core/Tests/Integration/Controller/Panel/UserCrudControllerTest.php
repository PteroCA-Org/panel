<?php

namespace App\Core\Tests\Integration\Controller\Panel;

use App\Core\Controller\Panel\UserCrudController;
use App\Core\Entity\User;
use App\Core\Service\Pterodactyl\PterodactylService;
use App\Core\Service\Pterodactyl\PterodactylUsernameService;
use App\Core\Tests\Integration\BaseTestCase;
use Timdesm\PterodactylPhpApi\Resources\User as PterodactylUser;
use Timdesm\PterodactylPhpApi\PterodactylApi;

class UserCrudControllerTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pterodactylApiMock = $this->createMock(PterodactylApi::class);
        $pterodactylApiMock->users = $this->mockPterodactylUserManager();

        $pterodactylServiceMock = $this->createMock(PterodactylService::class);
        $pterodactylServiceMock->method('getApi')->willReturn($pterodactylApiMock);
        $this->getContainer()->set(PterodactylService::class, $pterodactylServiceMock);

        $usernameServiceMock = $this->createMock(PterodactylUsernameService::class);
        $usernameServiceMock->method('generateUsername')->willReturn('generated_username');
        $this->getContainer()->set(PterodactylUsernameService::class, $usernameServiceMock);
    }

    public function testAccessDeniedForNonAdminUser(): void
    {
        $user = $this->createTestUser(['ROLE_USER']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/panel?crudControllerFqcn=' . urlencode(UserCrudController::class) . '&crudAction=new');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAccessGrantedForAdminUser(): void
    {
        $adminUser = $this->createTestUser(['ROLE_ADMIN']);
        $this->client->loginUser($adminUser);

        $this->client->request('GET', '/panel?crudControllerFqcn=' . urlencode(UserCrudController::class) . '&crudAction=new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreateUser(): void
    {
        $adminUser = $this->createTestUser(['ROLE_ADMIN']);
        $this->client->loginUser($adminUser);

        $crawler = $this->client->request('GET', '/panel?crudControllerFqcn=' . urlencode(UserCrudController::class) . '&crudAction=new');
        $form = $crawler->selectButton('Add user')->form([
            'User[email]' => 'testuser@example.com',
            'User[plainPassword]' => 'testpassword123',
            'User[name]' => 'Test',
            'User[surname]' => 'User',
            'User[roles]' => [UserRoleEnum::ROLE_USER->name],
            'User[balance]' => '100.00',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/panel?crudAction=index&crudControllerFqcn=' . urlencode(UserCrudController::class));

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'testuser@example.com']);
        $this->assertNotNull($user);
        $this->assertEquals('testuser@example.com', $user->getEmail());
        $this->assertEquals('Test', $user->getName());
        $this->assertEquals('User', $user->getSurname());
        $this->assertEquals(100.0, $user->getBalance());
    }

    public function testEditUser(): void
    {
        $adminUser = $this->createTestUser(['ROLE_ADMIN']);
        $this->client->loginUser($adminUser);

        $user = $this->createTestUser(['ROLE_USER'], 'edituser@example.com');

        $crawler = $this->client->request('GET', '/panel?crudAction=edit&crudControllerFqcn=' . urlencode(UserCrudController::class) . '&entityId=' . $user->getId());
        $form = $crawler->selectButton('Save user')->form([
            'User[name]' => 'Updated Name',
            'User[surname]' => 'Updated Surname',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/panel?crudAction=index&crudControllerFqcn=' . urlencode(UserCrudController::class) . '&entityId=' . $user->getId());

        $updatedUser = $this->entityManager->getRepository(User::class)->find($user->getId());
        $this->assertEquals('Updated Name', $updatedUser->getName());
        $this->assertEquals('Updated Surname', $updatedUser->getSurname());
    }

    private function createTestUser(array $roles = ['ROLE_ADMIN'], string $email = 'admin@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(
            $this->getContainer()->get('security.password_hasher')->hashPassword($user, 'password')
        );
        $user->setRoles($roles);
        $user->setName('Admin');
        $user->setSurname('User');
        $user->setBalance(0);
        $user->setPterodactylUserId(1);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function mockPterodactylUserManager()
    {
        $userMock = $this->createMock(PterodactylUser::class);
        $userMock->id = 1234;
        $userMock->username = 'generated_username';

        $userManagerMock = $this->getMockBuilder('stdClass')
            ->addMethods(['create', 'get', 'update', 'delete'])
            ->getMock();

        $userManagerMock->method('create')->willReturn($userMock);
        $userManagerMock->method('get')->willReturn($userMock);
        $userManagerMock->method('update')->willReturn(true);
        $userManagerMock->method('delete')->willReturn(true);

        return $userManagerMock;
    }
}
