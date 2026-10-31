<?php

declare(strict_types=1);

namespace App\Tests\Module\Member\Security;

use App\Module\Member\Security\BoundedOAuthJsonClient;
use App\Module\Member\Security\FacebookOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FacebookOAuthClientTest extends TestCase
{
    private const string CLIENT_ID = '123456789012345';
    private const string CLIENT_SECRET = 'test-facebook-oauth-secret-2026';
    private const string BASE_URL = 'https://arcanorum.example';

    public function testAuthorizationUsesFixedEndpointAndPkce(): void
    {
        $client = $this->client(new MockHttpClient([]));

        self::assertTrue($client->isConfigured());
        $parts = parse_url($client->authorizationUrl(str_repeat('s', 43), str_repeat('c', 43)));
        self::assertIsArray($parts);
        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('www.facebook.com', $parts['host'] ?? null);
        self::assertSame('/v24.0/dialog/oauth', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('email,public_profile', $query['scope'] ?? null);
        self::assertSame(str_repeat('s', 43), $query['state'] ?? null);
        self::assertSame(str_repeat('c', 43), $query['code_challenge'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame('https://arcanorum.example/konto/sozial/facebook/callback', $query['redirect_uri'] ?? null);
    }

    public function testProfileIsBoundedAndFacebookEmailIsNeverTreatedAsVerified(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            self::assertSame(0, $options['max_redirects'] ?? null);
            self::assertSame(8.0, $options['max_duration'] ?? null);
            self::assertStringNotContainsString(self::CLIENT_SECRET, $url);

            if ('POST' === $method) {
                self::assertSame('https://graph.facebook.com/v24.0/oauth/access_token', $url);

                return self::jsonResponse('{"access_token":"facebook-token","token_type":"Bearer"}');
            }

            self::assertSame('GET', $method);
            self::assertSame('https://graph.facebook.com/v24.0/me?fields=id,name,email', $url);
            $headers = $options['headers'] ?? [];
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer facebook-token', $headers);

            return self::jsonResponse('{"id":"112233445566","name":"Facebook Member","email":"member@example.test"}');
        });
        $client = $this->client($httpClient);

        $profile = $client->fetchProfile('authorization.code', str_repeat('v', 43));

        self::assertSame('112233445566', $profile->subject);
        self::assertSame('member@example.test', $profile->email);
        self::assertFalse($profile->emailVerified);
        self::assertSame('Facebook Member', $profile->displayName);
        self::assertCount(2, $requests);
    }

    public function testRejectsDuplicateKeysAndUnsafeBaseUrl(): void
    {
        $duplicate = new MockHttpClient([
            self::jsonResponse('{"access_token":"token","token_type":"Bearer"}'),
            self::jsonResponse('{"id":"112233445566","name":"First","name":"Second"}'),
        ]);

        try {
            $this->client($duplicate)->fetchProfile('authorization.code', str_repeat('v', 43));
            self::fail('Duplicate provider keys must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Der Anmeldedienst hat ungültige oder mehrdeutige JSON-Daten geliefert.', $exception->getMessage());
            self::assertSame(2, $duplicate->getRequestsCount());
        }

        $unsafe = new FacebookOAuthClient(new BoundedOAuthJsonClient(new MockHttpClient([])), self::CLIENT_ID, self::CLIENT_SECRET, 'http://arcanorum.example');
        self::assertFalse($unsafe->isConfigured());
    }

    private function client(MockHttpClient $httpClient): FacebookOAuthClient
    {
        return new FacebookOAuthClient(new BoundedOAuthJsonClient($httpClient), self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);
    }

    private static function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);
    }
}
