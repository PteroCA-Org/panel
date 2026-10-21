<?php

namespace App\Core\Tests\Unit\Service\Pterodactyl;

use App\Core\Service\Pterodactyl\PterocaAddonSecurity;
use PHPUnit\Framework\TestCase;

class PterocaAddonSecurityTest extends TestCase
{
    public function testUpgradeCommandUsesTheMinimumSafeVersion(): void
    {
        $this->assertSame('0.1.4', PterocaAddonSecurity::MINIMUM_SAFE_VERSION);
        $this->assertSame(
            'composer require pteroca-com/pterodactyl-addon:^0.1.4',
            PterocaAddonSecurity::upgradeCommand()
        );
        $this->assertStringStartsWith('https://github.com/PteroCA-Org/pterodactyl-addon/security/advisories/', PterocaAddonSecurity::ADVISORY_URL);
    }

    /**
     * @dataProvider outdatedVersionProvider
     */
    public function testIsOutdated(string $version): void
    {
        $this->assertTrue(PterocaAddonSecurity::isOutdated($version));
    }

    /**
     * @dataProvider safeVersionProvider
     */
    public function testIsNotOutdated(string $version): void
    {
        $this->assertFalse(PterocaAddonSecurity::isOutdated($version));
    }

    /**
     * @dataProvider unknownVersionProvider
     */
    public function testUnknownVersionsAreNotTreatedAsOutdated(?string $version): void
    {
        $this->assertFalse(PterocaAddonSecurity::isOutdated($version));
        $this->assertNull(PterocaAddonSecurity::normalize($version));
    }

    public function outdatedVersionProvider(): array
    {
        return [
            'patch below floor' => ['0.1.3'],
            'leading v' => ['v0.1.3'],
            'leading V and whitespace' => [' V0.1.0 '],
            'older minor' => ['0.0.9'],
            'zero' => ['0'],
            'two-part version' => ['0.1'],
        ];
    }

    public function safeVersionProvider(): array
    {
        return [
            'exact floor' => ['0.1.4'],
            'leading v on floor' => ['v0.1.4'],
            'newer patch' => ['0.1.5'],
            'newer minor that string-compares lower' => ['0.1.10'],
            'newer minor' => ['0.2.0'],
            'major' => ['1.0.0'],
        ];
    }

    public function unknownVersionProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'dev branch' => ['dev-main'],
            'word' => ['unknown'],
        ];
    }
}
