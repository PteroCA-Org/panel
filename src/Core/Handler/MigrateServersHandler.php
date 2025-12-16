<?php

namespace App\Core\Handler;

use App\Core\Contract\UserInterface;
use App\Core\DTO\Pterodactyl\Application\PterodactylUser;
use App\Core\DTO\Pterodactyl\Collection;
use App\Core\Entity\Server;
use App\Core\Entity\ServerProduct;
use App\Core\Entity\ServerProductPrice;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Enum\ProductPriceUnitEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Repository\ServerProductPriceRepository;
use App\Core\Repository\ServerProductRepository;
use App\Core\Repository\ServerRepository;
use App\Core\Repository\UserRepository;
use App\Core\Event\Cli\MigrateServers\ServerMigratedEvent;
use App\Core\Event\Cli\MigrateServers\ServerMigrationFailedEvent;
use App\Core\Event\Cli\MigrateServers\ServerMigrationProcessCompletedEvent;
use App\Core\Event\Cli\MigrateServers\ServerMigrationProcessFailedEvent;
use App\Core\Event\Cli\MigrateServers\ServerMigrationProcessStartedEvent;
use App\Core\Event\Cli\MigrateServers\ServerMigrationRequestedEvent;
use App\Core\Event\Cli\MigrateServers\ServerMigrationSkippedEvent;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Server\ServerEggService;
use App\Core\Service\SettingService;
use DateTime;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MigrateServersHandler implements HandlerInterface
{
    private int $limit = 100;

    private SymfonyStyle $io;

    public function __construct(
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly ServerRepository $serverRepository,
        private readonly ServerProductRepository $serverProductRepository,
        private readonly ServerProductPriceRepository $serverProductPriceRepository,
        private readonly UserRepository $userRepository,
        private readonly SettingService $settingService,
        private readonly ServerEggService $serverEggService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EventContextService $eventContextService,
    )
    {
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function setIo(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function handle(bool $dryRun = false): void
    {
        $startTime = new DateTimeImmutable();
        $context = $this->eventContextService->buildCliContext('pterodactyl:migrate-servers', [
            'limit' => $this->limit,
            'dryRun' => $dryRun,
        ]);

        $this->eventDispatcher->dispatch(
            new ServerMigrationProcessStartedEvent($startTime, $this->limit, $dryRun, $context)
        );

        $stats = [
            'pterodactylServersFound' => 0,
            'pterodactylUsersFound' => 0,
            'serversAlreadyExisting' => 0,
            'serversMigrated' => 0,
            'serversSkipped' => 0,
            'serversFailed' => 0,
        ];

        try {
            $this->io->title('Pterodactyl Server Migration');

            if ($dryRun) {
                $this->io->info('Running in dry-run mode - no changes will be made');
            }

            $pterodactylServers = $this->getPterodactylServers();
            $pterodactylUsers = $this->getPterodactylUsers();
            $pterocaServers = $this->getPterocaServers();
            $pterocaUsers = $this->getPterocaUsers();

            $stats['pterodactylServersFound'] = $pterodactylServers->count();
            $stats['pterodactylUsersFound'] = $pterodactylUsers->count();

            foreach ($pterodactylServers as $pterodactylServer) {
                $serverArray = is_array($pterodactylServer) ? $pterodactylServer : $pterodactylServer->toArray();

                // Skip: already exists
                if ($this->isServerAlreadyExists($serverArray, $pterocaServers)) {
                    $stats['serversAlreadyExisting']++;
                    $stats['serversSkipped']++;

                    $this->eventDispatcher->dispatch(
                        new ServerMigrationSkippedEvent(
                            $serverArray['id'],
                            $serverArray['identifier'],
                            $serverArray['name'],
                            'already_exists',
                            null,
                            $context
                        )
                    );

                    $this->io->info(sprintf(
                        'Server %s #%s already exists in PteroCA, skipping...',
                        $serverArray['name'],
                        $serverArray['identifier'],
                    ));
                    continue;
                }

                // Find owner
                $pterodactylServerOwner = current(array_filter(
                    $pterodactylUsers->all(),
                    fn(PterodactylUser $user) => $user->get('id') === $serverArray['user']
                ));

                // Skip: owner not found
                if (!in_array($pterodactylServerOwner->get('email'), array_column($pterocaUsers, 'email'))) {
                    $stats['serversSkipped']++;

                    $this->eventDispatcher->dispatch(
                        new ServerMigrationSkippedEvent(
                            $serverArray['id'],
                            $serverArray['identifier'],
                            $serverArray['name'],
                            'owner_not_found',
                            $pterodactylServerOwner->get('email'),
                            $context
                        )
                    );

                    $this->io->warning(sprintf(
                        'User %s does not exist in PteroCA, skipping server %s #%s...',
                        $pterodactylServerOwner->get('email'),
                        $serverArray['name'],
                        $serverArray['identifier'],
                    ));
                    continue;
                }

                // Skip: user declined
                if ($this->isUserWantMigrateServer($serverArray) === false) {
                    $stats['serversSkipped']++;

                    $this->eventDispatcher->dispatch(
                        new ServerMigrationSkippedEvent(
                            $serverArray['id'],
                            $serverArray['identifier'],
                            $serverArray['name'],
                            'user_declined',
                            $pterodactylServerOwner->get('email'),
                            $context
                        )
                    );

                    $this->io->info(sprintf(
                        'Skipping server %s #%s...',
                        $serverArray['name'],
                        $serverArray['identifier'],
                    ));
                    continue;
                }

                $this->io->section(sprintf(
                    'Migrating server %s #%s...',
                    $serverArray['name'],
                    $serverArray['identifier'],
                ));

                $requestedEvent = new ServerMigrationRequestedEvent(
                    $serverArray['id'],
                    $serverArray['identifier'],
                    $serverArray['name'],
                    $pterodactylServerOwner->get('email'),
                    $serverArray['user'],
                    $serverArray['suspended'],
                    $context
                );
                $this->eventDispatcher->dispatch($requestedEvent);

                if ($requestedEvent->isPropagationStopped()) {
                    $stats['serversSkipped']++;

                    $this->eventDispatcher->dispatch(
                        new ServerMigrationSkippedEvent(
                            $serverArray['id'],
                            $serverArray['identifier'],
                            $serverArray['name'],
                            'plugin_blocked',
                            $pterodactylServerOwner->get('email'),
                            $context
                        )
                    );

                    $this->io->warning(sprintf(
                        'Migration blocked by plugin for server %s #%s',
                        $serverArray['name'],
                        $serverArray['identifier'],
                    ));
                    continue;
                }

                $this->io->info('You need to set the base price and duration for the server in PteroCA.');
                $duration = $this->askForDuration();
                $price = $this->askForPrice();

                // Skip: dry-run
                if ($dryRun) {
                    $stats['serversSkipped']++;

                    $this->eventDispatcher->dispatch(
                        new ServerMigrationSkippedEvent(
                            $serverArray['id'],
                            $serverArray['identifier'],
                            $serverArray['name'],
                            'dry_run',
                            $pterodactylServerOwner->get('email'),
                            $context
                        )
                    );

                    $this->io->info('Dry run: Would migrate server but not saving changes');
                    continue;
                }

                // Migrate server
                try {
                    $migratedServerId = $this->migrateServer(
                        $serverArray,
                        $pterodactylServerOwner->get('email'),
                        $duration,
                        $price,
                        $context
                    );

                    $stats['serversMigrated']++;

                    $this->io->success(sprintf(
                        'Server %s #%s migrated successfully (ID: %d)',
                        $serverArray['name'],
                        $serverArray['identifier'],
                        $migratedServerId
                    ));
                } catch (Exception $e) {
                    $stats['serversFailed']++;

                    $this->eventDispatcher->dispatch(
                        new ServerMigrationFailedEvent(
                            $serverArray['id'],
                            $serverArray['identifier'],
                            $serverArray['name'],
                            $e->getMessage(),
                            $pterodactylServerOwner->get('email'),
                            $context
                        )
                    );

                    $this->io->error(sprintf(
                        'Failed to migrate server %s #%s: %s',
                        $serverArray['name'],
                        $serverArray['identifier'],
                        $e->getMessage()
                    ));

                    // Continue processing other servers
                }
            }

            $duration = (new DateTimeImmutable())->getTimestamp() - $startTime->getTimestamp();
            $this->eventDispatcher->dispatch(
                new ServerMigrationProcessCompletedEvent(
                    $stats['pterodactylServersFound'],
                    $stats['pterodactylUsersFound'],
                    $stats['serversAlreadyExisting'],
                    $stats['serversMigrated'],
                    $stats['serversSkipped'],
                    $stats['serversFailed'],
                    $this->limit,
                    $dryRun,
                    $duration,
                    new DateTimeImmutable(),
                    $context
                )
            );

            $this->io->success(sprintf(
                'Server migration completed. Migrated: %d, Skipped: %d, Failed: %d (Pterodactyl servers: %d)',
                $stats['serversMigrated'],
                $stats['serversSkipped'],
                $stats['serversFailed'],
                $stats['pterodactylServersFound']
            ));
        } catch (Exception $e) {
            $this->eventDispatcher->dispatch(
                new ServerMigrationProcessFailedEvent(
                    $e->getMessage(),
                    $stats,
                    new DateTimeImmutable(),
                    $context
                )
            );
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    private function migrateServer(
        array $pterodactylServer,
        string $pterodactylServerOwnerEmail,
        int $duration,
        float $price,
        array $context
    ): int
    {
        $pterocaUser = $this->userRepository->findOneBy(['email' => $pterodactylServerOwnerEmail]);
        if (empty($pterocaUser)) {
            throw new Exception(sprintf(
                'Could not find user with email %s',
                $pterodactylServerOwnerEmail
            ));
        }

        $serverEntity = $this->migrateServerEntity($pterocaUser, $pterodactylServer, $duration);
        $serverProductEntity = $this->migrateServerProductEntity($serverEntity, $pterodactylServer);
        $this->migrateServerProductPriceEntity($serverProductEntity, $duration, $price);

        // Emit ServerMigratedEvent
        $expiresAtImmutable = $serverEntity->getExpiresAt()
            ? DateTimeImmutable::createFromMutable($serverEntity->getExpiresAt())
            : new DateTimeImmutable();

        $this->eventDispatcher->dispatch(
            new ServerMigratedEvent(
                $pterocaUser->getId() ?? 0,
                $serverEntity->getId(),
                $pterodactylServer['id'],
                $pterodactylServer['identifier'],
                $pterodactylServer['name'],
                $duration,
                $price,
                $expiresAtImmutable,
                new DateTimeImmutable(),
                $context
            )
        );

        return $serverEntity->getId();
    }

    private function migrateServerProductPriceEntity(
        ServerProduct $serverProductEntity,
        int $duration,
        float $price
    ): void
    {
        $serverProductPriceEntity = (new ServerProductPrice())
            ->setServerProduct($serverProductEntity)
            ->setType(ProductPriceTypeEnum::STATIC)
            ->setValue($duration)
            ->setUnit(ProductPriceUnitEnum::DAYS)
            ->setPrice($price)
            ->setIsSelected(true);
        $this->serverProductPriceRepository->save($serverProductPriceEntity);

    }

    private function migrateServerProductEntity(Server $serverEntity, array $pterodactylServer): ServerProduct
    {
        $serverEggsConfiguration = $this->serverEggService
            ->prepareEggsConfiguration($pterodactylServer['id']);

        $serverProductEntity = (new ServerProduct())
            ->setServer($serverEntity)
            ->setOriginalProduct(null)
            ->setName(sprintf('%s #%s', $pterodactylServer['name'], $pterodactylServer['identifier']))
            ->setDiskSpace($pterodactylServer['limits']['disk'])
            ->setMemory($pterodactylServer['limits']['memory'])
            ->setIo($pterodactylServer['limits']['io'])
            ->setCpu($pterodactylServer['limits']['cpu'])
            ->setSwap($pterodactylServer['limits']['swap'])
            ->setBackups($pterodactylServer['feature_limits']['backups'])
            ->setPorts($pterodactylServer['feature_limits']['allocations'])
            ->setDbCount($pterodactylServer['feature_limits']['databases'])
            ->setNodes([$pterodactylServer['node']])
            ->setNest($pterodactylServer['nest'])
            ->setEggs([$pterodactylServer['egg']])
            ->setEggsConfiguration($serverEggsConfiguration)
            ->setAllowChangeEgg(false);
        $this->serverProductRepository->save($serverProductEntity);

        return $serverProductEntity;
    }

    private function migrateServerEntity(UserInterface $serverOwner, array $pterodactylServer, int $duration): Server
    {
        $expireAt = (new DateTime())
            ->modify(sprintf('+%d days', $duration));

        $serverEntity = (new Server())
            ->setUser($serverOwner)
            ->setPterodactylServerId($pterodactylServer['id'])
            ->setPterodactylServerIdentifier($pterodactylServer['identifier'])
            ->setExpiresAt($expireAt)
            ->setIsSuspended($pterodactylServer['suspended'])
            ->setAutoRenewal(false);
        $this->serverRepository->save($serverEntity);

        return $serverEntity;
    }

    private function askForPrice(): float
    {
        $internalCurrency = $this->settingService->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);
        $price = $this->io->ask(sprintf(
            'How much should the server cost? (%s)',
            $internalCurrency,
        ));
        if (!is_numeric($price) || $price <= 0) {
            $this->io->error('Price must be a positive number.');
            return $this->askForPrice();
        }

        return (float)$price;
    }

    private function askForDuration(): int
    {
        $duration = $this->io->ask('How long should the server be active? (in days)', 30);
        if (!is_numeric($duration) || $duration <= 0) {
            $this->io->error('Duration must be a positive number.');
            return $this->askForDuration();
        }

        return (int)$duration;
    }

    private function isUserWantMigrateServer(array $pterodactylServer): bool
    {
        $questionMessage = sprintf(
            'Do you want to migrate server %s #%s?',
            $pterodactylServer['name'],
            $pterodactylServer['identifier'],
        );

        return strtolower($this->io->ask($questionMessage, 'yes')) === 'yes';
    }

    private function isServerAlreadyExists(array $pterodactylServer, array $pterocaServers): bool
    {
        return in_array(
            $pterodactylServer['identifier'],
            array_column($pterocaServers, 'pterodactylServerIdentifier'),
        );
    }

    private function getPterodactylServers(): Collection
    {
        $this->io->section('Fetching servers from Pterodactyl...');
        $servers = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->servers()
            ->all([
                'per_page' => $this->limit,
            ]);
        $this->io->info(sprintf('Fetched %d servers from Pterodactyl', $servers->count()));

        return $servers;
    }

    private function getPterodactylUsers(): Collection
    {
        $this->io->section('Fetching users from Pterodactyl...');
        $users = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->users()
            ->getAllUsers([
                'per_page' => $this->limit,
            ]);
        $this->io->info(sprintf('Fetched %d users from Pterodactyl', $users->count()));

        return $users;
    }

    private function getPterocaServers(): array
    {
        return array_map(
            fn(Server $server) => [
                'pterodactylServerIdentifier' => $server->getPterodactylServerIdentifier(),
            ],
            $this->serverRepository->findAll(),
        );
    }

    private function getPterocaUsers(): array
    {
        return array_map(
            fn(UserInterface $user) => [
                'email' => $user->getEmail(),
            ],
            $this->userRepository->findAll(),
        );
    }
}
