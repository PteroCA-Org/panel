<?php

namespace App\Core\DTO\Action\Result;

class ConfiguratorVerificationResult
{
    public function __construct(
        public bool $isVerificationSuccessful,
        public string $message = '',
        public ?string $warning = null,
    ) {}
}