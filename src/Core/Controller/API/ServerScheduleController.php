<?php

namespace App\Core\Controller\API;

use App\Core\Enum\ServerPermissionEnum;
use App\Core\Repository\ServerRepository;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylExceptionHandler;
use App\Core\Service\Server\ServerScheduleService;
use App\Core\Trait\HandlesPterodactylExceptions;
use App\Core\Trait\InternalServerApiTrait;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ServerScheduleController extends APIAbstractController
{
    use InternalServerApiTrait;
    use HandlesPterodactylExceptions;

    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly ServerScheduleService $serverScheduleService,
        private readonly PterodactylApplicationService $pterodactylApplicationService,
        private readonly TranslatorInterface $translator,
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

    #[Route('/panel/api/server/{id}/schedules/create', name: 'server_schedules_create', methods: ['POST'])]
    public function createSchedule(int $id, Request $request): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_CREATE);
        $response = new JsonResponse();
        
        $data = json_decode($request->getContent(), true);
        
        if (empty($data['name'])) {
            $response->setStatusCode(400);
            $response->setData(['error' => $this->translator->trans('pteroca.api.schedule.name_required')]);
            return $response;
        }

        $cronFields = ['minute', 'hour', 'day_of_month', 'month', 'day_of_week'];
        foreach ($cronFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $response->setStatusCode(400);
                $response->setData(['error' => $this->translator->trans('pteroca.api.schedule.cron_field_required', ['field' => $field])]);
                return $response;
            }
        }

        try {
            $cronExpression = [
                'minute' => $data['minute'],
                'hour' => $data['hour'],
                'day_of_month' => $data['day_of_month'],
                'month' => $data['month'],
                'day_of_week' => $data['day_of_week'],
            ];

            $result = $this->serverScheduleService->createSchedule(
                $server,
                $this->getUser(),
                $data['name'],
                $cronExpression,
                $data['is_active'] ?? true,
                $data['only_when_online'] ?? true
            );

            $response->setData(['success' => true, 'schedule' => $result]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'create schedule', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_name' => $data['name'] ?? 'unknown',
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/schedules/{scheduleId}', name: 'server_schedules_update', methods: ['PUT'])]
    public function updateSchedule(int $id, int $scheduleId, Request $request): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_UPDATE);
        $response = new JsonResponse();
        
        $data = json_decode($request->getContent(), true);

        try {
            $cronExpression = null;
            if (isset($data['minute']) || isset($data['hour']) || isset($data['day_of_month']) || 
                isset($data['month']) || isset($data['day_of_week'])) {
                $cronExpression = [
                    'minute' => $data['minute'] ?? '*',
                    'hour' => $data['hour'] ?? '*',
                    'day_of_month' => $data['day_of_month'] ?? '*',
                    'month' => $data['month'] ?? '*',
                    'day_of_week' => $data['day_of_week'] ?? '*',
                ];
            }

            $result = $this->serverScheduleService->updateSchedule(
                $server,
                $this->getUser(),
                $scheduleId,
                $data['name'] ?? null,
                $cronExpression,
                $data['is_active'] ?? null,
                $data['only_when_online'] ?? null
            );

            $response->setData(['success' => true, 'schedule' => $result]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'update schedule', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_id' => $scheduleId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/schedules/{scheduleId}/delete', name: 'server_schedules_delete', methods: ['DELETE'])]
    public function deleteSchedule(int $id, int $scheduleId): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_DELETE);
        $response = new JsonResponse();

        try {
            $this->serverScheduleService->deleteSchedule($server, $this->getUser(), $scheduleId);
            $response->setData(['success' => true]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'delete schedule', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_id' => $scheduleId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/schedules/{scheduleId}', name: 'server_schedules_get', methods: ['GET'])]
    public function getSchedule(int $id, int $scheduleId): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_READ);
        $response = new JsonResponse();

        try {
            $schedule = $this->serverScheduleService->getSchedule($server, $this->getUser(), $scheduleId);
            $response->setData($schedule);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'get schedule', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_id' => $scheduleId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/schedules/{scheduleId}/tasks/{taskId}', name: 'server_schedule_tasks_update', methods: ['PUT'])]
    public function updateScheduleTask(int $id, int $scheduleId, int $taskId, Request $request): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_UPDATE);
        $response = new JsonResponse();

        $data = json_decode($request->getContent(), true);

        $validationError = $this->validateScheduleTaskData($data);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $result = $this->serverScheduleService->updateScheduleTask(
                $server,
                $this->getUser(),
                $scheduleId,
                $taskId,
                $data['action'],
                $data['payload'],
                $data['time_offset'] ?? 0,
                $data['continue_on_failure'] ?? false
            );

            $response->setData(['success' => true, 'task' => $result]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'update schedule task', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_id' => $scheduleId,
                'task_id' => $taskId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/schedules/{scheduleId}/tasks', name: 'server_schedule_tasks_create', methods: ['POST'])]
    public function createScheduleTask(int $id, int $scheduleId, Request $request): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_UPDATE);
        $response = new JsonResponse();

        $data = json_decode($request->getContent(), true);

        $validationError = $this->validateScheduleTaskData($data);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $result = $this->serverScheduleService->createScheduleTask(
                $server,
                $this->getUser(),
                $scheduleId,
                $data['action'],
                $data['payload'],
                $data['time_offset'] ?? 0,
                $data['continue_on_failure'] ?? false
            );

            $response->setData(['success' => true, 'task' => $result]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'create schedule task', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_id' => $scheduleId,
            ]);
        }

        return $response;
    }

    #[Route('/panel/api/server/{id}/schedules/{scheduleId}/tasks/{taskId}', name: 'server_schedules_tasks_delete', methods: ['DELETE'])]
    public function deleteScheduleTask(int $id, int $scheduleId, int $taskId): JsonResponse
    {
        $server = $this->getServer($id, ServerPermissionEnum::SCHEDULE_UPDATE);
        $response = new JsonResponse();

        try {
            $this->serverScheduleService->deleteScheduleTask($server, $this->getUser(), $scheduleId, $taskId);
            $response->setData(['success' => true]);
        } catch (Exception $e) {
            return $this->handlePterodactylException($e, 'delete schedule task', [
                'server_id' => $id,
                'user_id' => $this->getUser()->getId(),
                'schedule_id' => $scheduleId,
                'task_id' => $taskId,
            ]);
        }

        return $response;
    }

    /**
     * Validate schedule task data (action and payload).
     *
     * @param array|null $data The data to validate
     * @return JsonResponse|null Returns JsonResponse with error if validation fails, null if validation passes
     */
    private function validateScheduleTaskData(?array $data): ?JsonResponse
    {
        if (empty($data['action'])) {
            $response = new JsonResponse();
            $response->setStatusCode(400);
            $response->setData(['error' => $this->translator->trans('pteroca.api.schedule.action_required')]);
            return $response;
        }

        if (empty($data['payload'])) {
            $response = new JsonResponse();
            $response->setStatusCode(400);
            $response->setData(['error' => $this->translator->trans('pteroca.api.schedule.payload_required')]);
            return $response;
        }

        return null;
    }
}
