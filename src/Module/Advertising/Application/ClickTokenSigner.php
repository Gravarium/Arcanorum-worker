<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ClickTokenSigner
{
    private const int TOKEN_LIFETIME_SECONDS = 300;

    public function __construct(#[Autowire(env: 'APP_SECRET')] private string $secret)
    {
        if (strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('Advertising click signing secret must contain at least 32 bytes.');
        }
    }

    public function issue(string $advertisementId, \DateTimeImmutable $issuedAt): string
    {
        $expires = $issuedAt->getTimestamp() + self::TOKEN_LIFETIME_SECONDS;
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $advertisementId.'|'.$expires.'|'.$nonce, $this->secret);

        return $expires.'.'.$nonce.'.'.$signature;
    }

    public function verify(string $advertisementId, string $token, \DateTimeImmutable $now): ?string
    {
        if (strlen($token) > 120) {
            return null;
        }

        $parts = explode('.', $token);
        if (3 !== count($parts) || 1 !== preg_match('/\A[0-9]{10}\z/D', $parts[0]) || 1 !== preg_match('/\A[a-f0-9]{32}\z/D', $parts[1]) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $parts[2])) {
            return null;
        }

        $expires = (int) $parts[0];
        if ($expires < $now->getTimestamp() || $expires > $now->getTimestamp() + self::TOKEN_LIFETIME_SECONDS) {
            return null;
        }

        $expected = hash_hmac('sha256', $advertisementId.'|'.$parts[0].'|'.$parts[1], $this->secret);
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }

        return hash('sha256', $parts[0].'.'.$parts[1]);
    }
}
