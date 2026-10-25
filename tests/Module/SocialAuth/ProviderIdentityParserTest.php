<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth;

use App\Module\SocialAuth\AuthenticationRejected;
use App\Module\SocialAuth\Provider;
use App\Module\SocialAuth\ProviderIdentityParser;
use PHPUnit\Framework\TestCase;

final class ProviderIdentityParserTest extends TestCase
{
    public function testOnlyVerifiedEmailIsAccepted(): void
    {
        $parser = new ProviderIdentityParser(1024);
        $identity = $parser->parse(Provider::GOOGLE, '{"subject":"stable-subject","email":"person@example.test","email_verified":true}');

        self::assertSame('stable-subject', $identity->subject);
        self::assertSame('person@example.test', $identity->verifiedEmail?->value);
    }

    public function testUnverifiedEmailIsDiscarded(): void
    {
        $parser = new ProviderIdentityParser(1024);
        $identity = $parser->parse(Provider::FACEBOOK, '{"subject":"stable-subject","email":"person@example.test","email_verified":false}');

        self::assertNull($identity->verifiedEmail);
    }

    public function testMalformedJsonAndMissingStableSubjectFailClosed(): void
    {
        $parser = new ProviderIdentityParser(1024);

        try {
            $parser->parse(Provider::TWITTER, '{"subject":');
            self::fail('Malformed JSON was accepted.');
        } catch (AuthenticationRejected $exception) {
            self::assertSame('Die Anmeldung konnte nicht abgeschlossen werden.', $exception->getMessage());
        }

        try {
            $parser->parse(Provider::TWITTER, '{"email":"person@example.test","email_verified":true}');
            self::fail('A profile without a stable subject was accepted.');
        } catch (AuthenticationRejected $exception) {
            self::assertSame('Die Anmeldung konnte nicht abgeschlossen werden.', $exception->getMessage());
        }
    }

    public function testOversizedResponseIsRejectedBeforeJsonParsing(): void
    {
        $parser = new ProviderIdentityParser(256);

        $this->expectException(AuthenticationRejected::class);
        $parser->parse(Provider::GOOGLE, str_repeat('{', 257));
    }

    public function testInvalidVerificationFlagAndEmailAreRejected(): void
    {
        $parser = new ProviderIdentityParser(1024);

        foreach ([
            '{"subject":"stable-subject","email":"person@example.test","email_verified":"true"}',
            '{"subject":"stable-subject","email":"not-an-email","email_verified":true}',
            '{"subject":"bad\u0000subject","email_verified":false}',
        ] as $response) {
            try {
                $parser->parse(Provider::GOOGLE, $response);
                self::fail('An invalid provider profile was accepted.');
            } catch (AuthenticationRejected $exception) {
                self::assertSame('Die Anmeldung konnte nicht abgeschlossen werden.', $exception->getMessage());
            }
        }
    }
}
