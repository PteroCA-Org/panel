<?php

namespace App\Core\Controller\API;

use App\Core\Enum\PermissionEnum;
use App\Core\Trait\GetUserTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

abstract class APIAbstractController extends AbstractController
{
    use GetUserTrait;

    protected function requirePermission(string|PermissionEnum $permission): void
    {
        $permissionCode = $permission instanceof PermissionEnum ? $permission->value : $permission;

        if (!$this->isGranted($permissionCode)) {
            throw $this->createAccessDeniedException();
        }
    }
}
