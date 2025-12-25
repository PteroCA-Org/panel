<?php

namespace App\Core\Service\Update;

use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ValidationService
{
    private const MIN_DISK_SPACE_MB = 100;
    private const MIN_MEMORY_MB = 128;

    private Connection $connection;
    private Filesystem $filesystem;

    public function __construct(Connection $connection, Filesystem $filesystem = null)
    {
        $this->connection = $connection;
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function validateUpdateEnvironment(): array
    {
        $results = [];
        
        $results['disk_space'] = $this->checkDiskSpace();
        $results['memory'] = $this->checkMemory();
        $results['permissions'] = $this->validatePermissions();
        $results['database'] = $this->testDatabaseConnection();
        $results['git'] = $this->validateGitEnvironment();
        $results['git_branch'] = $this->validateGitBranch();
        $results['composer'] = $this->validateComposerEnvironment();
        $results['php_extensions'] = $this->validatePhpExtensions();
        $results['maintenance_mode'] = $this->isMaintenanceModeAvailable();

        return $results;
    }

    public function getValidationSummary(array $results): array
    {
        $passed = array_filter($results, fn($result) => $result['status'] === 'ok');
        $warnings = array_filter($results, fn($result) => $result['status'] === 'warning');
        $failed = array_filter($results, fn($result) => $result['status'] === 'error');

        return [
            'total' => count($results),
            'passed' => count($passed),
            'warnings' => count($warnings),
            'failed' => count($failed),
            'can_proceed' => count($failed) === 0
        ];
    }

    public function checkDiskSpace(): array
    {
        $rootPath = dirname(__DIR__, 4);
        $freeBytes = disk_free_space($rootPath);
        $totalBytes = disk_total_space($rootPath);
        
        if ($freeBytes === false || $totalBytes === false) {
            return [
                'status' => 'error',
                'message' => 'Could not determine disk space',
                'details' => []
            ];
        }

        $freeMB = round($freeBytes / 1024 / 1024);
        $totalMB = round($totalBytes / 1024 / 1024);
        $usedPercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);

        $status = 'ok';
        $message = "Free space: {$freeMB}MB ($usedPercent% used)";

        if ($freeMB < self::MIN_DISK_SPACE_MB) {
            $status = 'error';
            $message = "Insufficient disk space: {$freeMB}MB (minimum: " . self::MIN_DISK_SPACE_MB . "MB)";
        } elseif ($freeMB < self::MIN_DISK_SPACE_MB * 2) {
            $status = 'warning';
            $message = "Low disk space: {$freeMB}MB (recommended: " . (self::MIN_DISK_SPACE_MB * 2) . "MB+)";
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'free_mb' => $freeMB,
                'total_mb' => $totalMB,
                'used_percent' => $usedPercent
            ]
        ];
    }

    public function checkMemory(): array
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            return [
                'status' => 'ok',
                'message' => 'Memory limit: unlimited',
                'details' => ['limit' => 'unlimited']
            ];
        }

        $memoryBytes = $this->parseMemoryLimit($memoryLimit);
        $memoryMB = round($memoryBytes / 1024 / 1024);

        $status = 'ok';
        $message = "Memory limit: {$memoryMB}MB";

        if ($memoryMB < self::MIN_MEMORY_MB) {
            $status = 'error';
            $message = "Insufficient memory limit: {$memoryMB}MB (minimum: " . self::MIN_MEMORY_MB . "MB)";
        } elseif ($memoryMB < self::MIN_MEMORY_MB * 2) {
            $status = 'warning';
            $message = "Low memory limit: {$memoryMB}MB (recommended: " . (self::MIN_MEMORY_MB * 2) . "MB+)";
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'limit' => $memoryLimit,
                'mb' => $memoryMB
            ]
        ];
    }

    public function validatePermissions(): array
    {
        $rootPath = dirname(__DIR__, 4);
        $criticalPaths = [
            $rootPath . '/var',
            $rootPath . '/var/cache',
            $rootPath . '/var/log',
            $rootPath . '/var/backups',
            $rootPath . '/composer.lock',
            $rootPath . '/config',
            $rootPath . '/plugins',
        ];

        $issues = [];
        
        foreach ($criticalPaths as $path) {
            if (!$this->filesystem->exists($path) && str_contains($path, 'var/')) {
                try {
                    $this->filesystem->mkdir($path, 0755);
                } catch (Exception) {
                    $issues[] = "Cannot create directory: $path";
                    continue;
                }
            }

            if ($this->filesystem->exists($path)) {
                if (!is_readable($path)) {
                    $issues[] = "Not readable: $path";
                }
                if (!is_writable($path)) {
                    $issues[] = "Not writable: $path";
                }
            }
        }

        $testFile = $rootPath . '/permission_test_' . uniqid() . '.tmp';
        try {
            file_put_contents($testFile, 'test');
            unlink($testFile);
        } catch (Exception) {
            $issues[] = "Cannot write to root directory: $rootPath";
        }

        $status = empty($issues) ? 'ok' : 'error';
        $message = empty($issues) ? 'All permissions OK' : 'Permission issues found';

        return [
            'status' => $status,
            'message' => $message,
            'details' => ['issues' => $issues]
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function testDatabaseConnection(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            
            $schemaManager = $this->connection->createSchemaManager();
            $tables = $schemaManager->listTableNames();
            
            $hasMigrationsTable = in_array('doctrine_migration_versions', $tables) || 
                                  in_array('migration_versions', $tables);
            
            $status = 'ok';
            $message = 'Database connection successful';
            $details = ['tables_count' => count($tables)];
            
            if (!$hasMigrationsTable) {
                $status = 'warning';
                $message = 'Database connected but migrations table not found';
                $details['migrations_table'] = false;
            }

            return [
                'status' => $status,
                'message' => $message,
                'details' => $details
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    public function validateGitEnvironment(): array
    {
        $issues = [];
        
        $process = new Process(['git', '--version']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            return [
                'status' => 'error',
                'message' => 'Git is not installed or not accessible',
                'details' => ['error' => $process->getErrorOutput()]
            ];
        }

        $process = new Process(['git', 'rev-parse', '--git-dir']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            $issues[] = 'Not in a git repository';
        }

        $process = new Process(['git', 'remote', 'get-url', 'origin']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            $issues[] = 'No git remote origin configured';
        } else {
            $remoteUrl = trim($process->getOutput());
            
            $process = new Process(['git', 'ls-remote', '--exit-code', '--heads', 'origin']);
            $process->setTimeout(10);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $issues[] = 'Cannot connect to remote repository';
            }
        }

        $process = new Process(['git', 'status', '--porcelain']);
        $process->run();
        
        $hasChanges = !empty(trim($process->getOutput()));
        
        $status = empty($issues) ? ($hasChanges ? 'warning' : 'ok') : 'error';
        $message = empty($issues) ? 
                  ($hasChanges ? 'Git OK but has uncommitted changes' : 'Git environment OK') : 
                  'Git environment issues found';

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'issues' => $issues,
                'has_uncommitted_changes' => $hasChanges,
                'remote_url' => $remoteUrl ?? null
            ]
        ];
    }

    public function validateGitBranch(): array
    {
        $process = new Process(['git', 'branch', '--show-current']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            return [
                'status' => 'error',
                'message' => 'Cannot determine current Git branch',
                'details' => ['error' => $process->getErrorOutput()]
            ];
        }
        
        $currentBranch = trim($process->getOutput());
        
        if ($currentBranch !== 'main') {
            return [
                'status' => 'error',
                'message' => sprintf(
                    'Updates can only be performed from "main" branch. Current branch: "%s"',
                    $currentBranch
                ),
                'details' => [
                    'current_branch' => $currentBranch,
                    'required_branch' => 'main',
                    'suggestion' => 'Switch to main branch using: git checkout main'
                ]
            ];
        }
        
        return [
            'status' => 'ok',
            'message' => 'Currently on main branch',
            'details' => [
                'current_branch' => $currentBranch,
                'required_branch' => 'main'
            ]
        ];
    }

    public function validateComposerEnvironment(): array
    {
        $process = new Process(['composer', '--version']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            return [
                'status' => 'error',
                'message' => 'Composer is not installed or not accessible',
                'details' => ['error' => $process->getErrorOutput() ?: 'Command failed with no output']
            ];
        }

        $rootPath = dirname(__DIR__, 4);
        $composerJson = $rootPath . '/composer.json';
        $composerLock = $rootPath . '/composer.lock';

        $issues = [];
        
        if (!$this->filesystem->exists($composerJson)) {
            $issues[] = 'composer.json not found in project root';
        }
        
        if (!$this->filesystem->exists($composerLock)) {
            $issues[] = 'composer.lock not found - run "composer install" first';
        }

        $warnings = [];
        if (empty($issues)) {
            $process = new Process(['composer', 'validate', '--no-check-publish']);
            $process->setTimeout(30);
            $process->run();
            
            $composerOutput = trim($process->getErrorOutput() ?: $process->getOutput());
            
            if (!$process->isSuccessful()) {
                if (stripos($composerOutput, 'is valid, but with a few warnings') !== false) {
                    $warnings[] = 'Composer has warnings (non-blocking): ' . $composerOutput;
                } else {
                    $issues[] = 'Composer validation failed: ' . ($composerOutput ?: 'Unknown error');
                }
            } elseif ($composerOutput && stripos($composerOutput, 'warning') !== false) {
                $warnings[] = 'Composer warnings: ' . $composerOutput;
            }

            if (empty($issues)) {
                $process2 = new Process(['composer', 'check-platform-reqs']);
                $process2->setTimeout(15);
                $process2->run();
                
                if (!$process2->isSuccessful()) {
                    $platformOutput = trim($process2->getErrorOutput() ?: $process2->getOutput());
                    if ($platformOutput) {
                        if (stripos($platformOutput, 'requires') !== false || stripos($platformOutput, 'missing') !== false) {
                            $issues[] = 'Platform requirements check failed: ' . $platformOutput;
                        } else {
                            $warnings[] = 'Platform check warnings: ' . $platformOutput;
                        }
                    }
                }
            }
        }

        if (!empty($issues)) {
            $status = 'error';
            $message = 'Composer environment has critical issues';
        } elseif (!empty($warnings)) {
            $status = 'warning';
            $message = 'Composer environment OK with warnings';
        } else {
            $status = 'ok';
            $message = 'Composer environment OK';
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'issues' => $issues,
                'warnings' => $warnings
            ]
        ];
    }

    public function validatePhpExtensions(): array
    {
        $requiredExtensions = [
            'pdo', 'pdo_mysql', 'json', 'mbstring', 'xml', 'zip', 'curl', 'intl'
        ];
        
        $recommendedExtensions = [
            'opcache', 'apcu', 'gd', 'imagick'
        ];

        $missing = [];
        $missingRecommended = [];

        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        foreach ($recommendedExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missingRecommended[] = $ext;
            }
        }

        $status = 'ok';
        $message = 'All PHP extensions OK';

        if (!empty($missing)) {
            $status = 'error';
            $message = 'Missing required PHP extensions: ' . implode(', ', $missing);
        } elseif (!empty($missingRecommended)) {
            $status = 'warning';
            $message = 'Missing recommended PHP extensions: ' . implode(', ', $missingRecommended);
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'missing_required' => $missing,
                'missing_recommended' => $missingRecommended,
                'php_version' => PHP_VERSION
            ]
        ];
    }

    public function isMaintenanceModeAvailable(): array
    {
        $process = new Process(['php', 'bin/console', 'list']);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'status' => 'warning',
                'message' => 'Cannot access console commands - maintenance mode may not be available',
                'details' => ['available' => false]
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Console commands accessible',
            'details' => ['available' => true]
        ];
    }

    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
