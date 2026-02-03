<?php

namespace App\Core\Service\Theme;

use App\Core\DTO\TemplateManifestDTO;
use App\Core\Exception\Theme\InvalidTemplateManifestException;
use App\Core\Exception\Plugin\MissingManifestException;

class TemplateManifestParser
{
    public function parseFromDirectory(string $themeRoot): TemplateManifestDTO
    {
        $manifestPath = $themeRoot . '/template.json';

        if (!file_exists($manifestPath)) {
            throw new MissingManifestException('template.json not found in theme root');
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            throw new InvalidTemplateManifestException('Failed to read template.json');
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidTemplateManifestException('Invalid JSON in template.json: ' . json_last_error_msg());
        }

        if (!isset($data['template'])) {
            throw new InvalidTemplateManifestException('template.json must have "template" root key');
        }

        return TemplateManifestDTO::fromArray($data['template']);
    }
}
