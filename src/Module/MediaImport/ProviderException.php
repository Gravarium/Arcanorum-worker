<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly ProviderError $failure,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(match ($failure) {
            ProviderError::Unauthorized => 'The provider rejected authentication.',
            ProviderError::Forbidden => 'The provider denied this operation.',
            ProviderError::NotFound => 'The provider source was not found.',
            ProviderError::RateLimited => 'The provider rate limit was reached.',
            ProviderError::Unavailable => 'The provider is temporarily unavailable.',
            ProviderError::InvalidResponse => 'The provider response is invalid.',
            ProviderError::Unsupported => 'The provider does not support this operation.',
        });
    }
}
