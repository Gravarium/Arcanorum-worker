<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_messages')]
#[ORM\Index(name: 'idx_contact_messages_status_created', columns: ['status', 'created_at'])]
final class ContactMessage
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 16, enumType: ContactMessageStatus::class, options: ['default' => 'pending'])]
    private ContactMessageStatus $status = ContactMessageStatus::Pending;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 254)]
        private string $email,
        #[ORM\Column(length: 160)]
        private string $subject,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
    ) {
        if ('' === trim($name)
            || 1 !== preg_match('//u', $name)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $name)
            || mb_strlen($name, 'UTF-8') > 120
            || false === filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($email, 'UTF-8') > 254
            || '' === trim($subject)
            || 1 !== preg_match('//u', $subject)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $subject)
            || mb_strlen($subject, 'UTF-8') > 160
            || '' === trim($body)
            || 1 !== preg_match('//u', $body)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)
            || mb_strlen($body, 'UTF-8') > 5000
        ) {
            throw new \InvalidArgumentException('Die Kontaktnachricht enthält ungültige Angaben.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function resolve(): void
    {
        if (ContactMessageStatus::Pending !== $this->status) {
            throw new \InvalidArgumentException('Nur offene Kontaktnachrichten können abgeschlossen werden.');
        }

        $this->status = ContactMessageStatus::Resolved;
        $this->resolvedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): ContactMessageStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
