<?php

namespace App\Core\Tests\Unit\Twig;

use App\Core\Service\DateFormatterService;
use App\Core\Service\Plugin\PluginAssetManager;
use App\Core\Service\PriceFormatterService;
use App\Core\Service\Pterodactyl\PterocaAddonVersionChecker;
use App\Core\Service\Pterodactyl\PterodactylRedirectService;
use App\Core\Service\SettingService;
use App\Core\Service\System\SystemVersionService;
use App\Core\Service\Template\CurrentThemeService;
use App\Core\Service\Template\TemplateManager;
use App\Core\Twig\AppExtension;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\RouterInterface;

class AppExtensionTest extends TestCase
{
    private AppExtension $extension;

    protected function setUp(): void
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('getSetting')->willReturnMap([
            ['date_format', 'Y-m-d H:i'],
            ['date_timezone', 'UTC'],
            ['date_show_timezone', '0'],
        ]);

        $this->extension = new AppExtension(
            $this->createMock(SystemVersionService::class),
            $settingService,
            $this->createMock(TemplateManager::class),
            $this->createReadonlyStub(CurrentThemeService::class),
            $this->createMock(Packages::class),
            $this->createMock(RouterInterface::class),
            $this->createMock(PterodactylRedirectService::class),
            $this->createReadonlyStub(PluginAssetManager::class),
            new DateFormatterService($settingService),
            $this->createMock(PriceFormatterService::class),
            $this->createReadonlyStub(PterocaAddonVersionChecker::class),
        );
    }

    /**
     * PHPUnit 9 cannot double readonly classes; these dependencies are not used by the tested filters.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function createReadonlyStub(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }

    public function testFormatDateAcceptsDateTime(): void
    {
        $date = new DateTimeImmutable('2026-08-03 12:30:00', new \DateTimeZone('UTC'));

        $this->assertSame('2026-08-03 12:30', $this->extension->formatDate($date));
    }

    public function testFormatDateAcceptsPterodactylIsoString(): void
    {
        $this->assertSame('2026-08-03 10:30', $this->extension->formatDate('2026-08-03T12:30:00+02:00'));
    }

    public function testFormatDateReturnsDashForEmptyValues(): void
    {
        $this->assertSame('-', $this->extension->formatDate(null));
        $this->assertSame('-', $this->extension->formatDate(''));
    }

    public function testFormatDateReturnsUnparsableStringUnchanged(): void
    {
        $this->assertSame('not a date', $this->extension->formatDate('not a date'));
    }
}
