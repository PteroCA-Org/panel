<?php

namespace App\Core\DTO;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Server;
use App\Core\Enum\ServerPermissionEnum;

readonly class ServerTabContext
{
    public function __construct(
        public Server        $server,
        public object        $serverData, // ServerData DTO
        public UserInterface $user,
        public bool          $isAdminView,
        public bool          $isOwner,
    ) {}

    public function hasPermission(string|ServerPermissionEnum $permission): bool
    {
        if ($permission instanceof ServerPermissionEnum) {
            $permission = $permission->value;
        }

        if ($this->isAdminView) {
            return true; // Admins have all permissions
        }

        if (empty($this->serverData->serverPermissions)) {
            return false;
        }

        $permissions = $this->serverData->serverPermissions->toArray();
        return in_array($permission, $permissions, true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    public function getProductFeature(string $feature): ?int
    {
        $product = $this->server->getServerProduct();

        if (!$product) {
            return null;
        }

        return match($feature) {
            'dbCount' => $product->getDbCount(),
            'backups' => $product->getBackups(),
            'schedules' => $product->getSchedules(),
            default => null,
        };
    }

    public function hasConfigurableStartup(): bool
    {
        return !empty($this->serverData->hasConfigurableOptions)
            || !empty($this->serverData->hasConfigurableVariables);
    }
}
