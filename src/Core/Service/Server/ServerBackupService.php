<?php

namespace App\Core\Service\Server;

use App\Core\Contract\Pterodactyl\Client\PterodactylClientAdapterInterface;
use App\Core\Contract\UserInterface;
use App\Core\Entity\Server;
use App\Core\Enum\ServerLogActionEnum;
use App\Core\Event\Server\Backup\ServerBackupCreatedEvent;
use App\Core\Event\Server\Backup\ServerBackupCreationFailedEvent;
use App\Core\Event\Server\Backup\ServerBackupCreationRequestedEvent;
use App\Core\Event\Server\Backup\ServerBackupDeletedEvent;
use App\Core\Event\Server\Backup\ServerBackupDeletionRequestedEvent;
use App\Core\Event\Server\Backup\ServerBackupDownloadInitiatedEvent;
use App\Core\Event\Server\Backup\ServerBackupDownloadRequestedEvent;
use App\Core\Event\Server\Backup\ServerBackupRestoreFailedEvent;
use App\Core\Event\Server\Backup\ServerBackupRestoreInitiatedEvent;
use App\Core\Event\Server\Backup\ServerBackupRestoreRequestedEvent;
use App\Core\Event\Server\Backup\ServerBackupRestoredEvent;
use App\Core\Service\Event\EventContextService;
use App\Core\Service\Logs\ServerLogService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class ServerBackupService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private ServerLogService              $serverLogService,
        private EventDispatcherInterface      $eventDispatcher,
        private RequestStack                  $requestStack,
        private EventContextService           $eventContextService,
    ) {}

    /**
     * @throws Exception
     */
    public function createBackup(
        Server $server,
        UserInterface $user,
        ?string $backupName,
        ?string $ignoredFiles,
        bool $isLocked = false,
    ): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        if (empty($backupName)) {
            throw new InvalidArgumentException('Backup name is required');
        }

        $requestedEvent = new ServerBackupCreationRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupName,
            $ignoredFiles,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Backup creation was blocked';
            throw new RuntimeException($reason);
        }

        try {
            $ignoredFilesArray = $this->parseIgnoredFiles($ignoredFiles);

            $createdBackup = $this->getPterodactylClientApi($user)
                ->backups()
                ->createBackup($server, $backupName, $ignoredFilesArray, $isLocked)
                ->toArray();

            $this->serverLogService->logServerAction(
                $user,
                $server,
                ServerLogActionEnum::CREATE_BACKUP,
                [
                    'backup_id' => $createdBackup['uuid'],
                    'backup_name' => $backupName,
                ]
            );

            $createdEvent = new ServerBackupCreatedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $createdBackup['uuid'],
                $backupName,
                $ignoredFiles,
                $context
            );
            $this->eventDispatcher->dispatch($createdEvent);

            return $createdBackup;
        } catch (Exception $exception) {
            $failedEvent = new ServerBackupCreationFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $backupName,
                $exception->getMessage(),
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            throw $exception;
        }
    }

    public function getBackupDownloadUrl(
        Server $server,
        UserInterface $user,
        string $backupId,
    ): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerBackupDownloadRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupId,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        $downloadUrl = $this->getPterodactylClientApi($user)
            ->backups()
            ->getBackupDownloadUrl($server, $backupId)['url'] ?? null;

        if (empty($downloadUrl)) {
            throw new RuntimeException('Failed to get backup download URL');
        }

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::DOWNLOAD_BACKUP,
            [
                'backup_id' => $backupId,
            ]
        );

        $initiatedEvent = new ServerBackupDownloadInitiatedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupId,
            $downloadUrl,
            $context
        );
        $this->eventDispatcher->dispatch($initiatedEvent);

        return $downloadUrl;
    }

    public function deleteBackup(
        Server $server,
        UserInterface $user,
        string $backupId,
    ): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerBackupDeletionRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupId,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Backup deletion was blocked';
            throw new RuntimeException($reason);
        }

        $deletedBackup = $this->getPterodactylClientApi($user)
            ->backups()
            ->deleteBackup($server, $backupId);

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::DELETE_BACKUP,
            [
                'backup_id' => $backupId,
            ]
        );

        $deletedEvent = new ServerBackupDeletedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupId,
            $context
        );
        $this->eventDispatcher->dispatch($deletedEvent);

        return $deletedBackup;
    }

    /**
     * @throws Exception
     */
    public function restoreBackup(
        Server $server,
        UserInterface $user,
        string $backupId,
        bool $truncate = false,
    ): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerBackupRestoreRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupId,
            $truncate,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Backup restore was blocked';
            throw new RuntimeException($reason);
        }

        $initiatedEvent = new ServerBackupRestoreInitiatedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $backupId,
            $truncate,
            $context
        );
        $this->eventDispatcher->dispatch($initiatedEvent);

        try {
            $this->getPterodactylClientApi($user)
                ->backups()
                ->restoreBackup($server, $backupId, $truncate);

            $this->serverLogService->logServerAction(
                $user,
                $server,
                ServerLogActionEnum::RESTORE_BACKUP,
                [
                    'backup_id' => $backupId,
                    'truncate' => $truncate,
                ]
            );

            $restoredEvent = new ServerBackupRestoredEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $backupId,
                $truncate,
                $context
            );
            $this->eventDispatcher->dispatch($restoredEvent);
        } catch (Exception $exception) {
            $failedEvent = new ServerBackupRestoreFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $backupId,
                $truncate,
                $exception->getMessage(),
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            throw $exception;
        }
    }

    private function getPterodactylClientApi(UserInterface $user): PterodactylClientAdapterInterface
    {
        return $this->pterodactylApplicationService
            ->getClientApi($user);
    }

    private function parseIgnoredFiles(?string $ignoredFiles): ?array
    {
        if (empty($ignoredFiles)) {
            return null;
        }

        return array_filter(
            array_map('trim', explode("\n", $ignoredFiles)),
            fn($line) => $line !== ''
        );
    }
}
