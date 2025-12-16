<?php

namespace App\Core\Service\Update\Operation;

use Exception;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseOperationService
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

    public function runMigrations(): void
    {
        exec('php bin/console doctrine:migrations:migrate --no-interaction', $output, $returnCode);
        if ($returnCode !== 0) {
            throw new RuntimeException('Failed to update database. Migration output: ' . implode("\n", $output));
        }

        if ($this->options['verbose'] ?? false) {
            $this->io->success('Database migrations completed successfully.');
            if (!empty($output)) {
                $this->io->text('Migration output: ' . implode("\n", $output));
            }
        }
    }

    public function canRollbackMigrations(): bool
    {
        exec('php bin/console doctrine:migrations:status 2>/dev/null', $output, $returnCode);
        
        if ($returnCode !== 0) {
            return false;
        }

        $outputString = implode("\n", $output);
        
        return str_contains($outputString, '[migrate]');
    }

    public function rollbackMigrations(string $targetVersion = null): void
    {
        if (!$this->canRollbackMigrations()) {
            $this->io->warning('No migrations to rollback.');
            return;
        }

        if ($targetVersion) {
            $command = "php bin/console doctrine:migrations:migrate $targetVersion --no-interaction";
        } else {
            $command = 'php bin/console doctrine:migrations:migrate prev --no-interaction';
        }

        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new RuntimeException('Failed to rollback database migrations. Output: ' . implode("\n", $output));
        }

        $this->io->success('Database migrations rolled back successfully.');
    }

    public function getCurrentSchemaVersion(): ?string
    {
        try {
            exec('php bin/console doctrine:migrations:status 2>/dev/null', $output, $returnCode);
            
            if ($returnCode !== 0) {
                return null;
            }

            $outputString = implode("\n", $output);
            
            preg_match('/Current Version:\s+(.+)/', $outputString, $matches);
            if (isset($matches[1])) {
                return trim($matches[1]);
            }
            
            preg_match_all('/\[migrate]\s+(\w+)/', $outputString, $allMatches);
            if (!empty($allMatches[1])) {
                return end($allMatches[1]);
            }
        } catch (Exception) {
            // Fall back to null if command fails
        }
        
        return null;
    }

    public function validateDatabaseConnection(): bool
    {
        try {
            exec('php bin/console doctrine:query:sql "SELECT 1" --no-interaction 2>/dev/null', $output, $returnCode);
            return $returnCode === 0;
        } catch (Exception) {
            return false;
        }
    }
}
