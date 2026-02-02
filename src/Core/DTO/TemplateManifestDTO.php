<?php

namespace App\Core\DTO;

class TemplateManifestDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $author,
        public readonly string $version,
        public readonly string $license,
        public readonly string $pterocaVersion,
        public readonly string $phpVersion,
        public readonly array $contexts,
        public readonly array $translations,
        public readonly array $options,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            author: $data['author'] ?? '',
            version: $data['version'] ?? '',
            license: $data['license'] ?? '',
            pterocaVersion: $data['pterocaVersion'] ?? '',
            phpVersion: $data['phpVersion'] ?? '',
            contexts: $data['contexts'] ?? [],
            translations: $data['translations'] ?? [],
            options: $data['options'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'author' => $this->author,
            'version' => $this->version,
            'license' => $this->license,
            'pterocaVersion' => $this->pterocaVersion,
            'phpVersion' => $this->phpVersion,
            'contexts' => $this->contexts,
            'translations' => $this->translations,
            'options' => $this->options,
        ];
    }
}
