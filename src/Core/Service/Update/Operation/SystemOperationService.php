<?php

namespace App\Core\Service\Update\Operation;

use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use function dirname;

class SystemOperationService
{
    private SymfonyStyle $io;
    private array $options = [];

    public function setIo(SymfonyStyle $io): self
    {
        $this->io = $io;
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function clearCache(): void
    {
        exec('php bin/console cache:clear', $output, $returnCode);
        if ($returnCode !== 0) {
            throw new RuntimeException('Failed to clear cache. Output: ' . implode("\n", $output));
        }

        if ($this->options['verbose'] ?? false) {
            $this->io->success('Application cache cleared successfully.');
            if (!empty($output)) {
                $this->io->text('Cache clear output: ' . implode("\n", $output));
            }
        }
    }

    public function adjustFilePermissions(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            if ($this->options['verbose'] ?? false) {
                $this->io->text('Skipping file permissions adjustment - not on Linux system.');
            }
            return;
        }

        $directoryToCheck = dirname(__DIR__, 5);

        if (!$this->isRunningAsRoot()) {
            if ($this->options['verbose'] ?? false) {
                $this->io->text('Not running as root - skipping file permissions adjustment.');
            }
            return;
        }

        if ($this->isMountedVolume($directoryToCheck)) {
            if ($this->options['verbose'] ?? false) {
                $this->io->text('Directory is a mounted volume - skipping permissions adjustment to preserve host ownership.');
            }
            return;
        }

        $originalOwner = $this->detectOriginalOwner($directoryToCheck);
        $directoryToCheckEscaped = escapeshellarg($directoryToCheck);

        $candidateOwners = [
            'www-data:www-data',
            'nginx:nginx',
            'apache:apache',
        ];

        $ownerChanged = false;
        foreach ($candidateOwners as $candidate) {
            [$user] = explode(':', $candidate);

            $exitCode = 0;
            $output = [];
            exec(sprintf('id -u %s 2>/dev/null', escapeshellarg($user)), $output, $exitCode);

            if ($exitCode === 0) {
                exec(sprintf('chown -R %s %s', escapeshellarg($candidate), $directoryToCheckEscaped));
                $ownerChanged = true;
                
                if ($this->options['verbose'] ?? false) {
                    $this->io->success("File permissions adjusted to $candidate.");
                }
                break;
            }
        }

        if ($ownerChanged && $originalOwner) {
            $ownerFile = $directoryToCheck . '/var/.original_owner';
            @mkdir(dirname($ownerFile), 0755, true);
            file_put_contents($ownerFile, $originalOwner . '|' . $directoryToCheck);
        }

        if (!$ownerChanged && ($this->options['verbose'] ?? false)) {
            $this->io->warning('Could not find appropriate web server user for file permissions.');
        }
    }

    public function restoreFileOwnership(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }

        $directoryToCheck = \dirname(__DIR__, 5);
        $ownerFile = $directoryToCheck . '/var/.original_owner';

        if (!file_exists($ownerFile)) {
            if ($this->options['verbose'] ?? false) {
                $this->io->text('No ownership restoration needed.');
            }
            return;
        }

        $data = file_get_contents($ownerFile);
        [$originalOwner, $directory] = explode('|', $data);

        if ($this->options['verbose'] ?? false) {
            $this->io->text("Restoring original owner: {$originalOwner}");
        }

