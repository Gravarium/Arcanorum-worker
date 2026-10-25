<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Application;

use App\Module\Scheduling\Application\SchedulingAccessDenied;
use App\Module\Scheduling\Application\SchedulingManager;
use App\Module\Scheduling\Application\TaskHandlerRegistry;
use App\Module\Scheduling\Domain\TaskStatus;
use App\Module\Scheduling\Domain\TaskType;
use App\Tests\Module\Scheduling\Support\InMemorySchedulingStore;
use App\Tests\Module\Scheduling\Support\RecordingSchedulingAdminGuard;
use App\Tests\Module\Scheduling\Support\RecordingTaskHandler;
use PHPUnit\Framework\TestCase;

final class SchedulingManagerTest extends TestCase
{
    public function testPermissionAndCsrfAreRequiredForTaskMutations(): void
    {
        [$manager, $store, $guard] = $this->newManager();
        $guard->mayManage = false;
        try {
            $manager->createTask('Cleanup', TaskType::CacheCleanup->value, 3600, 'UTC', 'safe-test-token');
            self::fail('An unauthorized administrator must be denied.');
        } catch (SchedulingAccessDenied) {
            self::assertCount(0, $store->listTasks());
        }

        $guard->mayManage = true;
        try {
            $manager->createTask('Cleanup', TaskType::CacheCleanup->value, 3600, 'UTC', 'wrong-token');
            self::fail('A request without a valid CSRF token must be denied.');
        } catch (SchedulingAccessDenied) {
            self::assertCount(0, $store->listTasks());
        }

        self::assertSame(TaskStatus::Disabled, $manager->createTask('Cleanup', TaskType::CacheCleanup->value, 3600, 'UTC', 'safe-test-token')->status);
    }

    public function testTaskLifecycleRequiresExplicitActivationAndDisabledStateBeforeDelete(): void
    {
        [$manager, $store] = $this->newManager();
        $task = $manager->createTask('Cleanup', TaskType::CacheCleanup->value, 3600, 'Europe/Berlin', 'safe-test-token');
        self::assertSame(TaskStatus::Disabled, $task->status);
        self::assertNull($task->nextRunAt);

        $active = $manager->activateTask($task->id, 'safe-test-token');
        self::assertSame(TaskStatus::Active, $active->status);
        self::assertNotNull($active->nextRunAt);

        try {
            $manager->updateTask($active->id, 'Changed', TaskType::CacheCleanup->value, 7200, 'UTC', 'safe-test-token');
            self::fail('Active tasks must not be edited.');
        } catch (\DomainException) {
            self::assertSame(TaskStatus::Active, $store->findTask($active->id)?->status);
        }

        $paused = $manager->pauseTask($active->id, 'safe-test-token');
        self::assertSame(TaskStatus::Paused, $paused->status);
        $updated = $manager->updateTask($paused->id, 'Changed', TaskType::CacheCleanup->value, 7200, 'UTC', 'safe-test-token');
        self::assertSame(7200, $updated->frequency->intervalSeconds);
        self::assertSame(TaskStatus::Paused, $updated->status);

        $activeAgain = $manager->activateTask($updated->id, 'safe-test-token');
        self::assertSame(TaskStatus::Active, $activeAgain->status);
        $disabled = $manager->disableTask($activeAgain->id, 'safe-test-token');
        self::assertSame(TaskStatus::Disabled, $disabled->status);
        $manager->deleteTask($disabled->id, 'safe-test-token');
        self::assertNull($store->findTask($disabled->id));
    }

    public function testTaskTypeAndTimezoneMustBeAllowlistedAndValidated(): void
    {
        [$manager] = $this->newManager();
        try {
            $manager->createTask('Unsafe', 'shell_command', 3600, 'UTC', 'safe-test-token');
            self::fail('Unknown task types must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        $manager->createTask('Bad timezone', TaskType::CacheCleanup->value, 3600, 'EST', 'safe-test-token');
    }

    public function testRegistryRejectsDuplicateHandlersForOneAllowlistedType(): void
    {
        $handler = new RecordingTaskHandler(TaskType::CacheCleanup);

        $this->expectException(\InvalidArgumentException::class);
        new TaskHandlerRegistry([$handler, new RecordingTaskHandler(TaskType::CacheCleanup)]);
    }

    /** @return array{SchedulingManager, InMemorySchedulingStore, RecordingSchedulingAdminGuard} */
    private function newManager(): array
    {
        $store = new InMemorySchedulingStore();
        $guard = new RecordingSchedulingAdminGuard();
        $handlers = new TaskHandlerRegistry([new RecordingTaskHandler(TaskType::CacheCleanup)]);

        return [new SchedulingManager($store, $guard, $handlers), $store, $guard];
    }
}
