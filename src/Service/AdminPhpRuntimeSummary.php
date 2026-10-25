<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AdminPhpRuntimeSummary
{
    /** @var array<string, string> */
    private const array LIMITS = [
        'Maximale Uploadgröße' => 'upload_max_filesize',
        'Maximale POST-Größe' => 'post_max_size',
        'Speichergrenze' => 'memory_limit',
        'Zeitlimit pro Anfrage' => 'max_execution_time',
        'Zeitlimit für Eingaben' => 'max_input_time',
        'Maximale Eingabefelder' => 'max_input_vars',
        'Maximale Dateiübertragungen' => 'max_file_uploads',
        'Socket-Zeitlimit' => 'default_socket_timeout',
    ];

    /** @var array<string, string> */
    private const array SETTINGS = [
        'Fehlerausgabe' => 'display_errors',
        'Fehlerprotokollierung' => 'log_errors',
        'PHP-Version im HTTP-Header' => 'expose_php',
        'URL-Includes' => 'allow_url_include',
        'URL-Dateizugriff' => 'allow_url_fopen',
        'OPcache' => 'opcache.enable',
        'Assertionsmodus' => 'zend.assertions',
    ];

    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'Ctype' => 'ctype',
        'Iconv' => 'iconv',
        'Internationalisierung' => 'intl',
        'JSON' => 'json',
        'Multibyte-Zeichen' => 'mbstring',
        'PDO' => 'PDO',
        'PostgreSQL PDO' => 'pdo_pgsql',
        'Sodium' => 'sodium',
        'ZIP' => 'zip',
        'Dateitypprüfung' => 'fileinfo',
        'Redis' => 'redis',
    ];

    public function __construct(
        private PhpRuntimeInspectorInterface $runtime,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @return array{
     *     php_version: string,
     *     php_sapi: string,
     *     php_limits: array<string, string>,
     *     php_settings: array<string, string>,
     *     php_extensions: array<string, bool>,
     *     notices: list<string>
     * }
     */
    public function current(): array
    {
        $iniNames = array_merge(array_values(self::LIMITS), array_values(self::SETTINGS));
        $values = [];
        foreach ($iniNames as $iniName) {
            $values[$iniName] = $this->runtime->iniValue($iniName);
        }

        $allowUrlInclude = $this->booleanValue($values['allow_url_include'] ?? null);
        $displayErrors = $this->booleanValue($values['display_errors'] ?? null);
        $notices = [];
        if (true === $allowUrlInclude) {
            $notices[] = 'PHP-URL-Includes sind aktiviert und sollten deaktiviert werden.';
        }
        if ('prod' === $this->environment && true === $displayErrors) {
            $notices[] = 'PHP-Fehlerausgabe ist in der Produktionsumgebung aktiviert.';
        }

        $sapi = $this->runtime->sapi();

        return [
            'php_version' => $this->safeVersion($this->runtime->version()),
            'php_sapi' => 1 === preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $sapi) ? $sapi : 'Nicht verfügbar',
            'php_limits' => $this->limitValues($values),
            'php_settings' => $this->settingValues($values),
            'php_extensions' => $this->extensionValues(),
            'notices' => $notices,
        ];
    }

    /** @param array<string, ?string> $values
     * @return array<string, string>
     */
    private function limitValues(array $values): array
    {
        $result = [];
        foreach (self::LIMITS as $label => $iniName) {
            $value = $values[$iniName] ?? null;
            $allowsUnit = in_array($iniName, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true);
            $pattern = $allowsUnit ? '/\A(?:-1|[0-9]{1,12}[KMG]?)\z/i' : '/\A(?:-1|[0-9]{1,12})\z/';
            $result[$label] = is_string($value) && 1 === preg_match($pattern, trim($value))
                ? trim($value)
                : 'Nicht verfügbar';
        }

        return $result;
    }

    /** @param array<string, ?string> $values
     * @return array<string, string>
     */
    private function settingValues(array $values): array
    {
        $result = [];
        foreach (self::SETTINGS as $label => $iniName) {
            $value = $values[$iniName] ?? null;
            if ('display_errors' === $iniName && is_string($value) && 'stderr' === strtolower(trim($value))) {
                $result[$label] = 'Separater Fehlerkanal';

                continue;
            }
            if ('zend.assertions' === $iniName) {
                $result[$label] = in_array($value, ['-1', '0', '1'], true) ? $value : 'Nicht verfügbar';

                continue;
            }

            $enabled = $this->booleanValue($value);
            $result[$label] = null === $enabled ? 'Nicht verfügbar' : ($enabled ? 'Aktiviert' : 'Deaktiviert');
        }

        return $result;
    }

    /** @return array<string, bool> */
    private function extensionValues(): array
    {
        $result = [];
        foreach (self::EXTENSIONS as $label => $extension) {
            $result[$label] = $this->runtime->extensionLoaded($extension);
        }

        return $result;
    }

    private function booleanValue(?string $value): ?bool
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return is_bool($parsed) ? $parsed : null;
    }

    private function safeVersion(string $version): string
    {
        return 1 === preg_match('/\A[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?\z/', $version)
            ? $version
            : 'Nicht verfügbar';
    }
}
