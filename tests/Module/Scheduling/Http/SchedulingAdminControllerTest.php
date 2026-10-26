<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Http;

use App\Module\Scheduling\Application\SchedulingManager;
use App\Module\Scheduling\Application\TaskHandlerRegistry;
use App\Module\Scheduling\Domain\TaskType;
use App\Module\Scheduling\Http\SchedulingAdminController;
use App\Tests\Module\Scheduling\Support\InMemorySchedulingStore;
use App\Tests\Module\Scheduling\Support\RecordingSchedulingAdminGuard;
use App\Tests\Module\Scheduling\Support\RecordingTaskHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SchedulingAdminControllerTest extends TestCase
{
    public function testTaskMutationRejectsMissingCsrfToken(): void
    {
        $controller = $this->controller();
        $request = Request::create('/admin/scheduling/tasks', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"label":"Cleanup","type":"cache_cleanup","interval_seconds":3600,"timezone":"UTC"}');

        self::assertSame(403, $controller->createTask($request)->getStatusCode());
    }

    public function testUnauthorizedAdministratorCannotReadTaskList(): void
    {
        $guard = new RecordingSchedulingAdminGuard(false);
        $controller = $this->controller($guard);

        self::assertSame(403, $controller->listTasks()->getStatusCode());
    }

    public function testAdminApiRejectsArbitraryShellCommandFields(): void
    {
        $controller = $this->controller();
        $request = Request::create(
            '/admin/scheduling/tasks',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'safe-test-token'],
            content: '{"label":"Cleanup","type":"cache_cleanup","interval_seconds":3600,"timezone":"UTC","command":"run anything"}',
        );

        self::assertSame(400, $controller->createTask($request)->getStatusCode());
    }

    public function testValidTaskRequestCreatesDisabledSchedule(): void
    {
        $controller = $this->controller();
        $request = Request::create(
            '/admin/scheduling/tasks',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'safe-test-token'],
            content: '{"label":"Cleanup","type":"cache_cleanup","interval_seconds":3600,"timezone":"UTC"}',
        );
        $response = $controller->createTask($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('"status":"disabled"', (string) $response->getContent());
    }

    public function testExecutionLimitRejectsArrayQueryValuesAsBadRequest(): void
    {
        $controller = $this->controller();
        $taskId = str_repeat('a', 32);
        $request = Request::create('/admin/scheduling/tasks/'.$taskId.'/executions?limit[]=1');

        self::assertSame(400, $controller->executions($taskId, $request)->getStatusCode());
    }

    private function controller(?RecordingSchedulingAdminGuard $guard = null): SchedulingAdminController
    {
        return new SchedulingAdminController(new SchedulingManager(
            new InMemorySchedulingStore(),
            $guard ?? new RecordingSchedulingAdminGuard(),
            new TaskHandlerRegistry([new RecordingTaskHandler(TaskType::CacheCleanup)]),
        ));
    }
}
