<?php

namespace App\Core\Service\Update\Operation;

use App\Core\Service\Composer\ComposerBinaryResolverService;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;

class ComposerOperationService
{
    private string $composerBinary;
    private SymfonyStyle $io;
    private array $options = [];

    public function __construct(
        ComposerBinaryResolverService $composerResolver
    ) {
        $this->composerBinary = $composerResolver->resolveComposerBinary();
    }

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

    public function installDependencies(): void
    {
        $isDev = strtolower($_ENV['APP_ENV'] ?? '') === 'dev';
        
        if ($this->options['force-composer'] ?? false) {
            $this->io->note('Using --force-composer option: installing dependencies with --ignore-platform-reqs');
            $this->installWithIgnorePlatform($isDev);
            return;
        }

        $composerCommand = $this->buildComposerCommand(
            'install %s --optimize-autoloader --no-interaction 2>&1',
            $isDev ? '' : '--no-dev'
        );

        exec($composerCommand, $output, $returnCode);
        if ($returnCode !== 0) {
            $outputString = implode("\n", $output);
            
            if ($this->isPlatformDependencyError($outputString)) {
                $this->io->warning('Composer installation failed due to platform requirements.');
                $this->io->text('This typically happens when the server is missing PHP extensions or has different PHP version requirements.');
                
                if ($this->io->confirm('Do you want to ignore platform dependencies and continue with the update?', false)) {
                    $this->installWithIgnorePlatform($isDev);
                    return;
                }
                
                throw new RuntimeException('Update aborted due to composer dependency issues.');
            }
            
            throw new RuntimeException('Failed to install composer dependencies. Composer output: ' . $outputString);
        }
    }

    public function installWithIgnorePlatform(bool $isDev): void
    {
        $composerCommand = $this->buildComposerCommand(
            'install %s --optimize-autoloader --no-interaction --ignore-platform-reqs 2>&1',
            $isDev ? '' : '--no-dev'
        );

        $this->io->note('Running composer install with --ignore-platform-reqs...');
        
        exec($composerCommand, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new RuntimeException('Failed to install composer dependencies even with --ignore-platform-reqs. Composer output: ' . implode("\n", $output));
        }

        $this->io->success('Composer dependencies installed successfully (with ignored platform requirements).');
        $this->io->warning('Note: Platform requirements were ignored. Make sure your server environment is compatible.');
    }

    public function isPlatformDependencyError(string $output): bool
    {
        $platformErrorPatterns = [
            'requires php',
            'platform requirements',
            'requires ext-',
            'Your requirements could not be resolved',
            'requires',
        ];
        
        $lowerOutput = strtolower($output);
        
        foreach ($platformErrorPatterns as $pattern) {
            if (str_contains($lowerOutput, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    public function shouldUseForcedInstall(): bool
    {
        return $this->options['force-composer'] ?? false;
    }

    private function buildComposerCommand(string $commandTemplate, string ...$args): string
    {
        $this->ensureSuperuserAllowed();

        $envPrefix = $this->buildEnvironmentPrefix();

        return sprintf(
            '%s%s ' . $commandTemplate,
            $envPrefix,
            escapeshellcmd($this->composerBinary),
            ...$args
        );
    }

    private function ensureSuperuserAllowed(): void
    {
        if ($this->isRunningAsRoot()) {
            putenv('COMPOSER_ALLOW_SUPERUSER=1');
        }
    }

    private function buildEnvironmentPrefix(): string
    {
        $envVars = [];

        if ($this->isRunningAsRoot()) {
            $envVars[] = 'COMPOSER_ALLOW_SUPERUSER=1';
        }

        if (!getenv('HOME') && getenv('COMPOSER_HOME')) {
            $envVars[] = 'HOME=' . escapeshellarg(getenv('COMPOSER_HOME'));
        }

        return !empty($envVars) ? implode(' ', $envVars) . ' ' : '';
    }

    private function isRunningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }
}
