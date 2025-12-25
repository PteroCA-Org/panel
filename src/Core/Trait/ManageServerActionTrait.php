<?php

namespace App\Core\Trait;

use App\Core\Controller\Panel\ServerCrudController;
use App\Core\Controller\Panel\ServerLogCrudController;
use App\Core\Controller\Panel\ServerProductCrudController;
use App\Core\Entity\Server;
use App\Core\Entity\ServerProduct;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;

trait ManageServerActionTrait
{
    private function getServerAction(string $action): Action
    {
        return Action::new(
            sprintf('serverEdit_%s', $action),
            $this->translator->trans(sprintf('pteroca.crud.server.server_%s', $action)),
        )->linkToUrl(
            fn (Server|ServerProduct $entity) => $this->generateUrl(
                'panel',
                [
                    'crudAction' => $action,
                    'crudControllerFqcn' => $entity instanceof Server
                        ? ServerProductCrudController::class
                        : ServerCrudController::class,
                    'entityId' => $entity instanceof Server
                        ? $entity->getId()
                        : $entity->getServer()->getId(),
                ]
            )
        );
    }

    private function getShowServerLogsAction(): Action
    {
        return Action::new(
            'showServerLogs',
            $this->translator->trans('pteroca.crud.server.show_server_logs'),
        )->setIcon('fa fa-file-text')
        ->linkToUrl(
            fn (Server|ServerProduct $entity) => $this->generateUrl(
                'panel',
                [
                    'crudAction' => Action::INDEX,
                    'crudControllerFqcn' => ServerLogCrudController::class,
                    'filters' => [
                        'server' => [
                            'comparison' => '=',
                            'value' => $entity instanceof Server
                                ? $entity->getId()
                                : $entity->getServer()->getId(),
                        ]
                    ]
                ]
            )
        );
    }

    private function getManageServerAction(): Action
    {
        $manageServerAction = Action::new(
            'manageServer',
            $this->translator->trans('pteroca.crud.server.show_server_dashboard'),
        )->setIcon('fa fa-tachometer')
        ->displayIf(function (Server|ServerProduct $entity) {
            if ($entity instanceof Server) {
                return empty($entity->getDeletedAt());
            }

            return empty($entity->getServer()->getDeletedAt());
        });

        if (!$this->pterodactylRedirectService->shouldUsePterodactylPanel()) {
            $manageServerAction->linkToRoute(
                'server',
                fn (Server|ServerProduct $entity) => ['id' => $this->getEntityPterodactylServerIdentifier($entity)],
            );
        } else {
            $routeInfo = $this->pterodactylRedirectService->getServerRouteInfo('');

            if ($routeInfo['route']) {
                $manageServerAction->linkToRoute(
                    $routeInfo['route'],
                    fn (Server|ServerProduct $entity) => [
                        'redirect_path' => sprintf('/server/%s', $this->getEntityPterodactylServerIdentifier($entity)),
                    ]
                );
            } else {
                $manageServerAction->linkToUrl($routeInfo['url']);
            }

            if ($this->pterodactylRedirectService->shouldOpenInNewTab()) {
                $manageServerAction->setHtmlAttributes(['target' => '_blank']);
            }
        }

        return $manageServerAction;
    }

    private function getEntityPterodactylServerIdentifier(Server|ServerProduct $entity): string
    {
        if ($entity instanceof Server) {
            return $entity->getPterodactylServerIdentifier();
        }

        return $entity->getServer()->getPterodactylServerIdentifier();
    }
}
