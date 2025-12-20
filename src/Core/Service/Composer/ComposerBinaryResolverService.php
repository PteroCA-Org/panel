<?php

namespace App\Core\Service\Composer;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves the composer binary path across different systems.
 * Tries multiple fallback locations to ensure cross-platform compatibility.
 */
readonly class ComposerBinaryResolverService
{
    public function __construct(
        private ?string $composerBinaryEnv = null
    ) {}

    /**
     * Resolve composer binary path with fallback chain.
     *
     * @return string Absolute path to composer executable
     */
    public function resolveComposerBinary(): string
    {
        if ($this->composerBinaryEnv && $this->isExecutable($this->composerBinaryEnv)) {
            return $this->composerBinaryEnv;
        }

        $finder = new ExecutableFinder();
        $pathComposer = $finder->find('composer');
        if ($pathComposer !== null && $this->isExecutable($pathComposer)) {
            return $pathComposer;
        }

        $commonPaths = $this->getCommonComposerPaths();
        foreach ($commonPaths as $path) {
            if ($this->isExecutable($path)) {
                return $path;
            }
        }

        return 'composer';
    }

    /**
     * Get list of common composer installation paths.
     * Ordered by likelihood (most common first).
     *
     * @return array<string>
     */
    private function getCommonComposerPaths(): array
    {
        return [
            // Linux (most distributions)
            '/usr/bin/composer',
            '/usr/local/bin/composer',

            // macOS (Homebrew)
            '/opt/homebrew/bin/composer',  // Apple Silicon
            '/usr/local/bin/composer',     // Intel Mac

            // Generic Unix
            '/bin/composer',

            // Windows (if running under WSL or similar)
            '/mnt/c/ProgramData/ComposerSetup/bin/composer',
        ];
    }

    /**
     * Check if path is executable.
     * Handles both files and symlinks.
     */
    private function isExecutable(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        // On Windows, executables might not have executable bit
        if (PHP_OS_FAMILY === 'Windows') {
            return is_file($path);
        }

        return is_executable($path);
    }
}
