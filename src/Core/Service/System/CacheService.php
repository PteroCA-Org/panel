<?php

namespace App\Core\Service\System;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class CacheService
{
    private bool $shutdownScheduled = false;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Schedule full cache clearing on shutdown — files are removed
     * after the response is sent but before the process ends.
     */
    public function clearCacheOnShutdown(): void
    {
        if ($this->shutdownScheduled) {
            return;
        }

        $this->shutdownScheduled = true;
        $cacheDir = $this->kernel->getCacheDir();

        register_shutdown_function(function () use ($cacheDir) {
            try {
                (new Filesystem())->remove($cacheDir);
            } catch (\Throwable) {
            }
        });
    }
}
