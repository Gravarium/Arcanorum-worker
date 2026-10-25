<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdminPhpRuntimeSummary;
use App\Service\PhpRuntimeInspectorInterface;
use PHPUnit\Framework\TestCase;

final class AdminPhpRuntimeSummaryTest extends TestCase
{
    public function testReturnsOnlyCuratedAndValidatedRuntimeInformation(): void
    {
        $inspector = new FakePhpRuntimeInspector([
            'upload_max_filesize' => '64M',
            'post_max_size' => '72M',
            'memory_limit' => '256M',
            'max_execution_time' => '30',
            'max_input_time' => '60',
            'max_input_vars' => '3000',
            'max_file_uploads' => '20',
            'default_socket_timeout' => '60',
            'display_errors' => 'stderr',
            'log_errors' => '1',
            'expose_php' => '0',
            'allow_url_include' => '0',
            'allow_url_fopen' => '1',
            'opcache.enable' => '1',
            'zend.assertions' => '-1',
        ]);

        $summary = new AdminPhpRuntimeSummary($inspector, 'prod');
        $result = $summary->current();

        self::assertSame('8.5.4', $result['php_version']);
        self::assertSame('fpm-fcgi', $result['php_sapi']);
        self::assertSame('64M', $result['php_limits']['Maximale Uploadgröße']);
        self::assertSame('3000', $result['php_limits']['Maximale Eingabefelder']);
        self::assertSame('Separater Fehlerkanal', $result['php_settings']['Fehlerausgabe']);
        self::assertSame('Aktiviert', $result['php_settings']['URL-Dateizugriff']);
        self::assertSame('Deaktiviert', $result['php_settings']['PHP-Version im HTTP-Header']);
        self::assertSame('-1', $result['php_settings']['Assertionsmodus']);
        self::assertTrue($result['php_extensions']['PostgreSQL PDO']);
        self::assertFalse($result['php_extensions']['Redis']);
        self::assertSame([], $result['notices']);

        self::assertSame([
            'upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time',
            'max_input_time', 'max_input_vars', 'max_file_uploads', 'default_socket_timeout',
            'display_errors', 'log_errors', 'expose_php', 'allow_url_include', 'allow_url_fopen',
            'opcache.enable', 'zend.assertions',
        ], $inspector->requestedIniNames);
        self::assertStringNotContainsString('private-value-should-not-appear', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('session.save_path', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testCreatesActionableNoticesForUnsafeProductionSettings(): void
    {
        $inspector = new FakePhpRuntimeInspector([
            'allow_url_include' => 'On',
            'display_errors' => '1',
        ]);

        $result = new AdminPhpRuntimeSummary($inspector, 'prod')->current();

        self::assertSame([
            'PHP-URL-Includes sind aktiviert und sollten deaktiviert werden.',
            'PHP-Fehlerausgabe ist in der Produktionsumgebung aktiviert.',
        ], $result['notices']);

        $development = new AdminPhpRuntimeSummary($inspector, 'dev')->current();
        self::assertSame(['PHP-URL-Includes sind aktiviert und sollten deaktiviert werden.'], $development['notices']);
    }

    public function testSuppressesMalformedLimitsVersionAndSapiValues(): void
    {
        $inspector = new FakePhpRuntimeInspector([
            'upload_max_filesize' => '/private/config with secret.ini',
            'max_execution_time' => '12<script>alert(1)</script>',
            'allow_url_fopen' => '<script>alert(1)</script>',
        ], '8.5.4<script>', 'fpm /private/path');

        $result = new AdminPhpRuntimeSummary($inspector, 'test')->current();

        self::assertSame('Nicht verfügbar', $result['php_version']);
        self::assertSame('Nicht verfügbar', $result['php_sapi']);
        self::assertSame('Nicht verfügbar', $result['php_limits']['Maximale Uploadgröße']);
        self::assertSame('Nicht verfügbar', $result['php_limits']['Zeitlimit pro Anfrage']);
        self::assertSame('Nicht verfügbar', $result['php_settings']['URL-Dateizugriff']);
        self::assertStringNotContainsString('/private', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('<script>', json_encode($result, JSON_THROW_ON_ERROR));
    }
}

/** @internal */
final class FakePhpRuntimeInspector implements PhpRuntimeInspectorInterface
{
    /** @var list<string> */
    public array $requestedIniNames = [];

    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private array $values,
        private readonly string $phpVersion = '8.5.4',
        private readonly string $phpSapi = 'fpm-fcgi',
    ) {
    }

    public function version(): string
    {
        return $this->phpVersion;
    }

    public function sapi(): string
    {
        return $this->phpSapi;
    }

    public function iniValue(string $name): string
    {
        $this->requestedIniNames[] = $name;

        return $this->values[$name] ?? 'private-value-should-not-appear';
    }

    public function extensionLoaded(string $name): bool
    {
        return 'redis' !== $name;
    }
}
