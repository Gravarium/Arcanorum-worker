<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskType;
use App\Repository\ScheduledTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScheduledTaskRepositoryTest extends KernelTestCase
{
    public function testTasksWaitingForLeaseOrBackoffDoNotStarveOtherDueTasks(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $createdAt = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
            $now = $createdAt->modify('+20 minutes 10 seconds');
            $waitingTask = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Waiting retry '.$this->suffix(), 15, [], $createdAt);
            $runnableTask = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Runnable '.$this->suffix(), 15, [], $createdAt->modify('+1 minute'));
            $run = new ScheduledTaskRun(
                $waitingTask,
                $waitingTask->getNextRunAt(),
                $now->modify('-30 seconds'),
                'repository-test',
            );
            $run->fail($run->takeLeaseToken(), 'Temporary provider failure.', $now->modify('-20 seconds'));

            $entityManager->persist($waitingTask);
            $entityManager->persist($runnableTask);
            $entityManager->persist($run);
            $entityManager->flush();

            $tasks = static::getContainer()->get(ScheduledTaskRepository::class);
            self::assertInstanceOf(ScheduledTaskRepository::class, $tasks);
            $dueTasks = $tasks->findDue($now, 10);
            $dueIds = array_map(static fn (ScheduledTask $task): string => $task->getId(), $dueTasks);

            self::assertContains($runnableTask->getId(), $dueIds);
            self::assertNotContains($waitingTask->getId(), $dueIds);
            self::assertEquals($now->modify('+10 seconds'), $run->getRetryAfter());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(6));
    }
}
