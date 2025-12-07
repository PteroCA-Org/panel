<?php

namespace App\Core\Service\Security;

use App\Core\Entity\Permission;
use App\Core\Entity\Role;
use App\Core\Entity\User;
use App\Core\Repository\RoleRepository;
use App\Core\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing roles.
 * Handles CRUD operations for roles, permission assignments, and user-role relationships.
 */
readonly class RoleManager
{
    public function __construct(
        private RoleRepository $roleRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create a new role.
     *
     * @param array<Permission> $permissions Initial permissions to assign
     * @throws RuntimeException if role name already exists
     */
    public function createRole(
        string $name,
        string $displayName,
        ?string $description = null,
        array $permissions = [],
        bool $isSystem = false
    ): Role {
        // Check if role already exists
        $existing = $this->roleRepository->findByName($name);
        if ($existing !== null) {
            throw new RuntimeException("Role with name '{$name}' already exists");
        }

        // Validate name format (lowercase, alphanumeric, underscores only)
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new RuntimeException("Role name must contain only lowercase letters, numbers, and underscores");
        }

        $role = new Role();
        $role->setName($name);
        $role->setDisplayName($displayName);
        $role->setDescription($description);
        $role->setIsSystem($isSystem);

        // Assign initial permissions
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $this->roleRepository->save($role);

        $this->logger->info("Role created", [
            'name' => $name,
            'displayName' => $displayName,
            'permissionCount' => count($permissions),
        ]);

        return $role;
    }

    /**
     * Update an existing role.
     * System roles cannot be updated (name and isSystem fields are protected).
     *
     * @throws RuntimeException if role is system role
     */
    public function updateRole(
        Role $role,
        string $displayName,
        ?string $description = null
    ): Role {
        if ($role->isSystem()) {
            throw new RuntimeException("Cannot update system role '{$role->getName()}'");
        }

        $role->setDisplayName($displayName);
        $role->setDescription($description);

        $this->roleRepository->save($role);

        $this->logger->info("Role updated", [
            'name' => $role->getName(),
            'displayName' => $displayName,
        ]);

        return $role;
    }

    /**
     * Delete a role.
     * System roles cannot be deleted.
     * Checks if any users are assigned to this role.
     *
     * @throws RuntimeException if role is system role or has assigned users
     */
    public function deleteRole(Role $role): void
    {
        if ($role->isSystem()) {
            throw new RuntimeException("Cannot delete system role '{$role->getName()}'");
        }

        // Check if role has assigned users
        if ($role->getUsers()->count() > 0) {
            throw new RuntimeException(
                "Cannot delete role '{$role->getName()}' because it has {$role->getUsers()->count()} assigned users"
            );
        }

        $name = $role->getName();
        $this->roleRepository->remove($role);

        $this->logger->info("Role deleted", [
            'name' => $name,
        ]);
    }

    /**
     * Assign permissions to a role (replaces existing permissions).
     *
     * @param array<Permission> $permissions
     * @throws RuntimeException if role is system role
     */
    public function assignPermissions(Role $role, array $permissions): Role
    {
        if ($role->isSystem()) {
            throw new RuntimeException("Cannot modify permissions of system role '{$role->getName()}'");
        }

        // Clear existing permissions
        foreach ($role->getPermissions() as $permission) {
            $role->removePermission($permission);
        }

        // Assign new permissions
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $this->roleRepository->save($role);

        $this->logger->info("Role permissions updated", [
            'role' => $role->getName(),
            'permissionCount' => count($permissions),
        ]);

        return $role;
    }

    /**
     * Add single permission to a role.
     *
     * @throws RuntimeException if role is system role
     */
    public function addPermission(Role $role, Permission $permission): Role
    {
        if ($role->isSystem()) {
            throw new RuntimeException("Cannot modify permissions of system role '{$role->getName()}'");
        }

        if (!$role->hasPermission($permission)) {
            $role->addPermission($permission);
            $this->roleRepository->save($role);

            $this->logger->info("Permission added to role", [
                'role' => $role->getName(),
                'permission' => $permission->getCode(),
            ]);
        }

        return $role;
    }

    /**
     * Remove single permission from a role.
     *
     * @throws RuntimeException if role is system role
     */
    public function removePermission(Role $role, Permission $permission): Role
    {
        if ($role->isSystem()) {
            throw new RuntimeException("Cannot modify permissions of system role '{$role->getName()}'");
        }

        if ($role->hasPermission($permission)) {
            $role->removePermission($permission);
            $this->roleRepository->save($role);

            $this->logger->info("Permission removed from role", [
                'role' => $role->getName(),
                'permission' => $permission->getCode(),
            ]);
        }

        return $role;
    }

    /**
     * Assign a role to a user.
     */
    public function assignRoleToUser(User $user, Role $role): User
    {
        if (!$user->hasUserRole($role)) {
            $user->addUserRole($role);
            $this->userRepository->save($user);

            $this->logger->info("Role assigned to user", [
                'userId' => $user->getId(),
                'userEmail' => $user->getEmail(),
                'role' => $role->getName(),
            ]);
        }

        return $user;
    }

    /**
     * Remove a role from a user.
     */
    public function removeRoleFromUser(User $user, Role $role): User
    {
        if ($user->hasUserRole($role)) {
            $user->removeUserRole($role);
            $this->userRepository->save($user);

            $this->logger->info("Role removed from user", [
                'userId' => $user->getId(),
                'userEmail' => $user->getEmail(),
                'role' => $role->getName(),
            ]);
        }

        return $user;
    }

    /**
     * Get all roles assigned to a user.
     *
     * @return array<Role>
     */
    public function getUserRoles(User $user): array
    {
        return $user->getUserRoles()->toArray();
    }

    /**
     * Get role by name.
     */
    public function getRoleByName(string $name): ?Role
    {
        return $this->roleRepository->findByName($name);
    }

    /**
     * Get all system roles.
     *
     * @return array<Role>
     */
    public function getSystemRoles(): array
    {
        return $this->roleRepository->findSystemRoles();
    }

    /**
     * Get all non-system (custom) roles.
     *
     * @return array<Role>
     */
    public function getCustomRoles(): array
    {
        return $this->roleRepository->findNonSystemRoles();
    }

    /**
     * Get all roles ordered by system status and display name.
     *
     * @return array<Role>
     */
    public function getAllRoles(): array
    {
        return $this->roleRepository->findAllOrdered();
    }

    /**
     * Check if role exists by name.
     */
    public function hasRole(string $name): bool
    {
        return $this->roleRepository->findByName($name) !== null;
    }

    /**
     * Assign multiple roles to a user (replaces existing roles).
     *
     * @param array<Role> $roles
     */
    public function assignRolesToUser(User $user, array $roles): User
    {
        // Clear existing roles
        foreach ($user->getUserRoles() as $role) {
            $user->removeUserRole($role);
        }

        // Assign new roles
        foreach ($roles as $role) {
            $user->addUserRole($role);
        }

        $this->userRepository->save($user);

        $this->logger->info("User roles replaced", [
            'userId' => $user->getId(),
            'userEmail' => $user->getEmail(),
            'roleCount' => count($roles),
        ]);

        return $user;
    }

    /**
     * Get count of users assigned to a role.
     */
    public function getUserCountForRole(Role $role): int
    {
        return $role->getUsers()->count();
    }
}
