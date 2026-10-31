<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrivateObjectDeletionJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrivateObjectDeletionJobRepository::class)]
#[ORM\Table(name: 'private_object_deletion_jobs')]
#[ORM\UniqueConstraint(name: 'uniq_private_object_deletion_object', columns: ['object_id'])]
#[ORM\Index(name: 'idx_private_object_deletion_available', columns: ['available_at', 'created_at'])]
final class PrivateObjectDeletionJob
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(name: 'object_id', length: 64)]
    private string $objectId;

    #[ORM\Column(name: 'attempt_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'available_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'last_attempt_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    public function __construct(string $objectId)
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung des privaten Speicherobjekts ist ungültig.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->id = bin2hex(random_bytes(16));
        $this->objectId = $objectId;
        $this->createdAt = $now;
        $this->availableAt = $now;
    }

    public function getObjectId(): string
    {
        return $this->objectId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getAvailableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function recordFailure(\DateTimeImmutable $attemptedAt): void
    {
        if (PHP_INT_MAX === $this->attemptCount) {
            throw new \OverflowException('Der Wiederholungszähler ist ausgeschöpft.');
        }

        ++$this->attemptCount;
        $this->lastAttemptAt = $attemptedAt;
        $delay = min(3600, 30 * (2 ** min(7, $this->attemptCount - 1)));
        $this->availableAt = $attemptedAt->add(new \DateInterval('PT'.$delay.'S'));
    }
}
