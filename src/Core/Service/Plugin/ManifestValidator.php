<?php

namespace App\Core\Service\Plugin;

use App\Core\DTO\PluginManifestDTO;
use App\Core\Service\SettingTypeMapperService;
use Composer\Semver\VersionParser;
use Exception;

class ManifestValidator
{
    private const VALID_CAPABILITIES = [
        'routes',      // HTTP routes and controllers
        'entities',    // Doctrine entities
        'migrations',  // Database migrations
        'ui',          // Widgets, templates, Twig extensions
        'eda',         // Event-driven architecture (subscribers)
        'console',     // Console commands
        'cron',        // Scheduled tasks
    ];

    private const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private const MAX_NAME_LENGTH = 50;

    private const MAX_DISPLAY_NAME_LENGTH = 255;

    private VersionParser $versionParser;

    private string $pterocaVersion;

    private SettingTypeMapperService $typeMapper;

    public function __construct(
        string $pterocaVersion = '0.6.3',
        ?SettingTypeMapperService $typeMapper = null
    ) {
        $this->versionParser = new VersionParser();
        $this->pterocaVersion = $pterocaVersion;
        $this->typeMapper = $typeMapper ?? new SettingTypeMapperService();
    }

    public function validate(PluginManifestDTO $manifest): array
    {
        $errors = [];

        // Validate name
        $errors = array_merge($errors, $this->validateName($manifest->name));

        // Validate display name
        $errors = array_merge($errors, $this->validateDisplayName($manifest->displayName));

        // Validate version
        $errors = array_merge($errors, $this->validateVersion($manifest->version));

        // Validate author
        $errors = array_merge($errors, $this->validateAuthor($manifest->author));

        // Validate description
        $errors = array_merge($errors, $this->validateDescription($manifest->description));

        // Validate license
        $errors = array_merge($errors, $this->validateLicense($manifest->license));

        // Validate PteroCA version requirements
        $errors = array_merge($errors, $this->validatePterocaRequirements($manifest->pteroca));

        // Validate capabilities
        $errors = array_merge($errors, $this->validateCapabilities($manifest->capabilities));

        // Validate dependencies
        $errors = array_merge($errors, $this->validateRequires($manifest->requires));

        // Validate config schema
        $errors = array_merge($errors, $this->validateConfigSchema($manifest->configSchema));

        // Validate bootstrap class (if provided)
        if ($manifest->bootstrapClass !== null) {
            $errors = array_merge($errors, $this->validateBootstrapClass($manifest->bootstrapClass));
        }

        // Validate marketplace URL (if provided)
        if ($manifest->marketplaceUrl !== null) {
            $errors = array_merge($errors, $this->validateMarketplaceUrl($manifest->marketplaceUrl));
        }

        // Validate assets (if provided)
        if ($manifest->assets !== null) {
            $errors = array_merge($errors, $this->validateAssets($manifest->assets));
        }

        return $errors;
    }

    public function isValid(PluginManifestDTO $manifest): bool
    {
        return count($this->validate($manifest)) === 0;
    }

    private function validateName(string $name): array
    {
        $errors = [];

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = "Plugin name exceeds maximum length of " . self::MAX_NAME_LENGTH . " characters";
        }

        if (!preg_match(self::NAME_PATTERN, $name)) {
            $errors[] = "Plugin name must match pattern: lowercase alphanumeric with hyphens (e.g., 'hello-world', 'acme-payments')";
        }

