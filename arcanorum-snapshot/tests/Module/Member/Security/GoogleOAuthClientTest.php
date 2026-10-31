<?php

declare(strict_types=1);

namespace App\Tests\Module\Member\Security;

use App\Module\Member\Security\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleOAuthClientTest extends TestCase
{
    private const string CLIENT_ID = 'arcanorum-test-client.apps.googleusercontent.com';
    private const string CLIENT_SECRET = 'test-google-oauth-secret-123456';
    private const string BASE_URL = 'https://arcanorum.example';

    public function testAuthorizationUsesFixedGoogleEndpointAndPkce(): void
    {
        $client = new GoogleOAuthClient(new MockHttpClient([]), self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);

        self::assertTrue($client->isConfigured());
        $url = $client->authorizationUrl(str_repeat('a', 43), str_repeat('b', 43));
        $parts = parse_url($url);
        self::assertIsArray($parts);
        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('accounts.google.com', $parts['host'] ?? null);
        self::assertSame('/o/oauth2/v2/auth', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);

        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('openid email profile', $query['scope'] ?? null);
        self::assertSame(str_repeat('a', 43), $query['state'] ?? null);
        self::assertSame(str_repeat('b', 43), $query['code_challenge'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame('https://arcanorum.example/konto/sozial/google/callback', $query['redirect_uri'] ?? null);
    }

    public function testFetchesOnlyFixedEndpointsAndRequiresVerifiedEmail(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            self::assertSame(0, $options['max_redirects'] ?? null);
            self::assertSame(8.0, $options['max_duration'] ?? null);
            $headers = $options['headers'] ?? [];
            self::assertIsArray($headers);
            self::assertContains('Accept: application/json', $headers);
            self::assertContains('Accept-Encoding: identity', $headers);

            if ('POST' === $method) {
                self::assertSame('https://oauth2.googleapis.com/token', $url);
                self::assertStringNotContainsString(self::CLIENT_SECRET, $url);

                return self::jsonResponse('{"access_token":"test-access-token","token_type":"Bearer"}');
            }

            self::assertSame('GET', $method);
            self::assertSame('https://openidconnect.googleapis.com/v1/userinfo', $url);
            self::assertContains('Authorization: Bearer test-access-token', $headers);

            return self::jsonResponse('{"sub":"google-subject-123","email":"Member@example.test","email_verified":true,"name":"Example Member"}');
        });
        $google = new GoogleOAuthClient($client, self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);

        $profile = $google->fetchVerifiedProfile('authorization.code', str_repeat('v', 43));

        self::assertSame('google-subject-123', $profile->subject);
        self::assertSame('member@example.test', $profile->email);
        self::assertSame('Example Member', $profile->displayName);
        self::assertCount(2, $requests);
    }

    public function testRejectsUnverifiedEmailWithoutReturningProfile(): void
    {
        $client = new MockHttpClient([
            self::jsonResponse('{"access_token":"test-access-token","token_type":"Bearer"}'),
            self::jsonResponse('{"sub":"google-subject-123","email":"member@example.test","email_verified":false}'),
        ]);
        $google = new GoogleOAuthClient($client, self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);

        $this->expectException(\RuntimeException::class);
        $google->fetchVerifiedProfile('authorization.code', str_repeat('v', 43));
    }

    public function testRejectsDuplicateJsonKeysInTokenAndIdentityResponses(): void
    {
        $responses = [
            [
                '{"access_token":"first-token","access_token":"second-token","token_type":"Bearer"}',
                '{"sub":"google-subject-123","email":"member@example.test","email_verified":true,"name":"Member"}',
                1,
            ],
            [
                '{"access_token":"test-access-token","token_type":"Bearer"}',
                '{"sub":"google-subject-123","email":"member@example.test","email_verified":true,"\\u0065mail_verified":false,"name":"Member"}',
                2,
            ],
            [
                '{"access_token":"test-access-token","token_type":"Bearer"}',
                '{"sub":"google-subject-123","email":"member@example.test","email_verified":true,"name":"Member","metadata":{"flags":{"trusted":true,"trusted":false}}}',
                2,
            ],
        ];

        foreach ($responses as [$tokenResponse, $profileResponse, $expectedRequests]) {
            $client = new MockHttpClient([
                self::jsonResponse($tokenResponse),
                self::jsonResponse($profileResponse),
            ]);
            $google = new GoogleOAuthClient($client, self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);

            try {
                $google->fetchVerifiedProfile('authorization.code', str_repeat('v', 43));
                self::fail('Google OAuth responses with duplicate JSON keys must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Google hat ungültige oder mehrdeutige JSON-Daten geliefert.', $exception->getMessage());
                self::assertSame($expectedRequests, $client->getRequestsCount());
            }
        }
    }

    public function testRejectsLargeResponsesAndRedirectsBeforeUsingThem(): void
    {
        $tooLarge = new MockHttpClient([
            new MockResponse('{"access_token":"'.str_repeat('x', 17000).'","token_type":"Bearer"}', [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json'],
            ]),
        ]);
        $google = new GoogleOAuthClient($tooLarge, self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);
        try {
            $google->fetchVerifiedProfile('authorization.code', str_repeat('v', 43));
            self::fail('Oversized provider data must be rejected.');
        } catch (\RuntimeException) {
            self::assertSame(1, $tooLarge->getRequestsCount());
        }

        $redirect = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location: https://attacker.example/token'],
            ]),
        ]);
        $google = new GoogleOAuthClient($redirect, self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);
        try {
            $google->fetchVerifiedProfile('authorization.code', str_repeat('v', 43));
            self::fail('Provider redirects must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('attacker.example', $exception->getMessage());
        }
    }

    public function testRejectsUnsafeCallbackConfigurationAndInvalidExchangeInputs(): void
    {
        $unsafe = new GoogleOAuthClient(new MockHttpClient([]), self::CLIENT_ID, self::CLIENT_SECRET, 'http://arcanorum.example');
        self::assertFalse($unsafe->isConfigured());
        $this->expectException(\LogicException::class);
        $unsafe->authorizationUrl(str_repeat('a', 43), str_repeat('b', 43));
    }

    private static function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);
    }
}