        $command = sprintf('chown -R %s %s 2>&1', escapeshellarg($originalOwner), escapeshellarg($directory));
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            if ($this->options['verbose'] ?? false) {
                $this->io->warning("Could not restore original owner. Manual restoration may be needed: chown -R {$originalOwner} {$directory}");
            }
        } else {
            if ($this->options['verbose'] ?? false) {
                $this->io->success("Original owner restored: {$originalOwner}");
            }
            @unlink($ownerFile);
        }
    }

    private function isRunningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    private function isMountedVolume(string $directory): bool
    {
        if (!file_exists('/proc/mounts')) {
            return false;
        }

        $mounts = @file_get_contents('/proc/mounts');
        if ($mounts === false) {
            return false;
        }

        $realPath = realpath($directory);
        if ($realPath === false) {
            return false;
        }

        $lines = explode("\n", $mounts);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 2) {
                $mountPoint = $parts[1];
                if ($mountPoint === $realPath || strpos($realPath, $mountPoint . '/') === 0) {
                    if (in_array($parts[0], ['/dev/sda', '/dev/sdb', '/dev/sdc', '/dev/sdd', '/dev/sde'], true) ||
                        strpos($parts[0], '/dev/sd') === 0 ||
                        strpos($parts[2], 'ext4') !== false ||
                        strpos($parts[2], 'ext3') !== false) {

                        if ($this->options['verbose'] ?? false) {
                            $this->io->text("Detected mounted volume: {$parts[0]} on {$mountPoint}");
                        }
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function detectOriginalOwner(string $directory): ?string
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_getgrgid')) {
            return null;
        }

        $stat = @stat($directory);
        if ($stat === false) {
            return null;
        }

        $ownerInfo = posix_getpwuid($stat['uid']);
        $groupInfo = posix_getgrgid($stat['gid']);

        if ($ownerInfo === false || $groupInfo === false) {
            return null;
        }

        $owner = $ownerInfo['name'] . ':' . $groupInfo['name'];

        if ($this->options['verbose'] ?? false) {
            $this->io->text("Detected original owner: {$owner}");
        }

        return $owner;
    }


    public function validateSystemRequirements(): void
    {
        $this->checkWritePermissions();
        $this->checkRequiredCommands();
    }

    public function ensureDirectoryPermissions(): void
    {
        $directoriesToCheck = [
            'var/cache',
            'var/log',
            'var/backups',
            'var/locks',
            'plugins',
        ];

        $rootPath = dirname(__DIR__, 5);
        
        foreach ($directoriesToCheck as $dir) {
            $fullPath = $rootPath . '/' . $dir;
            
            if (!is_dir($fullPath)) {
                if (!mkdir($fullPath, 0755, true) && !is_dir($fullPath)) {
                    throw new RuntimeException("Could not create directory: $fullPath");
                }
            }

            if (!is_writable($fullPath)) {
                throw new RuntimeException("Directory is not writable: $fullPath");
            }
        }

        if ($this->options['verbose'] ?? false) {
            $this->io->success('Directory permissions validated successfully.');
        }
    }

    private function checkWritePermissions(): void
    {
        $directoryToCheck = dirname(__DIR__, 5);
        $testFile = $directoryToCheck . DIRECTORY_SEPARATOR . 'permission_test_' . uniqid() . '.tmp';

        $fp = @fopen($testFile, 'w');
        if ($fp === false) {
            throw new RuntimeException(sprintf(
                'You do not have write permissions to the directory "%s". Run the command with sudo/as root or adjust permissions.',
                $directoryToCheck
            ));
        }

        fclose($fp);
        @unlink($testFile);

        if ($this->options['verbose'] ?? false) {
            $this->io->text('Write permissions verified.');
        }
    }

    private function checkRequiredCommands(): void
    {
        $requiredCommands = ['git', 'composer', 'php'];
        $missingCommands = [];

        foreach ($requiredCommands as $command) {
            exec("which $command 2>/dev/null", $output, $returnCode);
            if ($returnCode !== 0) {
                $missingCommands[] = $command;
            }
        }

        if (!empty($missingCommands)) {
            throw new RuntimeException('Missing required commands: ' . implode(', ', $missingCommands));
        }

        if ($this->options['verbose'] ?? false) {
            $this->io->text('All required system commands are available.');
        }
    }

    public function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os_family' => PHP_OS_FAMILY,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'environment' => $_ENV['APP_ENV'] ?? 'prod',
        ];
    }
}
