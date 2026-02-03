<?php

namespace App\Core\DTO;

class ThemeUploadWarningDTO
{
    public function __construct(
        public readonly string $type,
        public readonly string $severity,
        public readonly string $message,
        public readonly array $details = [],
    ) {}
}
