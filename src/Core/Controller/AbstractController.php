<?php

namespace App\Core\Controller;

use App\Core\Enum\PermissionEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Trait\EventContextTrait;
use App\Core\Trait\GetUserTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as SymfonyAbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractController extends SymfonyAbstractController
{
    use GetUserTrait;
    use EventContextTrait;

    public function checkPermission(string|PermissionEnum $permission = 'access_dashboard'): void
    {
        $permissionCode = $permission instanceof PermissionEnum ? $permission->value : $permission;

        $user = $this->getUser();

        if (empty($user)) {
            $this->redirect($this->generateUrl('app_login'));
        }

        if (!$this->isGranted($permissionCode) || $user->isBlocked()) {
            throw $this->createAccessDeniedException('Access denied');
        }
    }

    protected function renderWithEvent(
        ViewNameEnum $viewName,
        string $template,
        array $viewData,
        Request $request
    ): Response
    {
        $viewEvent = $this->prepareViewDataEvent($viewName, $viewData, $request);

        return $this->render($template, $viewEvent->getViewData());
    }
}