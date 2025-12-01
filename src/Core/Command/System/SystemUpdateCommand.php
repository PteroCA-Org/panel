<?php

namespace App\Core\Command\System;

use App\Core\Handler\UpdateSystemHandler;
use App\Core\Service\Update\BackupService;
use App\Core\Service\Update\SystemStateManager;
use App\Core\Service\Update\UpdateLockManager;
use App\Core\Service\Update\ValidationService;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'pteroca:system:update',
    description: 'Update PteroCA system with enhanced safety features',
    aliases: ['app:update-system']
)]
class SystemUpdateCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?UpdateLockManager $lockManager = null,
        private readonly ?BackupService $backupService = null,
        private readonly ?SystemStateManager $stateManager = null,
        private readonly ?ValidationService $validationService = null,
        private readonly ?Filesystem $filesystem = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force-composer',
                null,
                InputOption::VALUE_NONE,
                'Force composer install with --ignore-platform-reqs without asking'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without making any changes'
            )
            ->addOption(
                'skip-backup',
                null,
                InputOption::VALUE_NONE,
                'Skip database backup creation (DANGEROUS - not recommended)'
            )
            ->addOption(
                'backup-retention',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days to retain backups',
                7
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Timeout for operations in seconds',
                600
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->showUpdateHeader($io, $input);

        $options = [
            'force-composer' => $input->getOption('force-composer'),
            'dry-run' => $input->getOption('dry-run'),
            'verbose' => $input->getOption('verbose'),
            'skip-backup' => $input->getOption('skip-backup'),
            'backup-retention' => (int)$input->getOption('backup-retention'),
            'timeout' => (int)$input->getOption('timeout'),
        ];

        if ($options['skip-backup'] && !$options['dry-run']) {
            $io->warning('WARNING: Database backup is disabled. This is dangerous and not recommended.');
            if (!$io->confirm('Are you absolutely sure you want to continue without backup?', false)) {
                $io->note('Update cancelled for safety.');
                return Command::SUCCESS;
            }
        }

        $updateSystemHandler = new UpdateSystemHandler(
            $this->connection,
            $this->lockManager,
            $this->backupService,
            $this->stateManager,
            $this->validationService
        );

        try {
            $updateSystemHandler
                ->setIo($io)
                ->setOptions($options)
                ->handle();

            if ($updateSystemHandler->hasError()) {
                $io->error(sprintf(
                    'Update process finished with errors. Current version: %s',
                    $updateSystemHandler->getCurrentVersion()
                ));
                return Command::FAILURE;
            } else {
                $io->success(sprintf(
                    'Update process completed successfully! Current version: %s',
                    $updateSystemHandler->getCurrentVersion()
                ));
                return Command::SUCCESS;
            }

        } catch (Exception $e) {
            $io->error('Update failed: ' . $e->getMessage());

            if ($options['verbose']) {
                $io->text('Stack trace:');
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function showUpdateHeader(SymfonyStyle $io, InputInterface $input): void
    {
        $io->title('PteroCA System Update');

        if (!$input->getOption('dry-run')) {
            $io->note([
                'This command will update your PteroCA installation.',
                'The process includes automatic backup and rollback capabilities.',
                '',
                'Safety features included:',
                '• Pre-flight environment validation',
                '• Automatic database backup (unless --skip-backup)',
                '• Git state tracking and restoration',
                '• Process locking to prevent concurrent updates',
                '• Complete rollback on failure',
                '',
                'For a preview without making changes, use --dry-run'
            ]);
        }

        $currentVersion = $this->getCurrentVersion();
        $phpVersion = PHP_VERSION;
        $environment = $_ENV['APP_ENV'] ?? 'prod';

        $io->table(['System Information', 'Value'], [
            ['Current Version', $currentVersion],
            ['PHP Version', $phpVersion],
            ['Environment', $environment],
            ['Mode', $input->getOption('dry-run') ? 'DRY RUN' : 'LIVE UPDATE']
        ]);
    }

    private function getCurrentVersion(): string
    {
        exec('git describe --tags 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            exec('git rev-parse --short HEAD 2>/dev/null', $output, $returnCode);
            if ($returnCode !== 0) {
                return 'Unknown';
            }
            return 'dev-' . ($output[0] ?? 'unknown');
        }
        return $output[0] ?? 'Unknown';
    }
}
