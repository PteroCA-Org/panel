<?php

namespace App\Core\Controller\API;

use App\Core\Enum\ServerPermissionEnum;
use App\Core\Repository\ServerRepository;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylExceptionHandler;
use App\Core\Service\Server\ServerBackupService;
use App\Core\Trait\HandlesPterodactylExceptions;
use App\Core\Trait\InternalServerApiTrait;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Annotation\Route;

class ServerBackupController extends APIAbstractController
{
    use InternalServerApiTrait;
    use HandlesPterodactylExceptions;

    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly ServerBackupService $serverBackupService,
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

    #[Route('/panel/api/server/{id}/backup/create', name: 'server_backup_create', methods: ['POST'])]
    public function createBackup(
        int $id,
        Request $request,
    ): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::BACKUP_CREATE);
        $response = new JsonResponse();

        try {
            $createdBackup = $this->serverBackupService->createBackup(
                $server,
                $this->getUser(),
                $request->request->all('Backup')['name'] ?? null,
                $request->request->all('Backup')['ignoredFiles'] ?? '',
            );
            $response->setData($createdBackup);
        } catch (TooManyRequestsHttpException) {
            $response->setStatusCode(429);
        } catch (Exception $exception) {
            return $this->handlePterodactylException($exception, 'create backup', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'backup_name' => $request->request->all('Backup')['name'] ?? 'unknown',
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/backup/{backupId}/download', name: 'server_backup_download', methods: ['GET'])]
    public function downloadBackup(
        int $id,
        string $backupId,
    ): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::BACKUP_DOWNLOAD);
        $response = new JsonResponse();

        try {
            $downloadUrl = $this->serverBackupService->getBackupDownloadUrl(
                $server,
                $this->getUser(),
                $backupId,
            );
            $response->setData(['url' => $downloadUrl]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'download backup', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'backup_id' => $backupId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/backup/{backupId}/delete', name: 'server_backup_delete', methods: ['DELETE'])]
    public function backupLockToggle(
        int $id,
        string $backupId,
    ): Response
    {
        $server = $this->getServer($id, ServerPermissionEnum::BACKUP_DELETE);
        $response = new Response();

        try {
            $this->serverBackupService->deleteBackup(
                $server,
                $this->getUser(),
                $backupId,
            );
            $response->setStatusCode(204);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'delete backup', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'backup_id' => $backupId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/backup/{backupId}/restore', name: 'server_backup_restore', methods: ['POST'])]
    public function restoreBackup(
        int $id,
        string $backupId,
        Request $request,
    ): Response
    {
        $server = $this->getServer($id, ServerPermissionEnum::BACKUP_RESTORE);
        $response = new Response();

        try {
            $truncate = $request->request->getBoolean('truncate');
            
            $this->serverBackupService->restoreBackup(
                $server,
                $this->getUser(),
                $backupId,
                $truncate,
            );
            $response->setStatusCode(204);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'restore backup', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'backup_id' => $backupId,
            ]);
        }

        return $response;
    }
}
