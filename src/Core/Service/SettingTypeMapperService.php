<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Enum\SettingTypeEnum;

/**
 * Service for mapping between config_schema storage types and SettingTypeEnum display types.
 *
 * Plugin config_schema uses simple types (string, integer, boolean, etc.) which need to be
 * mapped to SettingTypeEnum values for proper UI rendering and database storage.
 *
 * Mapping:
 * - 'string'  → 'text'     (SettingTypeEnum::TEXT)
 * - 'integer' → 'number'   (SettingTypeEnum::NUMBER)
 * - 'float'   → 'number'   (SettingTypeEnum::NUMBER)
 * - 'boolean' → 'boolean'  (SettingTypeEnum::BOOLEAN)
 * - 'json'    → 'textarea' (SettingTypeEnum::TEXTAREA)
 * - 'array'   → 'textarea' (SettingTypeEnum::TEXTAREA)
 */
readonly class SettingTypeMapperService
{
    /**
     * Valid storage types that can be used in plugin config_schema.
     */
    private const VALID_STORAGE_TYPES = [
        'string',
        'integer',
        'float',
        'boolean',
        'json',
        'array',
    ];

    /**
     * Convert a config_schema storage type to a SettingTypeEnum display type.
     *
     * This is used when storing settings from plugin config_schema to ensure
     * the database contains SettingTypeEnum values that the UI can render.
     *
     * @param string $storageType Type from plugin config_schema (e.g., 'string', 'integer')
     * @return string SettingTypeEnum value (e.g., 'text', 'number')
     */
    public function toDisplayType(string $storageType): string
    {
        return match ($storageType) {
            'string' => SettingTypeEnum::TEXT->value,
            'integer', 'float' => SettingTypeEnum::NUMBER->value,
            'boolean' => SettingTypeEnum::BOOLEAN->value,
            'json', 'array' => SettingTypeEnum::TEXTAREA->value,
            // If already a valid SettingTypeEnum value, pass through
            SettingTypeEnum::TEXT->value,
            SettingTypeEnum::TEXTAREA->value,
            SettingTypeEnum::SECRET->value,
            SettingTypeEnum::COLOR->value,
            SettingTypeEnum::NUMBER->value,
            SettingTypeEnum::LOCALE->value,
            SettingTypeEnum::TWIG->value,
            SettingTypeEnum::URL->value,
            SettingTypeEnum::EMAIL->value,
            SettingTypeEnum::IMAGE->value,
            SettingTypeEnum::SELECT->value => $storageType,
            // Safe fallback for unknown types
            default => SettingTypeEnum::TEXT->value,
        };
    }

    /**
     * Convert a SettingTypeEnum display type back to a storage type.
     *
     * This is used for reverse mapping when needed (e.g., exporting config).
     *
     * @param string $displayType SettingTypeEnum value (e.g., 'text', 'number')
     * @return string Config schema type (e.g., 'string', 'integer')
     */
    public function toStorageType(string $displayType): string
    {
        return match ($displayType) {
            SettingTypeEnum::TEXT->value => 'string',
            SettingTypeEnum::NUMBER->value => 'integer',
            SettingTypeEnum::BOOLEAN->value => 'boolean',
            SettingTypeEnum::TEXTAREA->value => 'json',
            // For special UI-only types, map to string as generic storage
            SettingTypeEnum::SECRET->value,
            SettingTypeEnum::COLOR->value,
            SettingTypeEnum::LOCALE->value,
            SettingTypeEnum::TWIG->value,
            SettingTypeEnum::URL->value,
            SettingTypeEnum::EMAIL->value,
            SettingTypeEnum::IMAGE->value,
            SettingTypeEnum::SELECT->value => 'string',
            // Safe fallback
            default => 'string',
        };
    }

    /**
     * Check if a type is a valid config_schema storage type.
     *
     * @param string $type Type to check
     * @return bool True if valid storage type
     */
    public function isValidStorageType(string $type): bool
    {
        return in_array($type, self::VALID_STORAGE_TYPES, true);
    }

    /**
     * Check if a type is a valid SettingTypeEnum display type.
     *
     * @param string $type Type to check
     * @return bool True if valid display type
     */
    public function isValidDisplayType(string $type): bool
    {
        return in_array($type, array_map(
            static fn(SettingTypeEnum $enum) => $enum->value,
            SettingTypeEnum::cases()
        ), true);
    }

    /**
     * Get all valid storage types for validation.
     *
     * @return array<string> Array of valid storage types
     */
    public function getValidStorageTypes(): array
    {
        return self::VALID_STORAGE_TYPES;
    }

    /**
     * Get the complete type mapping configuration.
     *
     * Useful for debugging and documentation purposes.
     *
     * @return array<string, string> Storage type => Display type mapping
     */
    public function getTypeMapping(): array
    {
        return [
            'string' => SettingTypeEnum::TEXT->value,
            'integer' => SettingTypeEnum::NUMBER->value,
            'float' => SettingTypeEnum::NUMBER->value,
            'boolean' => SettingTypeEnum::BOOLEAN->value,
            'json' => SettingTypeEnum::TEXTAREA->value,
            'array' => SettingTypeEnum::TEXTAREA->value,
        ];
    }
}
