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
            $this->addDirectoryToZip($zip, $themePath, 'themes/' . $themeName);

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
