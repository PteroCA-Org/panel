<?php

namespace App\Core\Handler;

use App\Core\Service\Composer\ComposerBinaryResolverService;
use App\Core\Service\Update\BackupService;
use App\Core\Service\Update\SystemStateManager;
use App\Core\Service\Update\UpdateLockManager;
use App\Core\Service\Update\UpdateOrchestrator;
use App\Core\Service\Update\UpdateRollbackService;
use App\Core\Service\Update\ValidationService;
use App\Core\Service\Update\Operation\GitOperationService;
use App\Core\Service\Update\Operation\ComposerOperationService;
use App\Core\Service\Update\Operation\DatabaseOperationService;
use App\Core\Service\Update\Operation\SystemOperationService;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdateSystemHandler implements HandlerInterface
{
    private UpdateOrchestrator $orchestrator;
    private array $options = [
        'force-composer' => false,
        'dry-run' => false,
        'verbose' => false,
        'skip-backup' => false,
        'backup-retention' => 7,
        'timeout' => 600,
    ];

    public function __construct(
        Connection $connection,
        UpdateLockManager $lockManager = null,
        BackupService $backupService = null,
        SystemStateManager $stateManager = null,
        ValidationService $validationService = null,
    ) {
        $lockManager = $lockManager ?? new UpdateLockManager();
        $backupService = $backupService ?? new BackupService();
        $stateManager = $stateManager ?? new SystemStateManager();
        $validationService = $validationService ?? new ValidationService($connection);

        // Create operation services
        $gitService = new GitOperationService();
        $composerService = new ComposerOperationService(new ComposerBinaryResolverService());
        $databaseService = new DatabaseOperationService();
        $systemService = new SystemOperationService();

        // Create rollback service
        $rollbackService = new UpdateRollbackService(
            $gitService,
            $databaseService,
            $backupService,
            $stateManager
        );

        // Create orchestrator with all dependencies
        $this->orchestrator = new UpdateOrchestrator(
            $gitService,
            $composerService,
            $databaseService,
            $systemService,
            $rollbackService,
            $validationService,
            $stateManager,
            $backupService,
            $lockManager
        );
    }

    /**
     * @throws Exception
     */
    public function handle(): void
    {
        $this->orchestrator->handle();
    }

    public function setIo(SymfonyStyle $io): self
    {
        $this->orchestrator->setIo($io);
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        $this->orchestrator->setOptions($this->options);
        return $this;
    }

    public function hasError(): bool
    {
        return $this->orchestrator->hasError();
    }

    public function getCurrentVersion(): string
    {
        return $this->orchestrator->getCurrentVersion();
    }
}
