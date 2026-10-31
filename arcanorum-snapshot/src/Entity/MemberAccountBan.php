<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberAccountBanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberAccountBanRepository::class)]
#[ORM\Table(name: 'member_account_bans')]
#[ORM\Index(name: 'idx_member_account_bans_member_created', columns: ['member_id', 'created_at'])]
#[ORM\Index(name: 'idx_member_account_bans_revoked_created', columns: ['revoked_at', 'created_at'])]
final class MemberAccountBan
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'revoked_by_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $revokedBy = null;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MemberAccount::class, inversedBy: 'bans')]
        #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private MemberAccount $member,
        #[ORM\ManyToOne(targetEntity: AdminUser::class)]
        #[ORM\JoinColumn(name: 'banned_by_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        private ?AdminUser $bannedBy,
        string $reason,
        #[ORM\Column(name: 'was_active', type: Types::BOOLEAN)]
        private bool $wasActive,
    ) {
        $normalizedReason = trim($reason);
        if (1 !== preg_match('//u', $normalizedReason)
            || '' === $normalizedReason
            || mb_strlen($normalizedReason, 'UTF-8') > 500
            || 1 === preg_match('/\p{Cc}/u', $normalizedReason)
        ) {
            throw new \InvalidArgumentException('Die Begründung muss gültigen Text mit 1 bis 500 Zeichen enthalten.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->reason = $normalizedReason;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->member->addBan($this);
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

    public function getBannedBy(): ?AdminUser
    {
        return $this->bannedBy;
    }

    public function getRevokedBy(): ?AdminUser
    {
        return $this->revokedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function wasActive(): bool
    {
        return $this->wasActive;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(AdminUser $revokedBy, ?\DateTimeImmutable $revokedAt = null): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Diese Mitgliedersperre wurde bereits aufgehoben.');
        }

        $revokedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($revokedAt < $this->createdAt) {
            throw new \InvalidArgumentException('Der Aufhebungszeitpunkt darf nicht vor der Sperre liegen.');
        }

        $this->revokedBy = $revokedBy;
        $this->revokedAt = $revokedAt;
    }
}
