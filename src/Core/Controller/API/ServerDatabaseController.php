<?php

namespace App\Core\Controller\API;

use App\Core\Enum\ServerPermissionEnum;
use App\Core\Repository\ServerRepository;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylExceptionHandler;
use App\Core\Service\Server\ServerDatabaseService;
use App\Core\Trait\HandlesPterodactylExceptions;
use App\Core\Trait\InternalServerApiTrait;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ServerDatabaseController extends APIAbstractController
{
    use InternalServerApiTrait;
    use HandlesPterodactylExceptions;

    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly ServerDatabaseService $serverDatabaseService,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly LoggerInterface $logger,
        private readonly PterodactylExceptionHandler $pterodactylExceptionHandler,
    ) {}

    protected function getPterodactylExceptionHandler(): PterodactylExceptionHandler
    {
        return $this->pterodactylExceptionHandler;
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    #[Route('/panel/api/server/{id}/database/all', name: 'server_database_get_all', methods: ['GET'])]
    public function getAllDatabases(
        int $id,
    ): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::DATABASE_READ);
        $response = new JsonResponse();

        try {
            $pterodactylDatabases = $this->serverDatabaseService->getAllDatabases(
                $server,
                $this->getUser(),
            );
            $response->setData($pterodactylDatabases);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'get all Pterodactyl databases', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/database/create', name: 'server_database_create', methods: ['POST'])]
    public function createDatabase(
        int $id,
        Request $request,
    ): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::DATABASE_CREATE);
        $response = new JsonResponse();
        $payload = $request->request->all('Database');

        try {
            $this->serverDatabaseService->createDatabase(
                $server,
                $this->getUser(),
                $payload['name'],
                $payload['connections_from'],
            );
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'create Pterodactyl database', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'database_name' => $payload['name'] ?? 'unknown',
                'connections_from' => $payload['connections_from'] ?? 'unknown',
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/database/{databaseId}/delete', name: 'server_database_delete', methods: ['DELETE'])]
    public function deleteDatabase(
        int $id,
        int $databaseId,
    ): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::DATABASE_DELETE);
        $response = new JsonResponse();

        try {
            $this->serverDatabaseService->deleteDatabase(
                $server,
                $this->getUser(),
                $databaseId,
            );
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'delete Pterodactyl database', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'database_id' => $databaseId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/database/{databaseId}/rotate-password', name: 'server_database_rotate_password', methods: ['POST'])]
    public function rotatePassword(
        int $id,
        string $databaseId,
    ): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::DATABASE_UPDATE);
        $response = new JsonResponse();

        try {
            $changedDatabaseData = $this->serverDatabaseService->rotatePassword(
                $server,
                $this->getUser(),
                $databaseId,
            );
            $response->setData($changedDatabaseData);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'rotate Pterodactyl database password', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'database_id' => $databaseId,
            ]);
        }

        return $response;
    }
}
