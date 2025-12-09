<?php

namespace App\Core\Entity;

abstract class AbstractEntity
{
    /**
     * Registry of virtual fields for plugins/extensions
     * Stored per-class to support inheritance
     * @var array<string, array<string>>
     */
    private static array $registeredVirtualFields = [];

    /**
     * Register a virtual field name (for EasyAdmin compatibility)
     * Allows plugins to add virtual fields without modifying entity files
     *
     * @param string $fieldName Virtual field name
     */
    public static function registerVirtualField(string $fieldName): void
    {
        $class = static::class;

        if (!isset(self::$registeredVirtualFields[$class])) {
            self::$registeredVirtualFields[$class] = [];
        }

        if (!in_array($fieldName, self::$registeredVirtualFields[$class], true)) {
            self::$registeredVirtualFields[$class][] = $fieldName;
        }
    }

    /**
     * Check if a method is a registered virtual field getter
     *
     * @param string $method Method name
     * @return bool
     */
    private function isVirtualFieldGetter(string $method): bool
    {
        if (!str_starts_with($method, 'get')) {
            return false;
        }

        $fieldName = lcfirst(substr($method, 3));
        $class = static::class;

        return in_array($fieldName, self::$registeredVirtualFields[$class] ?? [], true);
    }

    /**
     * Magic method for property existence check
     * Makes EasyAdmin think virtual fields exist
     *
     * @param string $name Property name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->isVirtualFieldGetter('get' . ucfirst($name));
    }

    /**
     * Magic method for property access
     * Returns null for virtual fields (actual value from formatValue())
     *
     * @param string $name Property name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        if ($this->isVirtualFieldGetter('get' . ucfirst($name))) {
            return null; // Dummy value - actual value comes from formatValue()
        }

        throw new \InvalidArgumentException(
            sprintf('Property %s::$%s does not exist', static::class, $name)
        );
    }
}
