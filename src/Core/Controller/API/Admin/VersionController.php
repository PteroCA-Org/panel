<?php

namespace App\Core\Controller\API\Admin;

use App\Core\Enum\PermissionEnum;
use App\Core\Service\System\SystemVersionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class VersionController extends AbstractAdminAPIController
{
    #[Route('/panel/api/check-version', name: 'check_version', methods: ['GET'])]
    public function checkVersion(
        SystemVersionService $systemVersionService,
    ): JsonResponse
    {
        $this->requirePermission(PermissionEnum::ACCESS_ADMIN_OVERVIEW);

        return new JsonResponse($systemVersionService->getVersionInformation());
    }
}
