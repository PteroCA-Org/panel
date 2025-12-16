<?php

namespace App\Core\Service\Server;

use App\Core\DTO\Action\Result\UpdateServerActionResult;
use App\Core\DTO\Pterodactyl\Application\PterodactylServer;
use App\Core\Entity\Server;
use App\Core\Entity\ServerProduct;
use App\Core\Enum\CrudFlashMessageTypeEnum;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use Exception;
use InvalidArgumentException;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class UpdateServerService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private ServerBuildService            $serverBuildService,
        private TranslatorInterface           $translator,
    )
    {
    }

    public function updateServer(Server|ServerProduct $entityInstance): UpdateServerActionResult
    {
        $pterodactylServerId = $entityInstance instanceof Server
            ? $entityInstance->getPterodactylServerId()
            : $entityInstance->getServer()->getPterodactylServerId();
        $pterodactylServer = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->servers()
            ->getServer($pterodactylServerId);
        $updateServerActionResult = new UpdateServerActionResult();

        return match (true) {
            $entityInstance instanceof Server => $this->updateByServerEntity(
                $updateServerActionResult,
                $entityInstance,
                $pterodactylServer
            ),
            $entityInstance instanceof ServerProduct => $this->updateByServerProductEntity(
                $updateServerActionResult,
                $entityInstance,
                $pterodactylServer
            ),
            default => throw new InvalidArgumentException('Invalid entity type'),
        };
    }

    private function updateByServerProductEntity(
        UpdateServerActionResult $updateServerActionResult,
        ServerProduct $entityInstance,
        PterodactylServer $pterodactylServer,
    ): UpdateServerActionResult
    {
        $updateServerActionResult = $this->updateServerBuild(
            $updateServerActionResult,
            $entityInstance,
            $pterodactylServer
        );

        $updateServerActionResult = $this->updateServerStartup(
            $updateServerActionResult,
            $entityInstance,
            $pterodactylServer
        );

        return $this->updateByServerEntity(
            $updateServerActionResult,
            $entityInstance->getServer(),
            $pterodactylServer
        );
    }

    private function updateByServerEntity(
        UpdateServerActionResult $updateServerActionResult,
        Server $entityInstance,
        PterodactylServer $pterodactylServer,
    ): UpdateServerActionResult
    {
        $updateServerActionResult = $this->setServerSuspendedStatus(
            $updateServerActionResult,
            $entityInstance,
            $pterodactylServer
        );

        return $this->updateServerDetails(
            $updateServerActionResult,
            $entityInstance,
            $pterodactylServer
        );
    }

    private function updateServerBuild(
        UpdateServerActionResult $updateServerActionResult,
        ServerProduct $entityInstance,
        PterodactylServer $pterodactylServer,
    ): UpdateServerActionResult
    {
        try {
            $updatedServerBuild = $this->serverBuildService
                ->prepareUpdateServerBuild($entityInstance, $pterodactylServer);
            $this->pterodactylApplicationService
                ->getApplicationApi()
                ->servers()
                ->updateServerBuild(
                    $entityInstance->getServer()->getPterodactylServerId(),
                    $updatedServerBuild
                );
            $updateServerActionResult->addMessage(
                $this->translator->trans('pteroca.crud.server.build_updated_successfully'),
                CrudFlashMessageTypeEnum::SUCCESS,
            );
        } catch (Exception $exception) {
            $errorMessage = sprintf(
                '%s %s',
                $this->translator->trans('pteroca.crud.server.build_update_error'),
                $exception->getMessage()
            );
            $updateServerActionResult->addMessage($errorMessage, CrudFlashMessageTypeEnum::DANGER);
        } finally {
            return $updateServerActionResult;
        }
    }

    private function updateServerStartup(
        UpdateServerActionResult $updateServerActionResult,
        ServerProduct $entityInstance,
        PterodactylServer $pterodactylServer,
    ): UpdateServerActionResult
    {
        try {
            $updatedServerStartup = $this->serverBuildService
                ->prepareUpdateServerStartup($entityInstance, $pterodactylServer);

            $this->pterodactylApplicationService
                ->getApplicationApi()
                ->servers()
                ->updateServerStartup(
                    $entityInstance->getServer()->getPterodactylServerId(),
                    $updatedServerStartup
                );

            $updateServerActionResult->addMessage(
                $this->translator->trans('pteroca.crud.server.startup_updated_successfully'),
                CrudFlashMessageTypeEnum::SUCCESS,
            );
        } catch (Exception $exception) {
            $errorMessage = sprintf(
                '%s %s',
                $this->translator->trans('pteroca.crud.server.startup_update_error'),
                $exception->getMessage(),
            );
            $updateServerActionResult->addMessage($errorMessage, CrudFlashMessageTypeEnum::DANGER);
        } finally {
            return $updateServerActionResult;
        }
    }

    private function setServerSuspendedStatus(
        UpdateServerActionResult $updateServerActionResult,
        Server $entityInstance,
        PterodactylServer $pterodactylServer,
    ): UpdateServerActionResult
    {
        if ($entityInstance->getIsSuspended() !== $pterodactylServer->get('suspended')) {
            if ($entityInstance->getIsSuspended()) {
                try {
                    $this->pterodactylApplicationService
                        ->getApplicationApi()
                        ->servers()
                        ->suspendServer($entityInstance->getPterodactylServerId());
                    $updateServerActionResult->addMessage(
                        $this->translator->trans('pteroca.crud.server.suspended_successfully'),
                        CrudFlashMessageTypeEnum::SUCCESS,
                    );
                } catch (Exception $exception) {
                    $errorMessage = sprintf(
                        '%s %s',
                        $this->translator->trans('pteroca.crud.server.suspended_error'),
                        $exception->getMessage(),
                    );
                    $updateServerActionResult->addMessage($errorMessage, CrudFlashMessageTypeEnum::DANGER);
                }
            } else {
                try {
                    $this->pterodactylApplicationService
                        ->getApplicationApi()
                        ->servers()
                        ->unsuspendServer($entityInstance->getPterodactylServerId());
                    $updateServerActionResult->addMessage(
                        $this->translator->trans('pteroca.crud.server.unsuspended_successfully'),
                        CrudFlashMessageTypeEnum::SUCCESS,
                    );
                } catch (Exception $exception) {
                    $errorMessage = sprintf(
                        '%s %s',
                        $this->translator->trans('pteroca.crud.server.unsuspended_error'),
                        $exception->getMessage(),
                    );
                    $updateServerActionResult->addMessage($errorMessage, CrudFlashMessageTypeEnum::DANGER);
                }
            }
        }

        return $updateServerActionResult;
    }
    private function updateServerDetails(
        UpdateServerActionResult $updateServerActionResult,
        Server $entityInstance,
        PterodactylServer $pterodactylServer,
    ): UpdateServerActionResult
    {
        $userChanged = $entityInstance->getUser()->getPterodactylUserId() !== $pterodactylServer->get('user');
        $nameChanged = $entityInstance->getName() !== null && $entityInstance->getName() !== $pterodactylServer->get('name');

        if ($userChanged || $nameChanged) {
            try {
                $this->pterodactylApplicationService
                    ->getApplicationApi()
                    ->servers()
                    ->updateServerDetails(
                        $entityInstance->getPterodactylServerId(),
                        [
                            'name' => $entityInstance->getName() ?: $pterodactylServer->get('name') ?: $entityInstance->getServerProduct()?->getName() ?: 'Default name',
                            'description' => $pterodactylServer->get('description'),
                            'user' => $entityInstance->getUser()->getPterodactylUserId(),
                        ],
                    );
                $updateServerActionResult->addMessage(
                    $this->translator->trans('pteroca.crud.server.details_updated_successfully'),
                    CrudFlashMessageTypeEnum::SUCCESS,
                );
            } catch (Exception $exception) {
                $errorMessage = sprintf(
                    '%s %s',
                    $this->translator->trans('pteroca.crud.server.details_update_error'),
                    $exception->getMessage(),
                );
                $updateServerActionResult->addMessage($errorMessage, CrudFlashMessageTypeEnum::DANGER);
            }
        }

        return $updateServerActionResult;
    }
}