        return $errors;
    }

    private function validateDisplayName(string $displayName): array
    {
        $errors = [];

        if (strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
            $errors[] = "Display name exceeds maximum length of " . self::MAX_DISPLAY_NAME_LENGTH . " characters";
        }

        if (trim($displayName) === '') {
            $errors[] = "Display name cannot be empty";
        }

        return $errors;
    }

    private function validateVersion(string $version): array
    {
        $errors = [];

        try {
            $this->versionParser->normalize($version);
        } catch (Exception $e) {
            $errors[] = "Invalid semantic version '$version': {$e->getMessage()}";
        }

        return $errors;
    }

    private function validateAuthor(string $author): array
    {
        $errors = [];

        if (trim($author) === '') {
            $errors[] = "Author cannot be empty";
        }

        if (strlen($author) > 255) {
            $errors[] = "Author exceeds maximum length of 255 characters";
        }

        return $errors;
    }

    private function validateDescription(string $description): array
    {
        $errors = [];

        if (trim($description) === '') {
            $errors[] = "Description cannot be empty";
        }

        if (strlen($description) > 5000) {
            $errors[] = "Description exceeds maximum length of 5000 characters";
        }

        return $errors;
    }

    private function validateLicense(string $license): array
    {
        $errors = [];

        if (trim($license) === '') {
            $errors[] = "License cannot be empty";
        }

        if (strlen($license) > 50) {
            $errors[] = "License exceeds maximum length of 50 characters";
        }

        return $errors;
    }

    private function validatePterocaRequirements(array $pteroca): array
    {
        $errors = [];

        if (!isset($pteroca['min'])) {
            $errors[] = "PteroCA minimum version (pteroca.min) is required";

            return $errors;
        }

        // Validate min version format
        try {
            $this->versionParser->normalize($pteroca['min']);
        } catch (Exception $e) {
            $errors[] = "Invalid PteroCA minimum version '{$pteroca['min']}': {$e->getMessage()}";
        }

        // Validate max version format (if provided)
        if (isset($pteroca['max'])) {
            try {
                $this->versionParser->normalize($pteroca['max']);
            } catch (Exception $e) {
                $errors[] = "Invalid PteroCA maximum version '{$pteroca['max']}': {$e->getMessage()}";
            }
        }

        return $errors;
    }

    private function validateCapabilities(array $capabilities): array
    {
        $errors = [];

        if (count($capabilities) === 0) {
            $errors[] = "Plugin must declare at least one capability";

            return $errors;
        }

        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                $errors[] = "Capability must be a string, got: " . gettype($capability);
                continue;
            }

            if (!in_array($capability, self::VALID_CAPABILITIES, true)) {
                $errors[] = "Invalid capability '$capability'. Valid capabilities: " . implode(', ', self::VALID_CAPABILITIES);
            }
        }

        // Check for duplicates
        $unique = array_unique($capabilities);
        if (count($unique) !== count($capabilities)) {
            $errors[] = "Duplicate capabilities found";
        }

        return $errors;
    }

    private function validateRequires(array $requires): array
    {
        $errors = [];

        foreach ($requires as $pluginName => $versionConstraint) {
            // Validate plugin name
            if (!is_string($pluginName) || !preg_match(self::NAME_PATTERN, $pluginName)) {
                $errors[] = "Invalid dependency plugin name '$pluginName'";
                continue;
            }

            // Validate version constraint
            if (!is_string($versionConstraint)) {
                $errors[] = "Version constraint for '$pluginName' must be a string";
                continue;
            }

            try {
                $this->versionParser->parseConstraints($versionConstraint);
            } catch (Exception $e) {
                $errors[] = "Invalid version constraint for '$pluginName': {$e->getMessage()}";
            }
        }

        return $errors;
    }

    private function validateConfigSchema(array $configSchema): array
    {
        $errors = [];

        foreach ($configSchema as $key => $schema) {
            if (!is_array($schema)) {
                $errors[] = "Config schema for '$key' must be an object";
                continue;
            }

            // Validate type
            if (!isset($schema['type'])) {
                $errors[] = "Config schema for '$key' must have 'type' field";
            } elseif (!$this->typeMapper->isValidStorageType($schema['type'])) {
                $validTypes = implode(', ', $this->typeMapper->getValidStorageTypes());
                $errors[] = "Invalid type '{$schema['type']}' for config '$key'. Valid types: {$validTypes}";
            }

            // Validate hierarchy
            if (!isset($schema['hierarchy'])) {
                $errors[] = "Config schema for '$key' must have 'hierarchy' field";
            } elseif (!is_string($schema['hierarchy'])) {
                $errors[] = "Config schema hierarchy for '$key' must be a string";
            }
        }

        return $errors;
    }

    private function validateBootstrapClass(string $bootstrapClass): array
    {
        $errors = [];

        // Check if class name is valid format
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $bootstrapClass)) {
            $errors[] = "Invalid bootstrap class name format: '$bootstrapClass'";
        }

        // Check if it starts with Plugins\ namespace
        if (!str_starts_with($bootstrapClass, 'Plugins\\')) {
            $errors[] = "Bootstrap class must be in 'Plugins\\' namespace";
        }

        return $errors;
    }

    private function validateMarketplaceUrl(string $url): array
    {
        $errors = [];

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "Invalid marketplace URL: '$url'";
        }

        if (strlen($url) > 500) {
            $errors[] = "Marketplace URL exceeds maximum length of 500 characters";
        }

        return $errors;
    }

    private function validateAssets(array $assets): array
    {
        $errors = [];
        $allowedTypes = ['css', 'js', 'img', 'fonts'];

        foreach ($assets as $type => $files) {
            // Validate asset type
            if (!in_array($type, $allowedTypes, true)) {
                $errors[] = "Invalid asset type '$type'. Allowed types: " . implode(', ', $allowedTypes);
                continue;
            }

            // Validate that files is an array
            if (!is_array($files)) {
                $errors[] = "Asset type '$type' must be an array of file paths";
                continue;
            }

            // Validate each file path
            foreach ($files as $index => $file) {
                if (!is_string($file)) {
                    $errors[] = "Asset path in '{$type}[$index]' must be a string";
                    continue;
                }

                // Security: prevent directory traversal
                if (str_contains($file, '..')) {
                    $errors[] = "Asset path '$file' contains directory traversal (..)";
                }

                // Security: prevent absolute paths
                if (str_starts_with($file, '/') || str_starts_with($file, '\\')) {
                    $errors[] = "Asset path '$file' must be relative (cannot start with / or \\)";
                }

                // Validate file extension matches type
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if ($type === 'css' && $extension !== 'css') {
                    $errors[] = "CSS asset '$file' must have .css extension";
                }
                if ($type === 'js' && $extension !== 'js') {
                    $errors[] = "JS asset '$file' must have .js extension";
                }
            }
        }

        return $errors;
    }

    public function isCompatibleWithPteroCA(PluginManifestDTO $manifest): bool
    {
        try {
            $minVersion = $manifest->getMinPterocaVersion();
            $maxVersion = $manifest->getMaxPterocaVersion();

            // Check minimum version
            if (version_compare($this->pterocaVersion, $minVersion, '<')) {
                return false;
            }

            // Check maximum version (if specified)
            if ($maxVersion !== null && version_compare($this->pterocaVersion, $maxVersion, '>')) {
                return false;
            }

            return true;
        } catch (Exception) {
            return false;
        }
    }

    public function getCompatibilityError(PluginManifestDTO $manifest): ?string
    {
        if ($this->isCompatibleWithPteroCA($manifest)) {
            return null;
        }

        $minVersion = $manifest->getMinPterocaVersion();
        $maxVersion = $manifest->getMaxPterocaVersion();

        if (version_compare($this->pterocaVersion, $minVersion, '<')) {
            return "Plugin requires PteroCA >= $minVersion, current version is $this->pterocaVersion";
        }

        if ($maxVersion !== null && version_compare($this->pterocaVersion, $maxVersion, '>')) {
            return "Plugin requires PteroCA <= $maxVersion, current version is $this->pterocaVersion";
        }

        return "Unknown compatibility error";
    }
}
