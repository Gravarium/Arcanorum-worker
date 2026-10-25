<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\Table(name: 'admin_users')]
#[ORM\UniqueConstraint(name: 'uniq_admin_users_email', columns: ['email'])]
final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @var list<mixed> Persisted JSON is checked again when read from storage. */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(name: 'mfa_secret_ciphertext', length: 255, nullable: true)]
    private ?string $mfaSecretCiphertext = null;

    #[ORM\Column(name: 'mfa_last_totp_counter', type: Types::BIGINT, nullable: true)]
    private ?string $mfaLastTotpCounter = null;

    #[ORM\Column(name: 'mfa_auth_version', type: Types::INTEGER, options: ['default' => 0])]
    private int $mfaAuthVersion = 0;

    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    /** @var non-empty-string */
    #[ORM\Column(length: 180)]
    private string $email;

    public function __construct(string $email)
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ('' === $normalizedEmail) {
            throw new \InvalidArgumentException('Die E-Mail-Adresse darf nicht leer sein.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->email = $normalizedEmail;
    }

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
        $roles = [];
        foreach ($this->roles as $role) {
            if (!is_string($role) || !AdminRole::supports($role) || in_array($role, $roles, true)) {
                return ['ROLE_USER'];
            }

            $roles[] = $role;
        }

        if (in_array(AdminRole::Administrator->value, $roles, true) && 1 < count($roles)) {
            return ['ROLE_USER'];
        }

        $roles[] = 'ROLE_USER';

        return $roles;
    }

    /** @param list<mixed> $roles */
    public function setRoles(array $roles): void
    {
        $validatedRoles = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new \InvalidArgumentException('Die Kontorolle ist ungültig.');
            }
            if ('ROLE_USER' === $role) {
                continue;
            }
            if (!AdminRole::supports($role)) {
                throw new \InvalidArgumentException('Die Kontorolle wird nicht unterstützt.');
            }

            $validatedRoles[] = $role;
        }

        $validatedRoles = array_values(array_unique($validatedRoles));
        if (in_array(AdminRole::Administrator->value, $validatedRoles, true) && 1 < count($validatedRoles)) {
            throw new \InvalidArgumentException('Administratoren erhalten keine zusätzlichen Moderationsrollen.');
        }

        $this->roles = $validatedRoles;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        if ($this->getId() !== $user->getId()) {
            return false;
        }

        $originalPassword = $this->getPassword();
        $refreshedPassword = $user->getPassword();
        if ($originalPassword !== $refreshedPassword
            && (null === $originalPassword
                || null === $refreshedPassword
                || 8 !== strlen($originalPassword)
                || hash('crc32c', $refreshedPassword) !== $originalPassword)
        ) {
            return false;
        }

        $originalRoles = $this->getRoles();
        $refreshedRoles = $user->getRoles();
        if (count($originalRoles) !== count($refreshedRoles)
            || count($originalRoles) !== count(array_intersect($originalRoles, $refreshedRoles))
        ) {
            return false;
        }

        return $this->getUserIdentifier() === $user->getUserIdentifier()
            && $this->mfaAuthVersion === $user->mfaAuthVersion;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getMfaSecretCiphertext(): ?string
    {
        return $this->mfaSecretCiphertext;
    }

    public function setMfaSecretCiphertext(?string $encryptedSecret): void
    {
        if ($this->mfaSecretCiphertext !== $encryptedSecret) {
            ++$this->mfaAuthVersion;
        }

        $this->mfaSecretCiphertext = $encryptedSecret;
    }

    public function getMfaLastTotpCounter(): ?string
    {
        return $this->mfaLastTotpCounter;
    }

    public function setMfaLastTotpCounter(?int $counter): void
    {
        $this->mfaLastTotpCounter = null === $counter ? null : (string) $counter;
    }
}
