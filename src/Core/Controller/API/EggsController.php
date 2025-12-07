<?php

namespace App\Core\Controller\API;

use App\Core\Enum\PermissionEnum;
use App\Core\Service\Server\ServerEggService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class EggsController extends APIAbstractController
{
    public function __construct(
        private readonly ServerEggService $serverEggService,
    ) {}

    #[Route('/panel/api/get-eggs/{nestId}', name: 'api_get_eggs', methods: ['GET'])]
    public function getEggs(int $nestId): JsonResponse
    {
        $this->requirePermission(PermissionEnum::EDIT_PRODUCT);

        return new JsonResponse($this->serverEggService->prepareEggsDataByNest($nestId));
    }
}
