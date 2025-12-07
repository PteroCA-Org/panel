<?php

namespace App\Core\Enum;

enum SystemRoleEnum: string
{
    case ROLE_USER = 'ROLE_USER';
    case ROLE_ADMIN = 'ROLE_ADMIN';

    /**
     * Get all role values as array.
     * Useful for validation and form choices.
     *
     * @return string[]
     */
    public static function getValues(): array
    {
        return array_map(fn(self $role) => $role->value, self::cases());
    }

    /**
     * Get choices for EasyAdmin/Symfony forms.
     * Returns array with labels as keys and enum values.
     *
     * @return array<string, self>
     */
    public static function getChoices(): array
    {
        return [
            'pteroca.role.admin' => self::ROLE_ADMIN,
            'pteroca.role.user' => self::ROLE_USER,
        ];
    }

    /**
     * Safe conversion from string to enum.
     *
     * @param string $role Role string
     * @return self|null
     */
    public static function fromString(string $role): ?self
    {
        return self::tryFrom($role);
    }

    /**
     * Check if role string is valid system role.
     *
     * @param string $role Role string to check
     * @return bool
     */
    public static function isValidRole(string $role): bool
    {
        return self::tryFrom($role) !== null;
    }
}
