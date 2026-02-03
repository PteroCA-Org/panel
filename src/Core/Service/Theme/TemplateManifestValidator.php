<?php

namespace App\Core\Service\Theme;

use App\Core\DTO\TemplateManifestDTO;
use Composer\Semver\VersionParser;

class TemplateManifestValidator
{
    private const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const MAX_NAME_LENGTH = 50;
    private const MAX_DESCRIPTION_LENGTH = 5000;
    private const MAX_AUTHOR_LENGTH = 255;
    private const MAX_LICENSE_LENGTH = 50;
    private const VALID_CONTEXTS = ['panel', 'landing', 'email'];

    public function __construct(
        private readonly string $currentPterocaVersion,
    ) {}

    public function validate(TemplateManifestDTO $manifest): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->validateName($manifest->name));
        $errors = array_merge($errors, $this->validateVersion($manifest->version));
        $errors = array_merge($errors, $this->validateAuthor($manifest->author));
        $errors = array_merge($errors, $this->validateDescription($manifest->description));
        $errors = array_merge($errors, $this->validateLicense($manifest->license));
        $errors = array_merge($errors, $this->validatePterocaVersion($manifest->pterocaVersion));
        $errors = array_merge($errors, $this->validatePhpVersion($manifest->phpVersion));
        $errors = array_merge($errors, $this->validateContexts($manifest->contexts));

        return $errors;
    }

    private function validateName(string $name): array
    {
        $errors = [];

        if (empty($name)) {
            $errors[] = 'Name is required';
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = sprintf('Name too long (max %d characters)', self::MAX_NAME_LENGTH);
        }

        if (!preg_match(self::NAME_PATTERN, $name)) {
            $errors[] = 'Name must be lowercase alphanumeric with hyphens only (e.g., my-theme)';
        }

        return $errors;
    }

    private function validateVersion(string $version): array
    {
        if (empty($version)) {
            return ['Version is required'];
        }

        try {
            $parser = new VersionParser();
            $parser->normalize($version);
            return [];
        } catch (\Exception $e) {
            return ['Invalid version format. Must be semantic version (e.g., 1.0.0)'];
        }
    }

    private function validateAuthor(string $author): array
    {
        $errors = [];

        if (empty($author)) {
            $errors[] = 'Author is required';
        }

        if (strlen($author) > self::MAX_AUTHOR_LENGTH) {
            $errors[] = sprintf('Author too long (max %d characters)', self::MAX_AUTHOR_LENGTH);
        }

        return $errors;
    }

    private function validateDescription(string $description): array
    {
        $errors = [];

        if (empty($description)) {
            $errors[] = 'Description is required';
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $errors[] = sprintf('Description too long (max %d characters)', self::MAX_DESCRIPTION_LENGTH);
        }

        return $errors;
    }

    private function validateLicense(string $license): array
    {
        $errors = [];

        if (empty($license)) {
            $errors[] = 'License is required';
        }

        if (strlen($license) > self::MAX_LICENSE_LENGTH) {
            $errors[] = sprintf('License too long (max %d characters)', self::MAX_LICENSE_LENGTH);
        }

        return $errors;
    }

    private function validatePterocaVersion(string $version): array
    {
        if (empty($version)) {
            return ['PteroCA version is required'];
        }

        try {
            $parser = new VersionParser();
            $parser->normalize($version);
            return [];
        } catch (\Exception $e) {
            return ['Invalid PteroCA version format. Must be semantic version (e.g., 1.0.0)'];
        }
    }

    private function validatePhpVersion(string $version): array
    {
        if (empty($version)) {
            return ['PHP version is required'];
        }

        // Basic validation for PHP version constraint (e.g., ">=8.2")
        if (!preg_match('/^[><=!^~\s\d\.]+$/', $version)) {
            return ['Invalid PHP version format. Expected format like ">=8.2"'];
        }

        return [];
    }

    private function validateContexts(array $contexts): array
    {
        if (empty($contexts)) {
            return ['At least one context required (panel, landing, or email)'];
        }

        $invalid = array_diff($contexts, self::VALID_CONTEXTS);
        if (!empty($invalid)) {
            return [sprintf('Invalid contexts: %s. Valid: %s', implode(', ', $invalid), implode(', ', self::VALID_CONTEXTS))];
        }

        return [];
    }
}
