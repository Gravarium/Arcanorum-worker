<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Application;

use App\Module\Advertising\Application\ClickTokenSigner;
use PHPUnit\Framework\TestCase;

final class ClickTokenSignerTest extends TestCase
{
    public function testClickTokenExpiresAtItsSignedExpirationTimestamp(): void
    {
        $signer = new ClickTokenSigner(str_repeat('s', 64));
        $advertisementId = str_repeat('a', 32);
        $issuedAt = new \DateTimeImmutable('2026-10-26T13:00:00+00:00');
        $token = $signer->issue($advertisementId, $issuedAt);

        self::assertNotNull($signer->verify($advertisementId, $token, $issuedAt->modify('+299 seconds')));
        self::assertNull($signer->verify($advertisementId, $token, $issuedAt->modify('+300 seconds')));
    }
}
