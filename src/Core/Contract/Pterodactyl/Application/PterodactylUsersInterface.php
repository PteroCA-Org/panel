<?php

namespace App\Core\Contract\Pterodactyl\Application;

use App\Core\DTO\Pterodactyl\Application\PterodactylApiKey;
use App\Core\DTO\Pterodactyl\Application\PterodactylUser;
use App\Core\DTO\Pterodactyl\Collection;

interface PterodactylUsersInterface
{
    public function getAllUsers(array $parameters = []): Collection;

    public function getAllUsersPaginated(int $page = 1, array $parameters = []): Collection;

    public function getUser(int|string $userId, array $parameters = []): PterodactylUser;

    public function getUserByExternalId(string $externalId, array $parameters = []): PterodactylUser;

    public function updateUser(int|string $userId, array $details): PterodactylUser;

    public function createUser(array $details): PterodactylUser;

    public function deleteUser(int|string $userId): bool;

    public function createApiKeyForUser(int|string $userId, string $description): PterodactylApiKey;

    public function deleteApiKeyForUser(int|string $userId, string $identifier): bool;
}
