<?php

namespace App\Core\Service\Server;

use App\Core\Contract\UserInterface;
use App\Core\DTO\Action\Result\ServerAllocationActionResult;
use App\Core\Entity\Server;
use App\Core\Enum\ServerLogActionEnum;
use App\Core\Service\Logs\ServerLogService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylExceptionHandler;
use Exception;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Event\Server\Network\ServerAllocationCreatedEvent;
use App\Core\Event\Server\Network\ServerAllocationCreationFailedEvent;
use App\Core\Event\Server\Network\ServerAllocationCreationRequestedEvent;
use App\Core\Event\Server\Network\ServerAllocationDeletedEvent;
use App\Core\Event\Server\Network\ServerAllocationDeletionFailedEvent;
use App\Core\Event\Server\Network\ServerAllocationDeletionRequestedEvent;
use App\Core\Event\Server\Network\ServerAllocationEditedEvent;
use App\Core\Event\Server\Network\ServerAllocationEditFailedEvent;
use App\Core\Event\Server\Network\ServerAllocationEditRequestedEvent;
use App\Core\Event\Server\Network\ServerAllocationPrimaryChangedEvent;
use App\Core\Event\Server\Network\ServerAllocationPrimaryChangeFailedEvent;
use App\Core\Event\Server\Network\ServerAllocationPrimaryChangeRequestedEvent;
use App\Core\Service\Event\EventContextService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ServerNetworkService
{
    private const AUTO_ALLOCATION_DISABLED_ERROR = 'Server auto-allocation is not enabled for this instance.';

    private const DELETE_PRIMARY_ALLOCATION_ERROR = 'You cannot delete the primary allocation for this server.';

    public function __construct(
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly ServerLogService $serverLogService,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
        private readonly EventContextService $eventContextService,
        private readonly PterodactylExceptionHandler $pterodactylExceptionHandler,
    ) {}

    public function createAllocation(
        Server $server,
        UserInterface $user,
    ): ServerAllocationActionResult
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerAllocationCreationRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Allocation creation was blocked';
            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $reason,
            );
        }

        try {
            $this->pterodactylApplicationService
                ->getClientApi($user)
                ->network()
                ->assignAllocation($server);
        } catch (Exception $exception) {
            $parsedError = $this->pterodactylExceptionHandler->parseException($exception);
            $errorDetail = $parsedError['detail'] ?? $parsedError['error'];

            if ($errorDetail === self::AUTO_ALLOCATION_DISABLED_ERROR) {
                $errorDetail = $this->translator->trans('pteroca.server.auto_allocation_disabled_for_this_instance');
            }
        }

        if (!empty($errorDetail)) {
            $failedEvent = new ServerAllocationCreationFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $errorDetail,
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $errorDetail,
            );
        }

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::CREATE_ALLOCATION,
        );

        $createdEvent = new ServerAllocationCreatedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $context
        );
        $this->eventDispatcher->dispatch($createdEvent);

        return new ServerAllocationActionResult(
            success: true,
            server: $server,
        );
    }

    public function makePrimaryAllocation(
        Server $server,
        UserInterface $user,
        int $allocationId,
    ): ServerAllocationActionResult
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerAllocationPrimaryChangeRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $allocationId,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Primary allocation change was blocked';
            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $reason,
            );
        }

        try {
            $this->pterodactylApplicationService
                ->getClientApi($user)
                ->network()
                ->setPrimaryAllocation($server, $allocationId);
        } catch (Exception $exception) {
            $parsedError = $this->pterodactylExceptionHandler->parseException($exception);
            $errorDetail = $parsedError['detail'] ?? $parsedError['error'];
        }

        if (!empty($errorDetail)) {
            $failedEvent = new ServerAllocationPrimaryChangeFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $allocationId,
                $errorDetail,
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $errorDetail,
            );
        }

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::MAKE_PRIMARY_ALLOCATION,
            [
                'allocationId' => $allocationId,
            ]
        );

        $changedEvent = new ServerAllocationPrimaryChangedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $allocationId,
            $context
        );
        $this->eventDispatcher->dispatch($changedEvent);

        return new ServerAllocationActionResult(
            success: true,
            server: $server,
        );
    }

    public function editAllocation(
        Server $server,
        UserInterface $user,
        int $allocationId,
        string $notes,
    ): ServerAllocationActionResult
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerAllocationEditRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $allocationId,
            $notes,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Allocation edit was blocked';
            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $reason,
            );
        }

        try {
            $this->pterodactylApplicationService
                ->getClientApi($user)
                ->network()
                ->updateAllocationNotes($server, $allocationId, $notes);
        } catch (Exception $exception) {
            $parsedError = $this->pterodactylExceptionHandler->parseException($exception);
            $errorDetail = $parsedError['detail'] ?? $parsedError['error'];
        }

        if (!empty($errorDetail)) {
            $failedEvent = new ServerAllocationEditFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $allocationId,
                $notes,
                $errorDetail,
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $errorDetail,
            );
        }

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::EDIT_ALLOCATION,
            [
                'allocationId' => $allocationId,
                'notes' => $notes,
            ]
        );

        $editedEvent = new ServerAllocationEditedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $allocationId,
            $notes,
            $context
        );
        $this->eventDispatcher->dispatch($editedEvent);

        return new ServerAllocationActionResult(
            success: true,
            server: $server,
        );
    }

    public function deleteAllocation(
        Server $server,
        UserInterface $user,
        int $allocationId,
    ): ServerAllocationActionResult
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request ? $this->eventContextService->buildMinimalContext($request) : [];

        $requestedEvent = new ServerAllocationDeletionRequestedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $allocationId,
            $context
        );
        $this->eventDispatcher->dispatch($requestedEvent);

        if ($requestedEvent->isPropagationStopped()) {
            $reason = $requestedEvent->getRejectionReason() ?? 'Allocation deletion was blocked';
            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $reason,
            );
        }

        try {
            $this->pterodactylApplicationService
                ->getClientApi($user)
                ->network()
                ->removeAllocation($server, $allocationId);
        } catch (Exception $exception) {
            $parsedError = $this->pterodactylExceptionHandler->parseException($exception);
            $errorDetail = $parsedError['detail'] ?? $parsedError['error'];

            if ($errorDetail === self::DELETE_PRIMARY_ALLOCATION_ERROR) {
                $errorDetail = $this->translator->trans('pteroca.server.cannot_delete_primary_allocation');
            }
        }

        if (!empty($errorDetail)) {
            $failedEvent = new ServerAllocationDeletionFailedEvent(
                $user->getId(),
                $server->getId(),
                $server->getPterodactylServerIdentifier(),
                $allocationId,
                $errorDetail,
                $context
            );
            $this->eventDispatcher->dispatch($failedEvent);

            return new ServerAllocationActionResult(
                success: false,
                server: $server,
                error: $errorDetail,
            );
        }

        $this->serverLogService->logServerAction(
            $user,
            $server,
            ServerLogActionEnum::DELETE_ALLOCATION,
            [
                'allocationId' => $allocationId,
            ]
        );

        $deletedEvent = new ServerAllocationDeletedEvent(
            $user->getId(),
            $server->getId(),
            $server->getPterodactylServerIdentifier(),
            $allocationId,
            $context
        );
        $this->eventDispatcher->dispatch($deletedEvent);

        return new ServerAllocationActionResult(
            success: true,
            server: $server,
        );
    }
}
