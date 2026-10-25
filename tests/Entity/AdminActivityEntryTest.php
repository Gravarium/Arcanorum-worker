<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AdminActivityEntry;
use App\Entity\AdminUser;
use PHPUnit\Framework\TestCase;

final class AdminActivityEntryTest extends TestCase
{
    public function testActivityLabelsAndActorDetachment(): void
    {
        $actor = new AdminUser('admin@example.test');
        $entry = new AdminActivityEntry($actor, 'admin_member_new', 'POST');

        self::assertSame($actor, $entry->getActor());
        self::assertSame('Mitglieder', $entry->getAreaLabel());
        self::assertSame('Mitgliedskonto angelegt', $entry->getActionLabel());

        $entry->detachActor();

        self::assertNull($entry->getActor());
    }

    public function testRejectsRouteOutsideAdminArea(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AdminActivityEntry(new AdminUser('admin@example.test'), 'member_new', 'POST');
    }

    public function testDatabaseBackupActionsHaveSpecificAuditLabels(): void
    {
        $entry = new AdminActivityEntry(new AdminUser('admin@example.test'), 'admin_database_backup_download', 'POST');

        self::assertSame('Datenbanksicherungen', $entry->getAreaLabel());
        self::assertSame('Datenbanksicherung heruntergeladen', $entry->getActionLabel());
    }
}
