<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

enum HostingCapability: string
{
    case PhpRuntime = 'runtime.php_supported';
    case CtypeExtension = 'runtime.ext_ctype';
    case IconvExtension = 'runtime.ext_iconv';
    case IntlExtension = 'runtime.ext_intl';
    case JsonExtension = 'runtime.ext_json';
    case MbstringExtension = 'runtime.ext_mbstring';
    case PdoExtension = 'runtime.ext_pdo';
    case PdoPgsqlExtension = 'runtime.ext_pdo_pgsql';
    case SodiumExtension = 'runtime.ext_sodium';
    case ZipExtension = 'runtime.ext_zip';
    case PostgreSqlServer18 = 'database.postgresql_18';
    case RedisServer8 = 'cache.redis_server_8';

    case PrivateStorage = 'boundary.private_storage';
    case DatabaseRoleSeparation = 'boundary.database_roles';
    case RedisPrivateEndpoint = 'boundary.redis_private_endpoint';
    case BackgroundWorker = 'boundary.background_worker';
    case ProcessIsolation = 'boundary.process_isolation';
    case OutboundNetworkPolicy = 'boundary.outbound_network_policy';
    case SecretStore = 'boundary.secret_store';
    case VerifiedBackupRestore = 'boundary.verified_backup_restore';

    public function label(): string
    {
        return match ($this) {
            self::PhpRuntime => 'PHP-Laufzeit',
            self::CtypeExtension => 'PHP-Erweiterung ctype',
            self::IconvExtension => 'PHP-Erweiterung iconv',
            self::IntlExtension => 'PHP-Erweiterung intl',
            self::JsonExtension => 'PHP-Erweiterung json',
            self::MbstringExtension => 'PHP-Erweiterung mbstring',
            self::PdoExtension => 'PDO',
            self::PdoPgsqlExtension => 'PDO PostgreSQL',
            self::SodiumExtension => 'PHP-Erweiterung sodium',
            self::ZipExtension => 'PHP-Erweiterung zip',
            self::PostgreSqlServer18 => 'PostgreSQL 18',
            self::RedisServer8 => 'Redis 8',
            self::PrivateStorage => 'Privater Dateispeicher',
            self::DatabaseRoleSeparation => 'Getrennte Datenbankrollen',
            self::RedisPrivateEndpoint => 'Private Redis-Anbindung',
            self::BackgroundWorker => 'Hintergrundprozess',
            self::ProcessIsolation => 'Prozessisolation',
            self::OutboundNetworkPolicy => 'Ausgehende Netzwerkregeln',
            self::SecretStore => 'Geheimnisspeicher',
            self::VerifiedBackupRestore => 'Geprüfte Sicherungswiederherstellung',
        };
    }

    public function operatorGuidance(): string
    {
        return match ($this) {
            self::PhpRuntime,
            self::CtypeExtension,
            self::IconvExtension,
            self::IntlExtension,
            self::JsonExtension,
            self::MbstringExtension,
            self::PdoExtension,
            self::PdoPgsqlExtension,
            self::SodiumExtension,
            self::ZipExtension => 'Diese Fähigkeit wird direkt in der aktiven PHP-Weblaufzeit geprüft.',
            self::PostgreSqlServer18 => 'Die Serverversion wird über die von der Anwendung verwendete Datenbankverbindung abgefragt.',
            self::RedisServer8 => 'Die Redis-Version wird über die konfigurierte Redis-Verbindung abgefragt.',
            self::PrivateStorage => 'Arcanorum prüft den Speicher außerhalb des öffentlichen Webpfads, seine Dateirechte und einen Schreibtest.',
            self::RedisPrivateEndpoint => 'Der Redis-Endpunkt wird ohne DNS-Auflösung nur als direkte private oder lokale IP-Adresse verifiziert. DNS-Namen, Unix-Sockets und Cluster bleiben unbekannt.',
            self::DatabaseRoleSeparation,
            self::BackgroundWorker,
            self::ProcessIsolation,
            self::OutboundNetworkPolicy,
            self::SecretStore,
            self::VerifiedBackupRestore => 'Diese Fähigkeit liegt beim Hoster oder Betriebssystem. Arcanorum kann sie in dieser Installation nicht unabhängig prüfen; sie bleibt unbekannt und wird nicht aktiviert.',
        };
    }
}
