<?php

namespace App\Core\Tests\Unit\Adapter\Pterodactyl;

use App\Core\Adapter\Pterodactyl\Client\PterodactylUsers;
use App\Core\DTO\Pterodactyl\Credentials;
use App\Core\Exception\Pterodactyl\PterodactylValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AbstractPterodactylClientAdapterTest extends TestCase
{
    private const SUBUSER_PAYLOAD = [
        'object' => 'server_subuser',
        'attributes' => [
            'uuid' => 'dfee6978-a6e0-4661-a34b-739decc6b9da',
            'email' => 'subuser@example.com',
            'created_at' => '2026-10-21T19:44:40+02:00',
            'permissions' => ['control.console'],
        ],
    ];

    public function testCreateSubuserAcceptsPterodactyl200Response(): void
    {
        $subuser = $this->createUsersAdapter(200)->createUser('837ab60c', 'subuser@example.com', ['control.console']);

        $this->assertSame('subuser@example.com', $subuser->getEmail());
    }

    public function testCreateSubuserAccepts201Response(): void
    {
        $subuser = $this->createUsersAdapter(201)->createUser('837ab60c', 'subuser@example.com', ['control.console']);

        $this->assertSame('dfee6978-a6e0-4661-a34b-739decc6b9da', $subuser->getUuid());
    }

    public function testCreateSubuserRejectsErrorResponse(): void
    {
        $this->expectException(PterodactylValidationException::class);

        $this->createUsersAdapter(422)->createUser('837ab60c', 'subuser@example.com', ['control.console']);
    }

    private function createUsersAdapter(int $statusCode): PterodactylUsers
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn(self::SUBUSER_PAYLOAD);
        $response->method('getContent')->willReturn(json_encode(self::SUBUSER_PAYLOAD));
        $response->method('getInfo')->willReturn(null);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->with('POST', 'https://panel.example.com/api/client/servers/837ab60c/users')
            ->willReturn($response);

        return new PterodactylUsers($httpClient, new Credentials('https://panel.example.com', 'ptlc_test'));
    }
}
