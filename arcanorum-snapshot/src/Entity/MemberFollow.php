<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberFollowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberFollowRepository::class)]
#[ORM\Table(name: 'member_follows')]
#[ORM\UniqueConstraint(name: 'uniq_member_follows_pair', columns: ['follower_id', 'followed_id'])]
#[ORM\Index(name: 'idx_member_follows_followed_created', columns: ['followed_id', 'created_at'])]
#[ORM\Index(name: 'idx_member_follows_follower_created', columns: ['follower_id', 'created_at'])]
final class MemberFollow
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
    #[ORM\JoinColumn(name: 'follower_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MemberAccount $follower;

    #[ORM\ManyToOne(targetEntity: MemberAccount::class)]
    #[ORM\JoinColumn(name: 'followed_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MemberAccount $followed;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(MemberAccount $follower, MemberAccount $followed)
    {
        if ($follower->getId() === $followed->getId()) {
            throw new \InvalidArgumentException('Ein Mitglied kann sich nicht selbst folgen.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->follower = $follower;
        $this->followed = $followed;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    public function getFollower(): MemberAccount
    {
        return $this->follower;
    }

    public function getFollowed(): MemberAccount
    {
        return $this->followed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
