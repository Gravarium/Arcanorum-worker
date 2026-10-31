<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup\Service;

use App\Module\Backup\Service\DatabaseBackupService;
use App\Module\Backup\Service\ProcessPostgresBackupExecutor;
use App\Platform\Hosting\HostingCapabilityGuard;
use App\Platform\Hosting\PrivateStorageProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class PostgresBackupExecutorIntegrationTest extends KernelTestCase
{
    public function testPostgreSql18DumpCanBeCreatedAndReadAsAValidArchive(): void
    {
        $databaseUrl = getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || '' === $databaseUrl) {
            self::markTestSkipped('Für den lokalen PostgreSQL-Sicherungstest ist DATABASE_URL nicht gesetzt.');
        }

        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $executor = new ProcessPostgresBackupExecutor($connection);
        if (!$executor->isAvailable()) {
            self::markTestSkipped('PostgreSQL-18-Sicherungswerkzeuge sind in dieser Umgebung nicht verfügbar.');
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-pg-backup-probe-'.bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            self::fail('Das isolierte Sicherungs-Testverzeichnis konnte nicht angelegt werden.');
        }
        $archivePath = $directory.DIRECTORY_SEPARATOR.'probe.dump';

        try {
            $handle = @fopen($archivePath, 'x+b');
            if (false === $handle) {
                self::fail('Die temporäre Testdatei konnte nicht sicher angelegt werden.');
            }
            $permissionsSet = @chmod($archivePath, 0600);
            fclose($handle);
            self::assertTrue($permissionsSet);

            $executor->dump($archivePath);
            $executor->verifyArchive($archivePath);

            self::assertGreaterThan(0, filesize($archivePath));
            $permissions = fileperms($archivePath);
            self::assertNotFalse($permissions);
            self::assertSame(0, $permissions & 0077);
        } finally {
            $connection->close();
            if (is_file($archivePath)) {
                @unlink($archivePath);
            }
            @rmdir($directory);
        }
    }

    public function testEncryptedBackupCanBeRestoredIntoAnIsolatedTemporaryDatabase(): void
    {
        $databaseUrl = getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || '' === $databaseUrl) {
            self::markTestSkipped('Für den lokalen Wiederherstellungstest ist DATABASE_URL nicht gesetzt.');
        }

        self::bootKernel();
        $source = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $source);
        $executor = new ProcessPostgresBackupExecutor($source);
        if (!$executor->isAvailable()) {
            self::markTestSkipped('PostgreSQL-18-Sicherungswerkzeuge sind in dieser Umgebung nicht verfügbar.');
        }
        $canCreateDatabase = $source->fetchOne('SELECT rolcreatedb FROM pg_roles WHERE rolname = current_user');
        if (!in_array($canCreateDatabase, [true, 1, '1', 't'], true)) {
            self::markTestSkipped('Die Testdatenbankrolle darf keine isolierte Wiederherstellungsdatenbank anlegen.');
        }

        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-restore-probe-'.bin2hex(random_bytes(16));
        $applicationDirectory = $root.DIRECTORY_SEPARATOR.'app';
        $publicDirectory = $applicationDirectory.DIRECTORY_SEPARATOR.'public';
        $privateDirectory = $root.DIRECTORY_SEPARATOR.'private';
        if (!@mkdir($root, 0700) || !@mkdir($applicationDirectory, 0700) || !@mkdir($publicDirectory, 0700) || !@mkdir($privateDirectory, 0700)) {
            self::fail('Das isolierte Wiederherstellungs-Testverzeichnis konnte nicht angelegt werden.');
        }

        $targetDatabase = 'arcanorum_restore_probe_'.bin2hex(random_bytes(12));
        $privateBackup = null;
        $targetConnection = null;

        try {
            $backups = new DatabaseBackupService(
                $executor,
                new PrivateStorageProbe($publicDirectory, $privateDirectory),
                new HostingCapabilityGuard(),
                $applicationDirectory,
                $publicDirectory,
                $privateDirectory,
                bin2hex(random_bytes(32)),
            );
            $created = $backups->create();
            $backups->verify($created['id']);
            $privateBackup = $backups->decryptedArchivePath($created['id']);

            $source->executeStatement('CREATE DATABASE "'.$targetDatabase.'"');
            $this->restoreWithPostgres18Tool($source, $privateBackup, $targetDatabase, dirname($privateBackup));

            $targetParameters = $source->getParams();
            $targetParameters['dbname'] = $targetDatabase;
            $targetConnection = DriverManager::getConnection($targetParameters);
            $sourceMigrations = $source->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
            $restoredMigrations = $targetConnection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
            if ((!is_string($sourceMigrations) && !is_int($sourceMigrations))
                || (!is_string($restoredMigrations) && !is_int($restoredMigrations))
            ) {
                self::fail('Die Wiederherstellungsprobe konnte die Migrationsstände nicht lesen.');
            }
            self::assertSame((string) $sourceMigrations, (string) $restoredMigrations);
        } finally {
            if ($targetConnection instanceof Connection) {
                $targetConnection->close();
            }
            $source->executeStatement('DROP DATABASE IF EXISTS "'.$targetDatabase.'" WITH (FORCE)');
            if (null !== $privateBackup && is_file($privateBackup) && !is_link($privateBackup)) {
                @unlink($privateBackup);
            }
            $this->removeTree($root);
        }
    }

    private function restoreWithPostgres18Tool(Connection $connection, string $archivePath, string $targetDatabase, string $workingDirectory): void
    {
        $parameters = $connection->getParams();
        $passFile = $workingDirectory.DIRECTORY_SEPARATOR.'.restore-probe-pass-'.bin2hex(random_bytes(16));
        $passHandle = @fopen($passFile, 'x+b');
        if (false === $passHandle) {
            self::fail('Die temporäre Zugangsdaten-Datei konnte nicht sicher angelegt werden.');
        }
        $permissionsSet = @chmod($passFile, 0600);
        $host = $this->escapePgPass($parameters['host'] ?? '*');
        $port = $this->escapePgPass((string) ($parameters['port'] ?? '*'));
        $database = $this->escapePgPass($targetDatabase);
        $user = $this->escapePgPass($parameters['user'] ?? '*');
        $password = $this->escapePgPass($parameters['password'] ?? '');
        $line = $host.':'.$port.':'.$database.':'.$user.':'.$password."\n";
        $written = false === $permissionsSet ? false : @fwrite($passHandle, $line);
        $flushed = false === $written ? false : @fflush($passHandle);
        $closed = @fclose($passHandle);
        if (false === $written || strlen($line) !== $written || !$flushed || !$closed) {
            @unlink($passFile);
            self::fail('Die temporäre Zugangsdaten-Datei konnte nicht vollständig geschrieben werden.');
        }

        $environment = $this->isolatedEnvironment();
        $environment['PGPASSFILE'] = $passFile;
        $arguments = ['/usr/lib/postgresql/18/bin/pg_restore', '--exit-on-error', '--no-owner', '--no-acl', '--dbname='.$targetDatabase];
        if (is_string($parameters['host'] ?? null) && '' !== $parameters['host']) {
            $arguments[] = '--host='.$parameters['host'];
        }
        if (is_int($parameters['port'] ?? null)) {
            $arguments[] = '--port='.$parameters['port'];
        }
        if (is_string($parameters['user'] ?? null) && '' !== $parameters['user']) {
            $arguments[] = '--username='.$parameters['user'];
        }
        $arguments[] = $archivePath;
        $process = new Process($arguments, $workingDirectory, $environment);
        $process->disableOutput();
        $process->setTimeout(120);

        try {
            if (!$process->isSuccessful() && 0 !== $process->run()) {
                self::fail('Die Wiederherstellungsprobe ist fehlgeschlagen.');
            }
        } finally {
            if (is_file($passFile) && !is_link($passFile)) {
                @unlink($passFile);
            }
        }
    }

    private function escapePgPass(string $value): string
    {
        if (str_contains($value, "\0") || 1 === preg_match('/[\r\n]/', $value)) {
            self::fail('Ein ungültiger Test-Verbindungswert wurde abgelehnt.');
        }

        return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
    }

    /** @return array<string, string|false> */
    private function isolatedEnvironment(): array
    {
        $environment = [];
        foreach (array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $key) {
            if (is_string($key) && '' !== $key) {
                $environment[$key] = false;
            }
        }
        foreach (['PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR'] as $key) {
            $value = getenv($key);
            if (is_string($value) && '' !== $value) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach (false === $items ? [] : $items as $item) {
            if ('.' !== $item && '..' !== $item) {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$item);
            }
        }
        @rmdir($path);
    }
}
