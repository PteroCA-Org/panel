<?php

namespace App\Core\Tests\Unit\Service\Pterodactyl;

use App\Core\Contract\Pterodactyl\Application\PterodactylAdapterInterface;
use App\Core\Contract\Pterodactyl\Application\PterodactylPterocaInterface;
use App\Core\Service\Pterodactyl\PterocaAddonVersionChecker;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class PterocaAddonVersionCheckerTest extends TestCase
{
    public function testInstalledVersionIsCached(): void
    {
        $pteroca = $this->createMock(PterodactylPterocaInterface::class);
        $pteroca->expects($this->once())
            ->method('getVersion')
            ->willReturn(['version' => '0.1.3']);

        $checker = $this->createChecker($pteroca);

        $this->assertSame('0.1.3', $checker->getInstalledVersion());
        $this->assertSame('0.1.3', $checker->getInstalledVersion());
    }

    public function testMissingVersionIsCachedAsUnknown(): void
    {
        $pteroca = $this->createMock(PterodactylPterocaInterface::class);
        $pteroca->expects($this->once())
            ->method('getVersion')
            ->willReturn([]);

        $checker = $this->createChecker($pteroca);

        $this->assertNull($checker->getInstalledVersion());
        $this->assertNull($checker->getInstalledVersion());
    }

    public function testApiFailureIsCachedAsUnknown(): void
    {
        $pteroca = $this->createMock(PterodactylPterocaInterface::class);
        $pteroca->expects($this->once())
            ->method('getVersion')
            ->willThrowException(new RuntimeException('panel unavailable'));

        $checker = $this->createChecker($pteroca);

        $this->assertNull($checker->getInstalledVersion());
        $this->assertNull($checker->getInstalledVersion());
    }

    public function testNonScalarVersionIsUnknown(): void
    {
        $pteroca = $this->createMock(PterodactylPterocaInterface::class);
        $pteroca->method('getVersion')->willReturn(['version' => ['0.1.3']]);

        $this->assertNull($this->createChecker($pteroca)->getInstalledVersion());
    }

    private function createChecker(PterodactylPterocaInterface $pteroca): PterocaAddonVersionChecker
    {
        $api = $this->createMock(PterodactylAdapterInterface::class);
        $api->method('pteroca')->willReturn($pteroca);

        $applicationService = $this->createMock(PterodactylApplicationService::class);
        $applicationService->method('getApplicationApi')->willReturn($api);

        return new PterocaAddonVersionChecker($applicationService, new ArrayAdapter());
    }
}
