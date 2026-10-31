<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminActivityEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminActivityEntryRepository::class)]
#[ORM\Table(name: 'admin_activity_entries')]
#[ORM\Index(name: 'idx_admin_activity_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_admin_activity_actor', columns: ['actor_id'])]
final class AdminActivityEntry
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $actor;

    #[ORM\Column(name: 'route_name', length: 128)]
    private string $routeName;

    #[ORM\Column(length: 8)]
    private string $method;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(AdminUser $actor, string $routeName, string $method)
    {
        if (1 !== preg_match('/\Aadmin_[a-z0-9_]{1,122}\z/D', $routeName)
            || !in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        ) {
            throw new \InvalidArgumentException('Die Verwaltungsaktivität ist ungültig.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->actor = $actor;
        $this->routeName = $routeName;
        $this->method = $method;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    public function getActor(): ?AdminUser
    {
        return $this->actor;
    }

    public function detachActor(): void
    {
        $this->actor = null;
    }

    public function getRouteName(): string
    {
        return $this->routeName;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAreaLabel(): string
    {
        return match (true) {
            str_starts_with($this->routeName, 'admin_database_backup_') => 'Datenbanksicherungen',
            str_starts_with($this->routeName, 'admin_member_') => 'Mitglieder',
            str_starts_with($this->routeName, 'admin_video_') => 'Videos',
            str_starts_with($this->routeName, 'admin_news_') => 'News',
            str_starts_with($this->routeName, 'admin_series_'),
            str_starts_with($this->routeName, 'admin_episode_') => 'Serien',
            str_starts_with($this->routeName, 'admin_category_') => 'Kategorien',
            str_starts_with($this->routeName, 'admin_tag_') => 'Schlagwörter',
            str_starts_with($this->routeName, 'admin_comment_') => 'Kommentare',
            str_starts_with($this->routeName, 'admin_contact_') => 'Kontakt',
            str_starts_with($this->routeName, 'admin_staff_') => 'Rollen und Rechte',
            str_starts_with($this->routeName, 'admin_password_') => 'Kontosicherheit',
            str_starts_with($this->routeName, 'admin_design_settings_') => 'Theme und Layout',
            str_starts_with($this->routeName, 'admin_general_settings_') => 'Einstellungen',
            str_starts_with($this->routeName, 'admin_content_settings_') => 'Einstellungen',
            str_starts_with($this->routeName, 'admin_page_') => 'Seiten',
            str_starts_with($this->routeName, 'admin_activity_') => 'Aktivität',
            default => 'Verwaltung',
        };
    }

    public function getActionLabel(): string
    {
        return match (true) {
            'admin_database_backup_create' === $this->routeName => 'Datenbanksicherung erstellt',
            'admin_database_backup_verify' === $this->routeName => 'Datenbanksicherung geprüft',
            'admin_database_backup_download' === $this->routeName => 'Datenbanksicherung heruntergeladen',
            'admin_database_backup_delete' === $this->routeName => 'Datenbanksicherung gelöscht',
            'admin_member_new' === $this->routeName => 'Mitgliedskonto angelegt',
            'admin_member_delete' === $this->routeName => 'Mitgliedskonto gelöscht',
            'admin_member_ban' === $this->routeName => 'Mitglied gebannt',
            'admin_member_ban_revoke' === $this->routeName => 'Mitgliedersperre aufgehoben',
            str_contains($this->routeName, 'activity_clear') => 'Aktivitätsverlauf geleert',
            str_contains($this->routeName, 'password_reset') => 'Passwort-Reset ausgestellt',
            str_contains($this->routeName, 'invitation') => 'Einrichtungscode ausgestellt',
            str_contains($this->routeName, 'status') => 'Kontostatus geändert',
            str_contains($this->routeName, 'delete') => 'Eintrag gelöscht',
            str_contains($this->routeName, 'moderate'),
            str_contains($this->routeName, 'close') => 'Eintrag moderiert',
            str_contains($this->routeName, 'approve') => 'Einreichung freigegeben',
            str_contains($this->routeName, 'import') => 'Inhalte importiert',
            'admin_general_settings_update' === $this->routeName => 'Website-Einstellungen geändert',
            'admin_content_settings_update' === $this->routeName => 'Video- und Inhaltseinstellungen geändert',
            'admin_video_subtitle_upload' === $this->routeName => 'Untertitelspur gespeichert',
            'admin_video_subtitle_delete' === $this->routeName => 'Untertitelspur gelöscht',
            'admin_staff_role_update' === $this->routeName => 'Verwaltungsrolle geändert',
            'admin_password_change' === $this->routeName => 'Eigenes Administratorpasswort geändert',
            'admin_design_settings_update' === $this->routeName => 'Theme und Layout geändert',
            str_ends_with($this->routeName, '_new') => 'Inhalt angelegt',
            str_ends_with($this->routeName, '_edit') => 'Inhalt bearbeitet',
            default => 'Verwaltungseintrag geändert',
        };
    }
}
