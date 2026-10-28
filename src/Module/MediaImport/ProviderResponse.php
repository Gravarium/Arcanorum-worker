<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ProviderResponse
{
    public const int MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        public int $statusCode,
        public string $contentType,
        public string $body,
        public bool $redirected = false,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->redirected || strlen($this->body) > self::MAX_BODY_BYTES) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        $failure = match ($this->statusCode) {
            401 => ProviderError::Unauthorized,
            403 => ProviderError::Forbidden,
            404 => ProviderError::NotFound,
            429 => ProviderError::RateLimited,
            default => $this->statusCode >= 500 ? ProviderError::Unavailable : null,
        };
        if (null !== $failure) {
            throw new ProviderException($failure, ProviderError::RateLimited === $failure ? max(0, min(86400, $this->retryAfterSeconds ?? 60)) : null);
        }

        if ($this->statusCode < 200 || $this->statusCode >= 300
            || 1 !== preg_match('/\Aapplication\/(?:[a-z0-9.+-]+\+)?json(?:\s*;\s*charset=utf-8)?\z/i', trim($this->contentType))) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        if (!is_array($decoded) || [] === $decoded || array_is_list($decoded)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }
            $payload[$key] = $value;
        }

        return $payload;
    }
}
