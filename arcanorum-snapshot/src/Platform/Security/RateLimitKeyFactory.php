<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class RateLimitKeyFactory
{
    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    public function forIp(Request $request, string $purpose): string
    {
        return hash_hmac(
            'sha256',
            'ip'."\0".$purpose."\0".($request->getClientIp() ?? 'unknown'),
            $this->secret,
        );
    }

    public function forIdentity(string $identifier, string $purpose): string
    {
        return hash_hmac('sha256', 'identity'."\0".$purpose."\0".$identifier, $this->secret);
    }

    public function forIdentityAndIp(string $identifier, Request $request, string $purpose): string
    {
        return hash_hmac(
            'sha256',
            'identity-ip'."\0".$purpose."\0".$identifier."\0".($request->getClientIp() ?? 'unknown'),
            $this->secret,
        );
    }
}
