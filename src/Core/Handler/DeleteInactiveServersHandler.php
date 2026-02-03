<?php

namespace App\Core\Handler;

use App\Core\Enum\SettingEnum;
use App\Core\Event\Cli\DeleteInactiveServers\DeleteInactiveServersProcessCompletedEvent;
use App\Core\Event\Cli\DeleteInactiveServers\DeleteInactiveServersProcessFailedEvent;
use App\Core\Event\Cli\DeleteInactiveServers\DeleteInactiveServersProcessStartedEvent;
use App\Core\Event\Cli\DeleteInactiveServers\InactiveServerDeletedEvent;
use App\Core\Event\Cli\DeleteInactiveServers\InactiveServerDeletionFailedEvent;
use App\Core\Event\Cli\DeleteInactiveServers\InactiveServerDeletionRequestedEvent;
use App\Core\Repository\ServerRepository;
use App\Core\Repository\SettingRepository;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use DateTime;
use DateTimeImmutable;
use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class DeleteInactiveServersHandler implements HandlerInterface
{
    public const DEFAULT_DELETE_INACTIVE_SERVERS_DAYS_AFTER = 30;

    public function __construct(
        private ServerRepository $serverRepository,
        private PterodactylApplicationService $pterodactylApplicationService,
        private SettingRepository $settingRepository,
        private EventDispatcherInterface $eventDispatcher,
        private EventContextService $eventContextService,
    ) {}

    /**
     * @throws Exception
     */
    public function handle(): void
    {
        $startTime = new DateTimeImmutable();
        $daysAfter = $this->getDeleteInactiveServersDaysAfter();
        $context = $this->eventContextService->buildCliContext('app:delete-inactive-servers', [
            'daysAfterExpiration' => $daysAfter,
        ]);

        $this->eventDispatcher->dispatch(
            new DeleteInactiveServersProcessStartedEvent($startTime, $daysAfter, $context)
        );

        $stats = ['checked' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $this->handleDeleteInactiveServers($stats, $daysAfter, $context);

            $duration = (new DateTimeImmutable())->getTimestamp() - $startTime->getTimestamp();
            $this->eventDispatcher->dispatch(
                new DeleteInactiveServersProcessCompletedEvent(
                    $stats['checked'],
                    $stats['deleted'],
                    $stats['skipped'],
                    $stats['failed'],
                    $daysAfter,
                    $duration,
                    new DateTimeImmutable(),
                    $context
                )
            );
        } catch (Exception $e) {
            $this->eventDispatcher->dispatch(
                new DeleteInactiveServersProcessFailedEvent(
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
    private function handleDeleteInactiveServers(array &$stats, int $daysAfter, array $context): void
    {
        if ($daysAfter === 0) {
            $serversToDelete = $this->serverRepository->getAllSuspendedServers();
        } else {
            $dateObject = new DateTime(sprintf('now - %d days', $daysAfter));
            $serversToDelete = $this->serverRepository->getServersExpiredBefore($dateObject);
        }

        foreach ($serversToDelete as $server) {
            $stats['checked']++;

            try {
                $expiredAt = $server->getExpiresAt();
                $expiredAtImmutable = DateTimeImmutable::createFromMutable($expiredAt);

                $requestedEvent = new InactiveServerDeletionRequestedEvent(
                    $server->getUser()->getId() ?? 0,
                    $server->getId(),
                    $server->getPterodactylServerIdentifier(),
                    $server->getServerProduct()?->getName() ?? 'Unknown Product',
                    $expiredAtImmutable,
                    $daysAfter,
                    $context
                );
                $this->eventDispatcher->dispatch($requestedEvent);

                if ($requestedEvent->isPropagationStopped()) {
                    $stats['skipped']++;
                    continue;
                }

                $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->servers()
                    ->deleteServer($server->getPterodactylServerId());

                $server->setDeletedAtValue();
                $this->serverRepository->save($server);

                $stats['deleted']++;

                $this->eventDispatcher->dispatch(
                    new InactiveServerDeletedEvent(
                        $server->getUser()->getId() ?? 0,
                        $server->getId(),
                        $server->getPterodactylServerIdentifier(),
                        $server->getServerProduct()?->getName() ?? 'Unknown Product',
                        $expiredAtImmutable,
                        new DateTimeImmutable(),
                        $daysAfter,
                        $context
                    )
                );

            } catch (Exception $e) {
                $stats['failed']++;

                $expiredAt = $server->getExpiresAt();
                $expiredAtImmutable = DateTimeImmutable::createFromMutable($expiredAt);

                $this->eventDispatcher->dispatch(
                    new InactiveServerDeletionFailedEvent(
                        $server->getUser()->getId() ?? 0,
                        $server->getId(),
                        $server->getPterodactylServerIdentifier(),
                        $server->getServerProduct()?->getName() ?? 'Unknown Product',
                        $expiredAtImmutable,
                        $e->getMessage(),
                        $context
                    )
                );

                // Continue processing other servers
            }
        }
    }

    private function getDeleteInactiveServersDaysAfter(): int
    {
        $settingValue = $this->settingRepository
            ->getSetting(SettingEnum::DELETE_SUSPENDED_SERVERS_DAYS_AFTER);

        if ($settingValue === '' || !is_numeric($settingValue)) {
            return self::DEFAULT_DELETE_INACTIVE_SERVERS_DAYS_AFTER;
        }

        return (int) $settingValue;
    }
}
