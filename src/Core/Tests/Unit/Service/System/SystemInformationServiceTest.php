<?php

namespace App\Core\Tests\Unit\Service\System;

use App\Core\Contract\Pterodactyl\Application\PterodactylAdapterInterface;
use App\Core\Contract\Pterodactyl\Application\PterodactylLocationsInterface;
use App\Core\Contract\Pterodactyl\Application\PterodactylPterocaInterface;
use App\Core\DTO\Pterodactyl\Collection;
use App\Core\Service\Pterodactyl\PterocaAddonVersionChecker;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\System\SystemInformationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class SystemInformationServiceTest extends TestCase
{
    public function testGetSystemInformationMarksOutdatedAddon(): void
    {
        $systemInformation = $this->service('0.1.2')->getSystemInformation();

        $this->assertArrayHasKey('php', $systemInformation);
        $this->assertArrayHasKey('database', $systemInformation);
        $this->assertArrayHasKey('os', $systemInformation);
        $this->assertArrayHasKey('webserver', $systemInformation);
        $this->assertTrue($systemInformation['pterodactyl']['status']);
        $this->assertSame('0.1.2', $systemInformation['pteroca_plugin']['version']);
        $this->assertTrue($systemInformation['pteroca_plugin']['outdated']);
    }

    public function testMissingAddonVersionIsNotMarkedOutdated(): void
    {
        $systemInformation = $this->service(null)->getSystemInformation();

        $this->assertNull($systemInformation['pteroca_plugin']['version']);
        $this->assertFalse($systemInformation['pteroca_plugin']['outdated']);
    }

    private function service(?string $version): SystemInformationService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getServerVersion')->willReturn('8.0.36');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $locations = $this->createMock(PterodactylLocationsInterface::class);
        $locations->method('all')->willReturn(new Collection());

        $pteroca = $this->createMock(PterodactylPterocaInterface::class);
        $pteroca->method('getVersion')->willReturn($version === null ? [] : ['version' => $version]);

        $api = $this->createMock(PterodactylAdapterInterface::class);
        $api->method('locations')->willReturn($locations);
        $api->method('pteroca')->willReturn($pteroca);

        $pterodactyl = $this->createMock(PterodactylApplicationService::class);
        $pterodactyl->method('getApplicationApi')->willReturn($api);

        $checker = new PterocaAddonVersionChecker($pterodactyl, new ArrayAdapter());

        return new SystemInformationService($entityManager, $pterodactyl, $checker);
    }
}
