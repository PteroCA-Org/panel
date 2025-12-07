<?php

namespace App\Core\Trait;

use App\Core\Contract\UserInterface;
use App\Core\DTO\Collection\ServerPermissionCollection;
use App\Core\DTO\Pterodactyl\Application\PterodactylServer;
use App\Core\Entity\Server;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\ServerPermissionEnum;

trait ServerPermissionsTrait
{
    private function getServerPermissions(
        PterodactylServer $pterodactylServer,
        Server $server,
        UserInterface $user
    ): ServerPermissionCollection {
        $isAdmin = $user->hasPermission(PermissionEnum::ACCESS_SERVERS);
        $isServerOwner = $server->getUser()->getId() === $user->getId();

        if (!$isAdmin && !$isServerOwner) {
            $subUser = current(array_filter(
                $pterodactylServer->get('relationships')['subusers']->toArray(),
                fn($subuser) => $subuser['user_id'] === $user->getPterodactylUserId(),
            ));

            return ServerPermissionEnum::fromArray($subUser['permissions'] ?? []);
        }

        $allPermissions = [];
        foreach (ServerPermissionEnum::cases() as $permission) {
            $allPermissions[] = $permission->value;
        }

        return ServerPermissionEnum::fromArray($allPermissions);
    }
}
