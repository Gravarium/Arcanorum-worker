<?php

declare(strict_types=1);

namespace App\Tests\Module\Member\Security;

use App\Module\Member\Security\BoundedOAuthJsonClient;
use App\Module\Member\Security\TwitterOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwitterOAuthClientTest extends TestCase
{
    private const string CLIENT_ID = 'arcanorum-twitter-client';
    private const string CLIENT_SECRET = 'test-twitter-oauth-secret-2026';
    private const string BASE_URL = 'https://arcanorum.example';

    public function testAuthorizationUsesFixedEndpointAndPkce(): void
    {
        $client = $this->client(new MockHttpClient([]));

        self::assertTrue($client->isConfigured());
        $parts = parse_url($client->authorizationUrl(str_repeat('s', 43), str_repeat('c', 43)));
        self::assertIsArray($parts);
        self::assertSame('https', $parts['scheme'] ?? null);
        self::assertSame('twitter.com', $parts['host'] ?? null);
        self::assertSame('/i/oauth2/authorize', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('users.read', $query['scope'] ?? null);
        self::assertSame(str_repeat('s', 43), $query['state'] ?? null);
        self::assertSame(str_repeat('c', 43), $query['code_challenge'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame('https://arcanorum.example/konto/sozial/twitter/callback', $query['redirect_uri'] ?? null);
    }

    public function testProfileRequiresStrictEnvelopeAndDoesNotAssumeEmail(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            self::assertSame(0, $options['max_redirects'] ?? null);
            self::assertSame(8.0, $options['max_duration'] ?? null);
            self::assertStringNotContainsString(self::CLIENT_SECRET, $url);

            if ('POST' === $method) {
                self::assertSame('https://api.twitter.com/2/oauth2/token', $url);

                return self::jsonResponse('{"access_token":"twitter-token","token_type":"bearer"}');
            }

            self::assertSame('GET', $method);
            self::assertSame('https://api.twitter.com/2/users/me?user.fields=name,username', $url);
            $headers = $options['headers'] ?? [];
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer twitter-token', $headers);

            return self::jsonResponse('{"data":{"id":"9988776655","name":"Twitter Member","username":"member"}}');
        });
        $client = $this->client($httpClient);

        $profile = $client->fetchProfile('authorization.code', str_repeat('v', 43));

        self::assertSame('9988776655', $profile->subject);
        self::assertNull($profile->email);
        self::assertFalse($profile->emailVerified);
        self::assertSame('Twitter Member', $profile->displayName);
        self::assertCount(2, $requests);
    }

    public function testRejectsDuplicateKeysInsideProviderEnvelope(): void
    {
        $httpClient = new MockHttpClient([
            self::jsonResponse('{"access_token":"token","token_type":"Bearer"}'),
            self::jsonResponse('{"data":{"id":"9988776655","id":"attacker"}}'),
        ]);

        try {
            $this->client($httpClient)->fetchProfile('authorization.code', str_repeat('v', 43));
            self::fail('Duplicate nested provider keys must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Der Anmeldedienst hat ungültige oder mehrdeutige JSON-Daten geliefert.', $exception->getMessage());
            self::assertSame(2, $httpClient->getRequestsCount());
        }
    }

    private function client(MockHttpClient $httpClient): TwitterOAuthClient
    {
        return new TwitterOAuthClient(new BoundedOAuthJsonClient($httpClient), self::CLIENT_ID, self::CLIENT_SECRET, self::BASE_URL);
    }

    private static function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);
    }
}
