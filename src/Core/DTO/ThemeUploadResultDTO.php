<?php

namespace App\Core\DTO;

class ThemeUploadResultDTO
{
    /**
     * @param ThemeUploadWarningDTO[] $warnings
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?TemplateManifestDTO $manifest,
        public readonly ?string $themePath,
        public readonly ?string $assetsPath,
        public readonly array $warnings = [],
        public readonly ?string $error = null,
    ) {}

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }
}
