<?php

declare(strict_types=1);

namespace App\Module\Backup\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;

final readonly class ProcessPostgresBackupExecutor implements PostgresBackupExecutorInterface
{
    private const int PROCESS_TIMEOUT_SECONDS = 3600;

    public function __construct(private Connection $connection)
    {
    }

    public function isAvailable(): bool
    {
        try {
            $dump = $this->tool('pg_dump');
            $restore = $this->tool('pg_restore');
            $this->requirePostgreSql18($dump);
            $this->requirePostgreSql18($restore);

            return 'pdo_pgsql' === ($this->connection->getParams()['driver'] ?? null);
        } catch (BackupOperationFailed) {
            return false;
        }
    }

    public function dump(string $targetPath): void
    {
        $this->assertServerVersion18();
        $dump = $this->tool('pg_dump');
        $this->requirePostgreSql18($dump);
        $this->runDatabaseProcess($dump, [
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '--no-password',
            '--file='.$targetPath,
            ...$this->connectionArguments(),
        ], dirname($targetPath));
    }

    public function verifyArchive(string $archivePath): void
    {
        $restore = $this->tool('pg_restore');
        $this->requirePostgreSql18($restore);
        $listPath = dirname($archivePath).DIRECTORY_SEPARATOR.'.pg-restore-list-'.bin2hex(random_bytes(16));
        $handle = @fopen($listPath, 'x+b');
        if (false === $handle) {
            throw new BackupOperationFailed();
        }
        $permissionsSet = @chmod($listPath, 0600);
        $closed = @fclose($handle);
        if (!$permissionsSet || !$closed) {
            @unlink($listPath);
            throw new BackupOperationFailed();
        }

        try {
            $process = new Process([$restore, '--list', '--exit-on-error', '--file='.$listPath, $archivePath], dirname($archivePath), $this->isolatedEnvironment());
            $process->disableOutput();
            $process->setTimeout(60);
            $status = $process->run();
            $listStat = @lstat($listPath);
            if (0 !== $status || false === $listStat || 0100000 !== ($listStat['mode'] & 0170000)
                || 0 !== ($listStat['mode'] & 0077) || $listStat['size'] < 1 || is_link($listPath)
            ) {
                throw new BackupOperationFailed();
            }
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        } finally {
            if (is_file($listPath) && !is_link($listPath)) {
                @unlink($listPath);
            }
        }
    }

    /** @return list<string> */
    private function connectionArguments(): array
    {
        $parameters = $this->connection->getParams();
        if ('pdo_pgsql' !== ($parameters['driver'] ?? null)) {
            throw new BackupOperationFailed();
        }

        $arguments = [];
        $host = $this->stringParameter($parameters, 'host');
        if (null !== $host) {
            $arguments[] = '--host';
            $arguments[] = $host;
        }

        $port = $parameters['port'] ?? null;
        if (null !== $port) {
            $port = (string) $port;
            if (1 !== preg_match('/\A[0-9]{1,5}\z/', $port) || (int) $port > 65535 || 0 === (int) $port) {
                throw new BackupOperationFailed();
            }
            $arguments[] = '--port='.$port;
        }

        $user = $this->stringParameter($parameters, 'user');
        if (null !== $user) {
            $arguments[] = '--username='.$user;
        }

        $database = $this->stringParameter($parameters, 'dbname');
        if (null === $database) {
            throw new BackupOperationFailed();
        }
        $arguments[] = '--dbname='.$database;

        return $arguments;
    }

    /** @param list<string> $arguments */
    private function runDatabaseProcess(string $binary, array $arguments, string $workingDirectory): void
    {
        $passFile = $this->createPasswordFile($workingDirectory);
        try {
            $environment = array_replace($this->isolatedEnvironment($this->connection->getParams()), ['PGPASSFILE' => $passFile]);
            $process = new Process([$binary, ...$arguments], $workingDirectory, $environment);
            $process->disableOutput();
            $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
            $status = $process->run();
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        } finally {
            if (is_file($passFile) && !is_link($passFile)) {
                @unlink($passFile);
            }
        }

        if (0 !== $status) {
            throw new BackupOperationFailed();
        }
    }

    private function createPasswordFile(string $directory): string
    {
        $parameters = $this->connection->getParams();
        $password = $this->stringParameter($parameters, 'password');

        $path = $directory.DIRECTORY_SEPARATOR.'.pgpass-'.bin2hex(random_bytes(16));
        $handle = @fopen($path, 'x+b');
        if (false === $handle) {
            throw new BackupOperationFailed();
        }

        try {
            if (!@chmod($path, 0600)) {
                throw new BackupOperationFailed();
            }

            if (null !== $password && '' !== $password) {
                $host = $this->pgPassEscape($this->stringParameter($parameters, 'host') ?? '*');
                $port = $this->pgPassEscape(null === ($configuredPort = $parameters['port'] ?? null) ? '*' : (string) $configuredPort);
                $database = $this->pgPassEscape($this->stringParameter($parameters, 'dbname') ?? '*');
                $user = $this->pgPassEscape($this->stringParameter($parameters, 'user') ?? '*');
                $line = $host.':'.$port.':'.$database.':'.$user.':'.$this->pgPassEscape($password)."\n";
                if (strlen($line) > 8192 || false === @fwrite($handle, $line) || !@fflush($handle)) {
                    throw new BackupOperationFailed();
                }
            }
        } catch (\Throwable $exception) {
            @fclose($handle);
            @unlink($path);
            if ($exception instanceof BackupOperationFailed) {
                throw $exception;
            }

            throw new BackupOperationFailed();
        }

        if (!@fclose($handle)) {
            @unlink($path);
            throw new BackupOperationFailed();
        }

        return $path;
    }

    private function pgPassEscape(string $value): string
    {
        if (str_contains($value, "\0") || 1 === preg_match('/[\r\n]/', $value)) {
            throw new BackupOperationFailed();
        }

        return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
    }

    /**
     * @param array<string, mixed> $databaseParameters
     *
     * @return array<string, string|false>
     */
    private function isolatedEnvironment(array $databaseParameters = []): array
    {
        $environment = [];
        $processEnvironment = getenv();
        foreach (array_unique([...array_keys($processEnvironment), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $key) {
            if (is_string($key) && '' !== $key) {
                $environment[$key] = false;
            }
        }

        $safeEnvironment = [];
        foreach (['PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR'] as $key) {
            $value = getenv($key);
            if (is_string($value) && '' !== $value) {
                $safeEnvironment[$key] = $value;
            }
        }

        foreach ($safeEnvironment as $key => $value) {
            $environment[$key] = $value;
        }

        foreach ([
            'sslmode' => 'PGSSLMODE',
            'sslrootcert' => 'PGSSLROOTCERT',
            'sslcert' => 'PGSSLCERT',
            'sslkey' => 'PGSSLKEY',
            'sslcrl' => 'PGSSLCRL',
            'sslcrldir' => 'PGSSLCRLDIR',
            'gssencmode' => 'PGGSSENCMODE',
        ] as $parameter => $variable) {
            $value = $databaseParameters[$parameter] ?? null;
            if (null === $value) {
                continue;
            }
            if (!is_string($value) || '' === $value || str_contains($value, "\0") || 1 === preg_match('/[\r\n]/', $value)) {
                throw new BackupOperationFailed();
            }
            $environment[$variable] = $value;
        }

        return $environment;
    }

    private function assertServerVersion18(): void
    {
        try {
            $version = $this->connection->fetchOne('SHOW server_version_num');
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        }

        if (!is_string($version) && !is_int($version)) {
            throw new BackupOperationFailed();
        }
        if (1 !== preg_match('/\A18[0-9]{4}\z/', (string) $version)) {
            throw new BackupOperationFailed();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function stringParameter(array $parameters, string $name): ?string
    {
        $value = $parameters[$name] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || str_contains($value, "\0") || 1 === preg_match('/[\r\n]/', $value)) {
            throw new BackupOperationFailed();
        }

        return $value;
    }

    private function tool(string $name): string
    {
        $candidates = [
            '/usr/bin/'.$name,
            '/usr/local/bin/'.$name,
            '/usr/lib/postgresql/18/bin/'.$name,
        ];

        foreach ($candidates as $candidate) {
            if (!is_link($candidate) && is_file($candidate) && is_executable($candidate)) {
                $resolved = realpath($candidate);
                if (false !== $resolved && is_file($resolved) && is_executable($resolved)) {
                    return $resolved;
                }
            }
        }

        throw new BackupOperationFailed();
    }

    private function requirePostgreSql18(string $binary): void
    {
        $process = new Process([$binary, '--version'], dirname($binary), $this->isolatedEnvironment());
        $process->setTimeout(5);
        try {
            $status = $process->run();
        } catch (\Throwable) {
            throw new BackupOperationFailed();
        }

        $version = $process->getOutput().$process->getErrorOutput();
        if (0 !== $status || 1 !== preg_match('/\b(?:pg_dump|pg_restore)\s+\(PostgreSQL\)\s+18\./', $version)) {
            throw new BackupOperationFailed();
        }
    }
}
