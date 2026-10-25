<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Http;

use App\Module\Scheduling\Application\SchedulingAccessDenied;
use App\Module\Scheduling\Application\SchedulingManager;
use App\Module\Scheduling\Application\SchedulingNotFound;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\TaskExecutionRecord;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/scheduling')]
final readonly class SchedulingAdminController
{
    public function __construct(private SchedulingManager $manager)
    {
    }

    #[Route('/tasks', name: 'cms_scheduling_tasks_list', methods: ['GET'])]
    public function listTasks(): JsonResponse
    {
        try {
            return new JsonResponse(array_map(self::taskData(...), $this->manager->listTasks()));
        } catch (SchedulingAccessDenied) {
            return self::error('forbidden', 403);
        }
    }

    #[Route('/tasks', name: 'cms_scheduling_tasks_create', methods: ['POST'])]
    public function createTask(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrf): array {
            self::assertKeys($data, ['label', 'type', 'interval_seconds', 'timezone']);
            $interval = $data['interval_seconds'] ?? null;
            if (!is_int($interval)) {
                throw new \InvalidArgumentException('Task interval must be an integer number of seconds.');
            }

            return self::taskData($this->manager->createTask(
                self::stringField($data, 'label'),
                self::stringField($data, 'type'),
                $interval,
                self::stringField($data, 'timezone'),
                $csrf,
            ));
        }, 201);
    }

    #[Route('/tasks/{id}', name: 'cms_scheduling_tasks_update', methods: ['PATCH'])]
    public function updateTask(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrf) use ($id): array {
            self::assertKeys($data, ['label', 'type', 'interval_seconds', 'timezone']);
            $interval = $data['interval_seconds'] ?? null;
            if (!is_int($interval)) {
                throw new \InvalidArgumentException('Task interval must be an integer number of seconds.');
            }

            return self::taskData($this->manager->updateTask(
                $id,
                self::stringField($data, 'label'),
                self::stringField($data, 'type'),
                $interval,
                self::stringField($data, 'timezone'),
                $csrf,
            ));
        });
    }

    #[Route('/tasks/{id}/activate', name: 'cms_scheduling_tasks_activate', methods: ['POST'])]
    public function activateTask(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrf): array => self::taskData($this->manager->activateTask($id, $csrf)), allowEmptyBody: true);
    }

    #[Route('/tasks/{id}/pause', name: 'cms_scheduling_tasks_pause', methods: ['POST'])]
    public function pauseTask(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrf): array => self::taskData($this->manager->pauseTask($id, $csrf)), allowEmptyBody: true);
    }

    #[Route('/tasks/{id}/disable', name: 'cms_scheduling_tasks_disable', methods: ['POST'])]
    public function disableTask(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrf): array => self::taskData($this->manager->disableTask($id, $csrf)), allowEmptyBody: true);
    }

    #[Route('/tasks/{id}', name: 'cms_scheduling_tasks_delete', methods: ['DELETE'])]
    public function deleteTask(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrf) use ($id): array {
            $this->manager->deleteTask($id, $csrf);

            return ['deleted' => true];
        }, allowEmptyBody: true);
    }

    #[Route('/tasks/{id}/executions', name: 'cms_scheduling_task_executions', methods: ['GET'])]
    public function executions(string $id, Request $request): JsonResponse
    {
        try {
            $rawLimit = $request->query->get('limit', '100');
            $limit = is_string($rawLimit) && 1 === preg_match('/\A[0-9]{1,3}\z/D', $rawLimit) ? (int) $rawLimit : null;
            if (null === $limit) {
                return self::error('invalid_request', 400);
            }

            return new JsonResponse(array_map(self::executionData(...), $this->manager->executions($id, $limit)));
        } catch (SchedulingAccessDenied) {
            return self::error('forbidden', 403);
        } catch (SchedulingNotFound) {
            return self::error('not_found', 404);
        } catch (\InvalidArgumentException) {
            return self::error('invalid_request', 400);
        }
    }

    /** @param callable(array<string, mixed>, string): array<string, mixed> $operation */
    private function mutate(Request $request, callable $operation, int $successStatus = 200, bool $allowEmptyBody = false): JsonResponse
    {
        try {
            $data = $allowEmptyBody ? [] : self::jsonObject($request);
            $csrf = $request->headers->get('X-CSRF-Token') ?? '';

            return new JsonResponse($operation($data, $csrf), $successStatus);
        } catch (SchedulingAccessDenied) {
            return self::error('forbidden', 403);
        } catch (SchedulingNotFound) {
            return self::error('not_found', 404);
        } catch (\JsonException|\InvalidArgumentException) {
            return self::error('invalid_request', 400);
        } catch (\DomainException) {
            return self::error('state_conflict', 409);
        }
    }

    /** @return array<string, mixed> */
    private static function jsonObject(Request $request): array
    {
        $content = $request->getContent();
        if (strlen($content) > 32000) {
            throw new \InvalidArgumentException('Request body exceeds the module limit.');
        }

        $data = json_decode($content, true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private static function assertKeys(array $data, array $allowed): void
    {
        foreach ($data as $key => $_value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Request contains unsupported fields.');
            }
        }
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException('Request is missing a required field.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Required field is invalid.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function taskData(ScheduledTask $task): array
    {
        return [
            'id' => $task->id,
            'label' => $task->label,
            'type' => $task->type->value,
            'interval_seconds' => $task->frequency->intervalSeconds,
            'timezone' => $task->frequency->timezone,
            'status' => $task->status->value,
            'next_run_at' => $task->frequency->localize($task->nextRunAt)?->format(DATE_ATOM),
            'created_at' => $task->createdAt->format(DATE_ATOM),
            'updated_at' => $task->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function executionData(TaskExecutionRecord $record): array
    {
        return [
            'scheduled_for' => $record->scheduledFor->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'status' => $record->state->value,
            'attempt' => $record->attempt,
            'result' => $record->resultCode?->value,
            'started_at' => $record->startedAt->format(DATE_ATOM),
            'finished_at' => $record->finishedAt?->format(DATE_ATOM),
        ];
    }

    private static function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status);
    }
}
