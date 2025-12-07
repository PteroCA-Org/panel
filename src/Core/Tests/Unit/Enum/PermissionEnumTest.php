<?php

namespace App\Core\Tests\Unit\Enum;

use App\Core\Enum\PermissionEnum;
use PHPUnit\Framework\TestCase;

class PermissionEnumTest extends TestCase
{
    /**
     * Test that enum has all core permissions from migration
     */
    public function testEnumCasesCount(): void
    {
        $cases = PermissionEnum::cases();

        // Verify we have all core system permissions
        $this->assertGreaterThanOrEqual(68, count($cases), 'Enum should have at least 68 core permissions');
    }

    /**
     * Test fromString conversion works correctly
     */
    public function testFromString(): void
    {
        // Test valid permission
        $this->assertSame(PermissionEnum::ACCESS_DASHBOARD, PermissionEnum::fromString('access_dashboard'));
        $this->assertSame(PermissionEnum::DELETE_USER, PermissionEnum::fromString('delete_user'));

        // Test invalid permission returns null
        $this->assertNull(PermissionEnum::fromString('invalid_permission'));
        $this->assertNull(PermissionEnum::fromString('PLUGIN_CUSTOM_PERMISSION'));
    }

    /**
     * Test getSection returns correct section for permissions
     */
    public function testGetSection(): void
    {
        $this->assertEquals('dashboard', PermissionEnum::ACCESS_DASHBOARD->getSection());
        $this->assertEquals('user_management', PermissionEnum::CREATE_USER->getSection());
        $this->assertEquals('shop', PermissionEnum::COPY_PRODUCT->getSection());
        $this->assertEquals('settings', PermissionEnum::EDIT_SETTINGS_EMAIL->getSection());
    }

    /**
     * Test getPermissionGroups returns all permissions organized by section
     */
    public function testGetPermissionGroups(): void
    {
        $groups = PermissionEnum::getPermissionGroups();

        // Check expected sections exist
        $this->assertArrayHasKey('dashboard', $groups);
        $this->assertArrayHasKey('user_management', $groups);
        $this->assertArrayHasKey('shop', $groups);
        $this->assertArrayHasKey('settings', $groups);

        // Check dashboard section contains correct permissions
        $this->assertContains(PermissionEnum::ACCESS_DASHBOARD, $groups['dashboard']);
        $this->assertContains(PermissionEnum::ACCESS_ADMIN_OVERVIEW, $groups['dashboard']);

        // Flatten all permissions from groups and count
        $allPermissions = array_merge(...array_values($groups));
        $this->assertGreaterThanOrEqual(68, count($allPermissions), 'All permissions should be in groups');
    }

    /**
     * Test isCorePermission correctly identifies core vs plugin permissions
     */
    public function testIsCorePermission(): void
    {
        // Test core permissions return true
        $this->assertTrue(PermissionEnum::isCorePermission('access_dashboard'));
        $this->assertTrue(PermissionEnum::isCorePermission('create_user'));
        $this->assertTrue(PermissionEnum::isCorePermission('copy_product'));

        // Test plugin permissions return false
        $this->assertFalse(PermissionEnum::isCorePermission('PLUGIN_CUSTOM_PERMISSION'));
        $this->assertFalse(PermissionEnum::isCorePermission('invalid_permission'));
    }

    /**
     * Test getAllCodes returns array of all permission codes
     */
    public function testGetAllCodes(): void
    {
        $codes = PermissionEnum::getAllCodes();

        // Should be array of strings
        $this->assertIsArray($codes);
        $this->assertContainsOnly('string', $codes);

        // Should contain expected permission codes
        $this->assertContains('access_dashboard', $codes);
        $this->assertContains('delete_user', $codes);
        $this->assertContains('copy_product', $codes);

        // Should have all core permissions
        $this->assertGreaterThanOrEqual(68, count($codes));
    }

    /**
     * Test enum values match database codes exactly
     */
    public function testEnumValuesMatchDatabaseCodes(): void
    {
        // Sample of expected database codes
        $expectedCodes = [
            'access_dashboard',
            'access_admin_overview',
            'access_users',
            'create_user',
            'edit_user',
            'delete_user',
            'view_user',
            'access_servers',
            'edit_server',
            'delete_server',
            'copy_product',
            'purchase_server',
            'renew_server',
        ];

        $actualCodes = PermissionEnum::getAllCodes();

        foreach ($expectedCodes as $code) {
            $this->assertContains($code, $actualCodes, "Permission code '{$code}' should exist in enum");
        }
    }
}
