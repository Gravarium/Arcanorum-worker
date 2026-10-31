<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberExternalIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberExternalIdentityRepository::class)]
#[ORM\Table(name: 'member_external_identities')]
#[ORM\UniqueConstraint(name: 'uniq_member_external_provider_subject', columns: ['provider', 'subject'])]
#[ORM\UniqueConstraint(name: 'uniq_member_external_member_provider', columns: ['member_id', 'provider'])]
#[ORM\Index(name: 'idx_member_external_member', columns: ['member_id'])]
final class MemberExternalIdentity
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 16)]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
        #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private MemberAccount $member,
        string $provider,
        string $subject,
    ) {
        if (!in_array($provider, ['google', 'facebook', 'twitter'], true)
            || '' === $subject
            || strlen($subject) > 255
            || 1 !== preg_match('//u', $subject)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $subject)
        ) {
            throw new \InvalidArgumentException('Die externe Mitgliedsidentität ist ungültig.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->provider = $provider;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    public function getMember(): MemberAccount
    {
        return $this->member;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
