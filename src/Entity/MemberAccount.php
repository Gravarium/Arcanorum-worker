<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: MemberAccountRepository::class)]
#[ORM\Table(name: 'member_accounts')]
#[ORM\Index(name: 'idx_member_accounts_invitation_token_hash', columns: ['invitation_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_member_accounts_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_member_accounts_handle', columns: ['handle'])]
final class MemberAccount implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    /** @var non-empty-string */
    #[ORM\Column(length: 254)]
    private string $email;

    /** @var non-empty-string */
    #[ORM\Column(length: 32)]
    private string $handle;

    /** @var non-empty-string */
    #[ORM\Column(name: 'display_name', length: 80)]
    private string $displayName;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255)]
    private string $password = '!invalid-password';

    #[ORM\Column(name: 'public_profile', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $publicProfile = false;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'email_verified', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $emailVerified = true;

    #[ORM\Column(name: 'email_version', type: Types::INTEGER, options: ['default' => 0])]
    private int $emailVersion = 0;

    #[ORM\Column(name: 'invitation_token_hash', length: 64, nullable: true)]
    private ?string $invitationTokenHash = null;

    #[ORM\Column(name: 'invitation_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invitationExpiresAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $handle, string $displayName)
    {
        $normalizedEmail = mb_strtolower(trim($email), 'UTF-8');
        $normalizedHandle = mb_strtolower(trim($handle), 'UTF-8');
        $normalizedDisplayName = trim($displayName);

        if ('' === $normalizedEmail
            || mb_strlen($normalizedEmail, 'UTF-8') > 254
            || false === filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)
            || 1 !== preg_match('/\A[a-z0-9][a-z0-9_-]{2,31}\z/D', $normalizedHandle)
            || !$this->validText($normalizedDisplayName)
            || '' === $normalizedDisplayName
            || mb_strlen($normalizedDisplayName, 'UTF-8') > 80
        ) {
            throw new \InvalidArgumentException('Das Mitgliedskonto enthält ungültige Angaben.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->email = $normalizedEmail;
        $this->handle = $normalizedHandle;
        $this->displayName = $normalizedDisplayName;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_MEMBER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function hasUsablePasswordCredential(): bool
    {
        return '!invalid-password' !== $this->password;
    }

    public function setPassword(string $password): void
    {
        if ('' === $password) {
            throw new \InvalidArgumentException('Das Passwort darf nicht leer sein.');
        }

        $this->password = $password;
    }

    public function eraseCredentials(): void
    {
        // The entity stores only the password hash and no transient plaintext credentials.
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function isPublicProfile(): bool
    {
        return $this->publicProfile;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function getEmailVersion(): int
    {
        return $this->emailVersion;
    }

    public function requireEmailVerification(): void
    {
        $this->emailVerified = false;
    }

    public function verifyEmail(): void
    {
        $this->emailVerified = true;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        if ($active) {
            $this->clearInvitation();
        }
    }

    public function setInvitation(string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        if ($this->active || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash)) {
            throw new \InvalidArgumentException('Der Einrichtungscode ist ungültig.');
        }

        $this->invitationTokenHash = $tokenHash;
        $this->invitationExpiresAt = $expiresAt;
    }

    public function getInvitationTokenHash(): ?string
    {
        return $this->invitationTokenHash;
    }

    public function getInvitationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->invitationExpiresAt;
    }

    public function clearInvitation(): void
    {
        $this->invitationTokenHash = null;
        $this->invitationExpiresAt = null;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $this->id === $user->id
            && $this->email === $user->email
            && $this->password === $user->password
            && $this->active === $user->active
            && $this->emailVerified === $user->emailVerified
            && $this->emailVersion === $user->emailVersion;
    }

    public function updateIdentity(string $email, string $handle): void
    {
        $normalizedEmail = mb_strtolower(trim($email), 'UTF-8');
        $normalizedHandle = mb_strtolower(trim($handle), 'UTF-8');

        if (1 !== preg_match('//u', $normalizedEmail)
            || '' === $normalizedEmail
            || mb_strlen($normalizedEmail, 'UTF-8') > 254
            || false === filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)
            || 1 !== preg_match('/\A[a-z0-9][a-z0-9_-]{2,31}\z/D', $normalizedHandle)
        ) {
            throw new \InvalidArgumentException('E-Mail-Adresse oder Kanalname enthält ungültige Angaben.');
        }

        if ($this->email !== $normalizedEmail) {
            if (PHP_INT_MAX === $this->emailVersion) {
                throw new \OverflowException('Die E-Mail-Sicherheitsversion kann nicht weiter erhöht werden.');
            }
            ++$this->emailVersion;
            $this->clearInvitation();
            $this->requireEmailVerification();
        }
        $this->email = $normalizedEmail;
        $this->handle = $normalizedHandle;
    }

    public function updateProfile(string $displayName, string $bio, bool $publicProfile): void
    {
        $normalizedDisplayName = trim($displayName);
        $normalizedBio = trim($bio);

        if (!$this->validText($normalizedDisplayName)
            || '' === $normalizedDisplayName
            || mb_strlen($normalizedDisplayName, 'UTF-8') > 80
            || !$this->validText($normalizedBio)
            || mb_strlen($normalizedBio, 'UTF-8') > 500
        ) {
            throw new \InvalidArgumentException('Das Profil enthält ungültige Angaben.');
        }

        $this->displayName = $normalizedDisplayName;
        $this->bio = '' === $normalizedBio ? null : $normalizedBio;
        $this->publicProfile = $publicProfile;
    }

    private function validText(string $value): bool
    {
        return 1 === preg_match('//u', $value)
            && 1 !== preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }
}
