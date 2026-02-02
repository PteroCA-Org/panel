<?php

namespace App\Core\Service\Template;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use ZipArchive;

class ThemeExportService
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $projectDir,
    ) {}

    /**
     * Export theme as ZIP file
     */
    public function exportTheme(string $themeName): string
    {
        $themePath = $this->projectDir . '/themes/' . $themeName;
        $assetsPath = $this->projectDir . '/public/assets/theme/' . $themeName;
        $tempDir = $this->projectDir . '/var/tmp';

        if (!$this->filesystem->exists($themePath)) {
            throw new \RuntimeException("Theme directory not found: {$themePath}");
        }

        if (!$this->filesystem->exists($tempDir)) {
            $this->filesystem->mkdir($tempDir);
        }

        $zipFilename = $tempDir . '/' . $themeName . '-' . date('Y-m-d-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Failed to create ZIP file: {$zipFilename}");
        }

        try {
            // Add theme files to ZIP under themes/{theme-name}/
            $this->addDirectoryToZip($zip, $themePath, 'themes/' . $themeName);

            // Add assets to ZIP under public/assets/theme/{theme-name}/ if they exist
            if ($this->filesystem->exists($assetsPath)) {
                $this->addDirectoryToZip($zip, $assetsPath, 'public/assets/theme/' . $themeName);
            }

            $zip->close();

            return $zipFilename;
        } catch (\Exception $e) {
            $zip->close();
            if ($this->filesystem->exists($zipFilename)) {
                $this->filesystem->remove($zipFilename);
            }
            throw $e;
        }
    }

    /**
     * Add directory contents to ZIP recursively
     */
    private function addDirectoryToZip(ZipArchive $zip, string $sourcePath, string $zipPath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourcePath) + 1);
            $zipFilePath = $zipPath . '/' . $relativePath;

            if ($file->isDir()) {
                $zip->addEmptyDir($zipFilePath);
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }

    /**
     * Create a download response for the exported ZIP file
     */
    public function createDownloadResponse(string $zipFilePath, string $themeName): BinaryFileResponse
    {
        $response = new BinaryFileResponse($zipFilePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $themeName . '-' . date('Y-m-d') . '.zip'
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
