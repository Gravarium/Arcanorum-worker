<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScheduledTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_tasks')]
#[ORM\UniqueConstraint(name: 'uniq_scheduled_task_key', columns: ['task_key'])]
#[ORM\Index(name: 'idx_scheduled_task_due', columns: ['enabled', 'next_run_at'])]
final class ScheduledTask
{
    public const int MIN_INTERVAL_MINUTES = 15;
    public const int MAX_INTERVAL_MINUTES = 10080;

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(name: 'task_key', length: 80)]
    private string $taskKey;

    #[ORM\Column(length: 32, enumType: ScheduledTaskType::class)]
    private ScheduledTaskType $type;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $systemTask;

    #[ORM\Column(name: 'interval_minutes', type: Types::INTEGER)]
    private int $intervalMinutes;

    #[ORM\Column(name: 'next_run_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $nextRunAt;

    #[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $configuration */
    private function __construct(
        string $taskKey,
        ScheduledTaskType $type,
        string $name,
        int $intervalMinutes,
        #[ORM\Column(type: Types::JSON)]
        private array $configuration,
        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
        private bool $enabled,
        bool $systemTask,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        self::assertKey($taskKey);
        self::assertName($name);
        self::assertInterval($intervalMinutes);

        if ($systemTask !== $type->isSystemOnly()) {
            throw new \InvalidArgumentException('Nur der feste Quellenprüfer darf ein Systemauftrag sein.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->taskKey = $taskKey;
        $this->type = $type;
        $this->name = trim($name);
        $this->intervalMinutes = $intervalMinutes;
        $this->systemTask = $systemTask;
        $this->updatedAt = $this->createdAt;
        $this->nextRunAt = $this->createdAt->modify(sprintf('+%d minutes', $intervalMinutes));
    }

    public static function createSystemSourceCheck(\DateTimeImmutable $now): self
    {
        return new self(
            'video-source-health-check',
            ScheduledTaskType::VideoSourceCheck,
            'Entfernte Videoquellen zur Moderation melden',
            60,
            [],
            true,
            true,
            $now,
        );
    }

    /** @param array<string, mixed> $configuration */
    public static function create(
        ScheduledTaskType $type,
        string $name,
        int $intervalMinutes,
        array $configuration,
        \DateTimeImmutable $now,
    ): self {
        if ($type->isSystemOnly()) {
            throw new \InvalidArgumentException('Der feste Videoquellenprüfer kann nicht als eigener Auftrag angelegt werden.');
        }

        return new self(
            'task-'.bin2hex(random_bytes(16)),
            $type,
            $name,
            $intervalMinutes,
            $configuration,
            true,
            false,
            $now,
        );
    }

    /** @param array<string, mixed> $configuration */
    public function revise(string $name, int $intervalMinutes, array $configuration, \DateTimeImmutable $now): void
    {
        self::assertName($name);
        self::assertInterval($intervalMinutes);
        if ($this->systemTask && ($this->name !== trim($name) || $this->configuration !== $configuration)) {
            throw new \InvalidArgumentException('Der feste Systemauftrag kann nicht umbenannt oder umkonfiguriert werden.');
        }
        $this->name = trim($name);
        $this->intervalMinutes = $intervalMinutes;
        $this->configuration = $configuration;
        $this->nextRunAt = $now->modify(sprintf('+%d minutes', $intervalMinutes));
        $this->updatedAt = $now;
    }

    public function setEnabled(bool $enabled, \DateTimeImmutable $now): void
    {
        if ($this->enabled === $enabled) {
            return;
        }

        $this->enabled = $enabled;
        if ($enabled) {
            $this->nextRunAt = $now->modify(sprintf('+%d minutes', $this->intervalMinutes));
        }
        $this->updatedAt = $now;
    }

    public function markRun(\DateTimeImmutable $scheduledAt, \DateTimeImmutable $now): void
    {
        $this->lastRunAt = $now;
        $this->nextRunAt = $scheduledAt > $now
            ? $scheduledAt->modify(sprintf('+%d minutes', $this->intervalMinutes))
            : $now->modify(sprintf('+%d minutes', $this->intervalMinutes));
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTaskKey(): string
    {
        return $this->taskKey;
    }

    public function getType(): ScheduledTaskType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isSystemTask(): bool
    {
        return $this->systemTask;
    }

    public function getIntervalMinutes(): int
    {
        return $this->intervalMinutes;
    }

    /** @return array<string, mixed> */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getNextRunAt(): \DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function assertKey(string $taskKey): void
    {
        if (1 !== preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D', $taskKey)) {
            throw new \InvalidArgumentException('Der interne Aufgabenbezeichner ist ungültig.');
        }
    }

    private static function assertName(string $name): void
    {
        $name = trim($name);
        if (!mb_check_encoding($name, 'UTF-8')
            || mb_strlen($name, 'UTF-8') < 3
            || mb_strlen($name, 'UTF-8') > 120
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $name)
        ) {
            throw new \InvalidArgumentException('Der Aufgabenname muss 3 bis 120 sichere Zeichen enthalten.');
        }
    }

    private static function assertInterval(int $intervalMinutes): void
    {
        if ($intervalMinutes < self::MIN_INTERVAL_MINUTES || $intervalMinutes > self::MAX_INTERVAL_MINUTES) {
            throw new \InvalidArgumentException('Das Intervall muss zwischen 15 Minuten und sieben Tagen liegen.');
        }
    }
}
