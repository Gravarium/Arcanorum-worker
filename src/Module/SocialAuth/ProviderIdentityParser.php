<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class ProviderIdentityParser
{
    public function __construct(private int $maxResponseBytes)
    {
        if ($maxResponseBytes < 256 || $maxResponseBytes > 65_536) {
            throw new AuthenticationRejected();
        }
    }

    public function parse(Provider $provider, string $response): ProviderIdentity
    {
        if ('' === $response || strlen($response) > $this->maxResponseBytes) {
            throw new AuthenticationRejected();
        }

        try {
            $decoded = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationRejected();
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AuthenticationRejected();
        }

        $subject = $decoded['subject'] ?? null;
        $email = $decoded['email'] ?? null;
        $emailVerified = $decoded['email_verified'] ?? false;

        if (!is_string($subject) || !is_bool($emailVerified) || (null !== $email && !is_string($email))) {
            throw new AuthenticationRejected();
        }

        try {
            $verifiedEmail = null === $email ? null : VerifiedEmail::fromProvider($email, $emailVerified);

            return new ProviderIdentity($provider, $subject, $verifiedEmail);
        } catch (\Throwable) {
            throw new AuthenticationRejected();
        }
    }
}
