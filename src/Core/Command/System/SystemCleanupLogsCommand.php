<?php

namespace App\Core\Command\System;

use App\Core\Enum\SettingEnum;
use App\Core\Event\Cli\DeleteOldLogs\LogDeletionProcessCompletedEvent;
use App\Core\Event\Cli\DeleteOldLogs\LogDeletionProcessFailedEvent;
use App\Core\Event\Cli\DeleteOldLogs\LogDeletionProcessStartedEvent;
use App\Core\Repository\EmailLogRepository;
use App\Core\Repository\LogRepository;
use App\Core\Repository\ServerLogRepository;
use App\Core\Repository\SettingRepository;
use App\Core\Service\Event\EventContextService;
use DateTime;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'pteroca:system:cleanup-logs',
    description: 'Delete old logs based on system settings',
    aliases: ['app:delete-old-logs']
)]
class SystemCleanupLogsCommand extends Command
{
    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly LogRepository $logRepository,
        private readonly ServerLogRepository $serverLogRepository,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EventContextService $eventContextService,
    ) {
        parent::__construct();
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $startTime = new DateTimeImmutable();

        try {
            $daysAfter = $this->settingRepository->getSetting(SettingEnum::LOG_CLEANUP_DAYS_AFTER);
            if (!$daysAfter || !is_numeric($daysAfter)) {
                $io->error('Invalid log cleanup days setting.');

                $this->eventDispatcher->dispatch(
                    new LogDeletionProcessFailedEvent(
                        'Invalid log cleanup days setting',
                        null,
                        new DateTimeImmutable(),
                        $this->eventContextService->buildCliContext('pteroca:system:cleanup-logs')
                    )
                );

                return Command::FAILURE;
            }

            $daysAfterInt = (int) $daysAfter;
            $cutoffDate = new DateTime();
            $cutoffDate->modify("-$daysAfterInt days");
            $cutoffDateImmutable = DateTimeImmutable::createFromMutable($cutoffDate);

            $context = $this->eventContextService->buildCliContext('pteroca:system:cleanup-logs', [
                'daysAfter' => $daysAfterInt,
                'cutoffDate' => $cutoffDateImmutable->format('Y-m-d H:i:s'),
            ]);

            $this->eventDispatcher->dispatch(
                new LogDeletionProcessStartedEvent(
                    $startTime,
                    $daysAfterInt,
                    $cutoffDateImmutable,
                    $context
                )
            );

            $io->writeln(sprintf('Deleting logs older than %d days (before %s)', $daysAfterInt, $cutoffDate->format('Y-m-d H:i:s')));

            $totalDeleted = 0;

            $deletedLogs = $this->logRepository->deleteOldLogs($cutoffDate);
            $totalDeleted += $deletedLogs;
            $io->writeln(sprintf('Deleted %d old log entries', $deletedLogs));

            $deletedServerLogs = $this->serverLogRepository->deleteOldLogs($cutoffDate);
            $totalDeleted += $deletedServerLogs;
            $io->writeln(sprintf('Deleted %d old server log entries', $deletedServerLogs));

            $deletedEmailLogs = $this->emailLogRepository->deleteOldLogs($cutoffDate);
            $totalDeleted += $deletedEmailLogs;
            $io->writeln(sprintf('Deleted %d old email log entries', $deletedEmailLogs));

            $io->success(sprintf('Log cleanup completed. Total deleted entries: %d', $totalDeleted));

            $endTime = new DateTimeImmutable();
            $duration = $endTime->getTimestamp() - $startTime->getTimestamp();

            $this->eventDispatcher->dispatch(
                new LogDeletionProcessCompletedEvent(
                    $daysAfterInt,
                    $cutoffDateImmutable,
                    $deletedLogs,
                    $deletedServerLogs,
                    $deletedEmailLogs,
                    $totalDeleted,
                    $duration,
                    $endTime,
                    $context
                )
            );

            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error(sprintf('Log deletion failed: %s', $e->getMessage()));

            $this->eventDispatcher->dispatch(
                new LogDeletionProcessFailedEvent(
                    $e->getMessage(),
                    $daysAfterInt ?? null,
                    new DateTimeImmutable(),
                    $this->eventContextService->buildCliContext('pteroca:system:cleanup-logs')
                )
            );

            throw $e;
        }
    }
}
