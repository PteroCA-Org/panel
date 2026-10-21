<?php

namespace App\Core\Tests\Integration\Controller;

use App\Core\Entity\User;
use App\Core\Entity\Server;
use App\Core\Entity\Product;
use App\Core\Entity\Category;
use App\Core\Tests\Integration\BaseTestCase;

class ServerControllerTest extends BaseTestCase
{
    public function testServersPageLoadsForAuthenticatedUser(): void
    {
        $user = $this->createTestUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/panel?routeName=servers');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.container.mt-4');
    }

    public function testServersPageRedirectsForUnauthenticatedUser(): void
    {
        $this->client->request('GET', '/panel?routeName=servers');

        $this->assertResponseRedirects('/login');
    }

    public function testServersAreDisplayedCorrectly(): void
    {
        $user = $this->createTestUser();
        $this->client->loginUser($user);

        $product = $this->createTestProduct();
        $server1 = $this->createTestServer($user, $product, 'Server 1');
        $server2 = $this->createTestServer($user, $product, 'Server 2');

        $crawler = $this->client->request('GET', '/panel?routeName=servers');

        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('.card'));
        $this->assertSelectorTextContains('.card-title', 'Test Product Active');
        $this->assertEquals(2, $crawler->filter('.badge-success')->count());
    }

    public function testNoServersMessageIsDisplayed(): void
    {
        $user = $this->createTestUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/panel?routeName=servers');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert.alert-info');
        $this->assertSelectorTextContains('.alert.alert-info', 'You do not have any servers yet.');
    }

    private function createTestUser(string $email = 'testuser@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(
            $this->getContainer()->get('security.password_hasher')->hashPassword($user, 'password')
        );
        $user->setRoles(['ROLE_USER']);
        $user->setName('Test');
        $user->setSurname('User');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTestProduct(): Product
    {
        $product = new Product();
        $product->setName('Test Product Active');
        $product->setImagePath('test-product.jpg');
        $product->setPrice(5.00);
        $product->setDiskSpace(1024);
        $product->setMemory(1024);
        $product->setBackups(1);
        $product->setCpu(1);
        $product->setDbCount(1);
        $product->setSwap(128);
        $product->setPorts(1);
        $product->setUpdatedAtValue();
        $product->setCategory($this->createTestCategory());
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function createTestCategory(): Category
    {
        $category = new Category();
        $category->setName('Test Category');
        $category->setDescription('This is a test category.');
        $category->setImagePath('test-category.jpg');
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function createTestServer(User $user, Product $product, string $name): Server
    {
        $server = new Server();
        $server->setUser($user);
        $server->setProduct($product);
        $server->setPterodactylServerIdentifier(bin2hex(random_bytes(16)));
        $server->setPterodactylServerId(random_int(1, 1000));
        $server->setExpiresAt(new \DateTime('+1 month'));
        $server->setIsSuspended(false);
        $this->entityManager->persist($server);
        $this->entityManager->flush();

        return $server;
    }
}
