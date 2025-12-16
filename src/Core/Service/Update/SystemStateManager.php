<?php

namespace App\Core\Service\Update;

use Exception;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class SystemStateManager
{
    private const STATE_FILE = 'var/cache/update_state.json';

    private Filesystem $filesystem;
    private array $systemState = [];

    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function captureSystemState(): array
    {
        $this->systemState = [
            'timestamp' => time(),
            'git_commit' => $this->getCurrentGitCommit(),
            'git_branch' => $this->getCurrentGitBranch(),
            'composer_lock_hash' => $this->getComposerLockHash(),
            'db_schema_version' => $this->getCurrentSchemaVersion(),
            'cache_state' => $this->getCacheState(),
            'app_version' => $this->getAppVersion(),
            'php_version' => PHP_VERSION,
            'environment' => $_ENV['APP_ENV'] ?? 'prod'
        ];

        $this->saveState();
        return $this->systemState;
    }

    public function getSystemState(): array
    {
        if (empty($this->systemState)) {
            $this->loadState();
        }
        return $this->systemState;
    }

    public function hasValidState(): bool
    {
        $state = $this->getSystemState();
        return !empty($state) && isset($state['timestamp']) && 
               (time() - $state['timestamp']) < 86400; // Valid for 24 hours
    }

    public function clearState(): void
    {
        $stateFile = $this->getStateFilePath();
        if ($this->filesystem->exists($stateFile)) {
            $this->filesystem->remove($stateFile);
        }
        $this->systemState = [];
    }

    public function compareStates(array $beforeState, array $afterState): array
    {
        $changes = [];

        $compareKeys = ['git_commit', 'composer_lock_hash', 'db_schema_version', 'app_version'];
        
        foreach ($compareKeys as $key) {
            $before = $beforeState[$key] ?? null;
            $after = $afterState[$key] ?? null;
            
            if ($before !== $after) {
                $changes[$key] = [
                    'before' => $before,
                    'after' => $after
                ];
            }
        }

        return $changes;
    }

    public function canRollbackTo(array $targetState): bool
    {
        $requiredKeys = ['git_commit', 'db_schema_version'];
        
        foreach ($requiredKeys as $key) {
            if (empty($targetState[$key])) {
                return false;
            }
        }

        return $this->gitCommitExists($targetState['git_commit']);
    }

    public function getStateSummary(array $state): string
    {
        $summary = [];
        
        if (isset($state['git_commit'])) {
            $summary[] = "Git: " . substr($state['git_commit'], 0, 8);
        }
        
        if (isset($state['app_version'])) {
            $summary[] = "Version: " . $state['app_version'];
        }
        
        if (isset($state['db_schema_version'])) {
            $summary[] = "DB: " . $state['db_schema_version'];
        }
        
        if (isset($state['timestamp'])) {
            $summary[] = "Captured: " . date('Y-m-d H:i:s', $state['timestamp']);
        }

        return implode(', ', $summary);
    }

    private function getCurrentGitCommit(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD']);
        $process->run();
        
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        
        return null;
    }

    private function getCurrentGitBranch(): ?string
    {
        $process = new Process(['git', 'branch', '--show-current']);
        $process->run();
        
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        
        return null;
    }

    private function getComposerLockHash(): ?string
    {
        $composerLockPath = dirname(__DIR__, 4) . '/composer.lock';
        
        if (!$this->filesystem->exists($composerLockPath)) {
            return null;
        }

        $composerLockContent = file_get_contents($composerLockPath);
        $composerLockData = json_decode($composerLockContent, true);
        
        return $composerLockData['content-hash'] ?? md5($composerLockContent);
    }

    private function getCurrentSchemaVersion(): ?string
    {
        try {
            $process = new Process(['php', 'bin/console', 'doctrine:migrations:status']);
            $process->setTimeout(30);
            $process->run();
            
            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                
                preg_match('/Current Version:\s+(.+)/', $output, $matches);
                if (isset($matches[1])) {
                    return trim($matches[1]);
                }
                
                preg_match_all('/\[migrate]\s+(\w+)/', $output, $allMatches);
                if (!empty($allMatches[1])) {
                    return end($allMatches[1]);
                }
            }
        } catch (Exception) {
            return $this->getCurrentSchemaVersionFromDatabase();
        }
        
        return null;
    }

    private function getCacheState(): array
    {
        $cacheDir = dirname(__DIR__, 4) . '/var/cache';
        
        $state = [
            'exists' => $this->filesystem->exists($cacheDir),
            'writable' => is_writable($cacheDir),
            'size' => 0
        ];

        if ($state['exists']) {
            $state['size'] = $this->getDirectorySize($cacheDir);
        }

        return $state;
    }

    private function getAppVersion(): ?string
    {
        $process = new Process(['git', 'describe', '--tags', '--exact-match']);
        $process->run();
        
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        
        $process = new Process(['git', 'describe', '--tags']);
        $process->run();
        
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        
        $composerPath = dirname(__DIR__, 4) . '/composer.json';
        if ($this->filesystem->exists($composerPath)) {
            $composerData = json_decode(file_get_contents($composerPath), true);
            if (isset($composerData['version'])) {
                return $composerData['version'];
            }
        }
        
        return 'dev';
    }

    private function gitCommitExists(string $commit): bool
    {
        $process = new Process(['git', 'cat-file', '-e', $commit]);
        $process->run();
        
        return $process->isSuccessful();
    }

    private function getDirectorySize(string $directory): int
    {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function saveState(): void
    {
        $stateFile = $this->getStateFilePath();
        $stateDir = dirname($stateFile);
        
        if (!$this->filesystem->exists($stateDir)) {
            $this->filesystem->mkdir($stateDir, 0755);
        }

        $this->filesystem->dumpFile(
            $stateFile, 
            json_encode($this->systemState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function loadState(): void
    {
        $stateFile = $this->getStateFilePath();
        
        if ($this->filesystem->exists($stateFile)) {
            $stateContent = file_get_contents($stateFile);
            $this->systemState = json_decode($stateContent, true) ?? [];
        }
    }

    private function getCurrentSchemaVersionFromDatabase(): ?string
    {
        try {
            $pdo = new PDO('sqlite:' . dirname(__DIR__, 4) . '/var/data.db');
            $stmt = $pdo->query('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result['version'] : null;
        } catch (Exception) {
            return null;
        }
    }

    private function getStateFilePath(): string
    {
        return dirname(__DIR__, 4) . '/' . self::STATE_FILE;
    }
}
