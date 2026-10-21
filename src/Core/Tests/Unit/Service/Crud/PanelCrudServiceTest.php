<?php

namespace App\Core\Tests\Unit\Service\Crud;

use App\Core\Entity\Log;
use App\Core\Entity\Setting;
use App\Core\Entity\User;
use App\Core\Entity\Voucher;
use App\Core\Enum\LogActionEnum;
use App\Core\Enum\SettingTypeEnum;
use App\Core\Enum\VoucherTypeEnum;
use App\Core\Repository\LogRepository;
use App\Core\Service\Crud\CrudTemplateService;
use App\Core\Service\Crud\PanelCrudService;
use App\Core\Service\Logs\LogService;
use App\Core\Service\System\IpAddressProviderService;
use DateTime;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Uses real Doctrine attribute metadata; no database connection is opened.
 */
class PanelCrudServiceTest extends TestCase
{
    private PanelCrudService $panelCrudService;

    private ?array $loggedDetails = null;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 4) . '/Entity'], true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $config);

        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->method('save')->willReturnCallback(function (Log $log) {
            $this->loggedDetails = json_decode($log->getDetails(), true);
        });

        $this->panelCrudService = new PanelCrudService(
            $this->createMock(CrudTemplateService::class),
            new LogService($logRepository, new IpAddressProviderService(new RequestStack())),
            new EntityManager($connection, $config),
        );
    }

    public function testAddedEntityIsLoggedWithItsFields(): void
    {
        $voucher = $this->createVoucher();

        $this->panelCrudService->logEntityAction(LogActionEnum::ENTITY_ADD, $voucher, new User(), Voucher::class);

        $this->assertSame(Voucher::class, $this->loggedDetails['entityName']);
        $this->assertSame('SUMMER', $this->loggedDetails['entity']['code']);
        $this->assertSame(1, $this->loggedDetails['entity']['maxGlobalUses']);
        $this->assertSame(VoucherTypeEnum::BALANCE_TOPUP->value, $this->loggedDetails['entity']['type']);
        $this->assertSame('2026-12-31 00:00:00', $this->loggedDetails['entity']['expirationDate']);
    }

    public function testEditedEntityLogsOnlyChangedFields(): void
    {
        $voucher = $this->createVoucher();
        $previousSnapshot = $this->panelCrudService->createEntitySnapshot($voucher);

        $voucher->setMaxGlobalUses(10);

        $this->panelCrudService->logEntityAction(
            LogActionEnum::ENTITY_EDIT,
            $voucher,
            new User(),
            Voucher::class,
            $previousSnapshot,
        );

        $this->assertSame(['maxGlobalUses' => ['old' => 1, 'new' => 10]], $this->loggedDetails['changes']);
        $this->assertArrayNotHasKey('entity', $this->loggedDetails);
    }

    public function testDeletedEntityUsesSnapshotTakenBeforeRemoval(): void
    {
        $voucher = $this->createVoucher();
        $previousSnapshot = $this->panelCrudService->createEntitySnapshot($voucher);

        $voucher->setCode('CHANGED');

        $this->panelCrudService->logEntityAction(
            LogActionEnum::ENTITY_DELETE,
            $voucher,
            new User(),
            Voucher::class,
            $previousSnapshot,
        );

        $this->assertSame('SUMMER', $this->loggedDetails['entity']['code']);
    }

    public function testSensitiveUserFieldsAreMasked(): void
    {
        $user = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('$2y$13$hash')
            ->setPterodactylUserApiKey('ptlc_secret');

        $this->panelCrudService->logEntityAction(LogActionEnum::ENTITY_ADD, $user, new User(), User::class);

        $this->assertSame('admin@example.com', $this->loggedDetails['entity']['email']);
        $this->assertSame('********', $this->loggedDetails['entity']['password']);
        $this->assertSame('********', $this->loggedDetails['entity']['pterodactylUserApiKey']);
        $this->assertStringNotContainsString('ptlc_secret', json_encode($this->loggedDetails));
    }

    public function testSecretSettingValueIsMasked(): void
    {
        $setting = (new Setting())
            ->setName('stripe_secret_key')
            ->setValue('sk_live_123')
            ->setType(SettingTypeEnum::SECRET->value);

        $this->panelCrudService->logEntityAction(LogActionEnum::ENTITY_EDIT, $setting, new User(), Setting::class);

        $this->assertSame('********', $this->loggedDetails['entity']['value']);
        $this->assertStringNotContainsString('sk_live_123', json_encode($this->loggedDetails));
    }

    private function createVoucher(): Voucher
    {
        return (new Voucher())
            ->setCode('SUMMER')
            ->setValue('10')
            ->setType(VoucherTypeEnum::BALANCE_TOPUP)
            ->setMaxGlobalUses(1)
            ->setExpirationDate(new DateTime('2026-12-31 00:00:00'));
    }
}
