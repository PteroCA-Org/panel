<?php

namespace App\Core\Tests\Unit\Adapter\Pterodactyl;

use App\Core\Adapter\Pterodactyl\Application\AbstractPterodactylApplicationAdapter;
use App\Core\Adapter\Pterodactyl\Application\PterodactylUsers;
use App\Core\DTO\Pterodactyl\Credentials;
use App\Core\Exception\Pterodactyl\PterodactylAuthenticationException;
use App\Core\Exception\Pterodactyl\PterodactylNotFoundException;
use App\Core\Exception\Pterodactyl\PterodactylServerException;
use App\Core\Exception\Pterodactyl\PterodactylValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AbstractPterodactylApplicationAdapterTest extends TestCase
{
    public function testEmpty204ResponseDoesNotDecodeJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(204);
        $response->expects($this->never())->method('toArray');
        $response->expects($this->never())->method('getContent');

        $this->assertSame([], $this->adapter()->validate($response, 204));
    }

    public function testDeleteApiKeyAcceptsEmpty204(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(204);
        $response->expects($this->never())->method('toArray');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('DELETE', 'https://panel.example.com/api/application/users/15/api-keys/identifier12')
            ->willReturn($response);

        $users = new PterodactylUsers(
            $httpClient,
            new Credentials('https://panel.example.com', 'ptla_test')
        );

        $this->assertTrue($users->deleteApiKeyForUser(15, 'identifier12'));
    }

    public function testJsonSuccessResponseIsStillDecoded(): void
    {
        $payload = ['version' => '0.1.4'];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('toArray')->willReturn($payload);

        $this->assertSame($payload, $this->adapter()->validate($response, 200));
    }

    public function testEmpty200ResponseStillFails(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willThrowException(new JsonException('Response body is empty.'));

        $this->expectException(JsonException::class);
        $this->adapter()->validate($response, 200);
    }

    /**
     * @dataProvider errorStatusProvider
     */
    public function testUnexpectedStatusStillThrows(int $statusCode, string $exceptionClass): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn('{"errors":[{"detail":"nope"}]}');
        $response->method('getInfo')->willReturnMap([
            ['url', 'https://panel.example.com/api/application/users/1/api-keys/abc'],
            ['http_method', 'DELETE'],
        ]);
        $response->expects($this->never())->method('toArray');

        $this->expectException($exceptionClass);
        $this->adapter()->validate($response, 204);
    }

    public function errorStatusProvider(): array
    {
        return [
            'unauthorized' => [401, PterodactylAuthenticationException::class],
            'not found' => [404, PterodactylNotFoundException::class],
            'validation' => [422, PterodactylValidationException::class],
            'server error' => [500, PterodactylServerException::class],
        ];
    }

    private function adapter(): AbstractPterodactylApplicationAdapter
    {
        return new class(
            $this->createMock(HttpClientInterface::class),
            new Credentials('https://panel.example.com', 'ptla_test')
        ) extends AbstractPterodactylApplicationAdapter {
            public function validate(ResponseInterface $response, int $expectedStatusCode): array
            {
                return $this->validateServerResponse($response, $expectedStatusCode);
            }
        };
    }
}
