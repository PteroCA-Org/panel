<?php

namespace App\Core\DTO;

/**
 * Theme Data Transfer Object
 * Represents a theme from filesystem without database entity
 */
class ThemeDTO
{
    public function __construct(
        private readonly string $name,
        private readonly string $displayName,
        private readonly string $version,
        private readonly string $author,
        private readonly string $description,
        private readonly string $license,
        private readonly array $contexts,
        private readonly array $translations,
        private readonly array $options,
        private readonly string $pterocaVersion,
        private readonly string $phpVersion,
        private readonly bool $isActive,
        private readonly ?string $context,
        private readonly array $activeContexts = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getLicense(): string
    {
        return $this->license;
    }

    public function getContexts(): array
    {
        return $this->contexts;
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getPterocaVersion(): string
    {
        return $this->pterocaVersion;
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getActiveContexts(): array
    {
        return $this->activeContexts;
    }

    public function isActiveInContext(string $context): bool
    {
        return in_array($context, $this->activeContexts, true);
    }

    public function isActiveInAnyContext(): bool
    {
        return !empty($this->activeContexts);
    }

    public function supportsDarkMode(): bool
    {
        return $this->options['supportDarkMode'] ?? false;
    }

    public function supportsCustomColors(): bool
    {
        return $this->options['supportCustomColors'] ?? false;
    }
}
