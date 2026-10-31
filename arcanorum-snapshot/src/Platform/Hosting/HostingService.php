<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

enum HostingService: string
{
    case Database = 'database';
    case Cache = 'cache';
    case Queue = 'queue';
    case Storage = 'storage';
    case Mail = 'mail';
    case Secrets = 'secrets';
    case ProviderCommunication = 'provider_communication';
    case WebServer = 'web_server';
}
