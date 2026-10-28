<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum ProviderError: string
{
    case Unauthorized = 'provider_unauthorized';
    case Forbidden = 'provider_forbidden';
    case NotFound = 'provider_not_found';
    case RateLimited = 'provider_rate_limited';
    case Unavailable = 'provider_unavailable';
    case InvalidResponse = 'provider_invalid_response';
    case Unsupported = 'provider_unsupported_operation';
}
