<?php

namespace App\Core\Security;

use App\Core\Entity\User;
use App\Core\Repository\PermissionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionRepository $permissionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Determine if this voter supports the given attribute.
     *
     * Supports any attribute (permission code) that exists in the database.
     *
     * @param string $attribute Permission code to check
     * @param mixed $subject Subject being voted on (typically null for permissions)
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        // Check if permission exists in database
        $permission = $this->permissionRepository->findByCode($attribute);

        return $permission !== null;
    }

    /**
     * Vote on whether user has the given permission.
     *
     * @param string $attribute Permission code to check
     * @param mixed $subject Subject being voted on
     * @param TokenInterface $token Security token containing user
     * @return bool True if access granted, false if denied
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // User must be authenticated and be instance of our User entity
        if (!$user instanceof User) {
            $this->logger->debug("Permission denied: user not authenticated", [
                'permission' => $attribute,
            ]);
            return false;
        }

        // Check if user has permission through their roles
        $hasPermission = $user->hasPermission($attribute);

        $this->logger->debug("Permission check completed", [
            'permission' => $attribute,
            'user' => $user->getUserIdentifier(),
            'userId' => $user->getId(),
            'granted' => $hasPermission,
        ]);

        return $hasPermission;
    }
}
