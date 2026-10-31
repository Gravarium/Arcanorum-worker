<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskTest extends TestCase
{
    public function testFixedSourceCheckCanBePausedButCannotBeCreatedAsAUserTask(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::createSystemSourceCheck($now);

        self::assertTrue($task->isSystemTask());
        self::assertSame('video-source-health-check', $task->getTaskKey());
        self::assertSame(ScheduledTaskType::VideoSourceCheck, $task->getType());
        self::assertSame('2026-10-28T13:00:00+00:00', $task->getNextRunAt()->format(DATE_ATOM));

        $task->setEnabled(false, $now);
        self::assertFalse($task->isEnabled());

        $this->expectException(\InvalidArgumentException::class);
        ScheduledTask::create(ScheduledTaskType::VideoSourceCheck, 'Benutzerauftrag', 60, [], $now);
    }

    public function testUserTaskConfigurationCanBeRevisedAndIntervalIsBounded(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 30, [], $now);
        $task->revise('Sitemapprüfung', 45, [], $now);

        self::assertSame(45, $task->getIntervalMinutes());
        self::assertSame('Sitemapprüfung', $task->getName());

        $this->expectException(\InvalidArgumentException::class);
        $task->revise('Sitemapprüfung', 5, [], $now);
    }
}
