<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\DatabaseLoginRateLimiter;
use App\Platform\Security\RateLimitKeyFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class DatabaseLoginRateLimiterTest extends KernelTestCase
{
    private ?Connection $connection = null;
    private ?DatabaseLoginRateLimiter $limiter = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM authentication_login_rate_limits');
        $this->limiter = new DatabaseLoginRateLimiter($this->connection, new RateLimitKeyFactory('test-login-rate-limit-secret'));
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isConnected()) {
            $this->connection->executeStatement('DELETE FROM authentication_login_rate_limits');
        }

        parent::tearDown();
    }

    public function testUsernameAndIpLimitIsSharedAcrossLimiterInstances(): void
    {
        $request = $this->loginRequest('admin_login', 'Administrator', '192.0.2.10');
        $secondProcess = new DatabaseLoginRateLimiter($this->connection(), new RateLimitKeyFactory('test-login-rate-limit-secret'));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $limit = 1 === $attempt % 2 ? $this->limiter()->consume($request) : $secondProcess->consume($request);
            self::assertInstanceOf(RateLimit::class, $limit);
            self::assertTrue($limit->isAccepted());
        }

        self::assertFalse($secondProcess->consume($request)->isAccepted());
    }

    public function testIpLimitCoversManyDifferentUsernamesInOneFirewall(): void
    {
        for ($attempt = 0; $attempt < 25; ++$attempt) {
            self::assertTrue($this->limiter()->consume($this->loginRequest('admin_login', 'person-'.$attempt, '192.0.2.20'))->isAccepted());
        }

        self::assertFalse($this->limiter()->consume($this->loginRequest('admin_login', 'another-person', '192.0.2.20'))->isAccepted());
    }

    public function testAdminAndMemberLoginUseSeparateLimiterScopes(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::assertTrue($this->limiter()->consume($this->loginRequest('admin_login', 'same-user', '192.0.2.21'))->isAccepted());
        }

        self::assertTrue($this->limiter()->consume($this->loginRequest('member_login', 'same-user', '192.0.2.21'))->isAccepted());
    }

    public function testSuccessfulAuthenticationReleasesOnlyItsReservedAttempt(): void
    {
        $request = $this->loginRequest('admin_login', 'Administrator', '192.0.2.30');
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            self::assertTrue($this->limiter()->consume($request)->isAccepted());
        }

        self::assertTrue($this->limiter()->consume($request)->isAccepted());
        $this->limiter()->reset($request);
        self::assertTrue($this->limiter()->consume($request)->isAccepted());
        self::assertFalse($this->limiter()->consume($request)->isAccepted());
    }

    public function testUnrelatedSuccessfulAuthenticationCannotClearLoginFailures(): void
    {
        $loginRequest = $this->loginRequest('member_login', 'person@example.test', '192.0.2.31');
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            self::assertTrue($this->limiter()->consume($loginRequest)->isAccepted());
        }

        $registrationRequest = $this->loginRequest('member_register', 'person@example.test', '192.0.2.31');
        $this->limiter()->reset($registrationRequest);
        self::assertTrue($this->limiter()->consume($loginRequest)->isAccepted());
        self::assertFalse($this->limiter()->consume($loginRequest)->isAccepted());
    }

    public function testUsernameCaseDoesNotCreateSeparateBuckets(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $username = 0 === $attempt % 2 ? 'Administrator' : 'administrator';
            self::assertTrue($this->limiter()->consume($this->loginRequest('admin_login', $username, '192.0.2.40'))->isAccepted());
        }

        self::assertFalse($this->limiter()->consume($this->loginRequest('admin_login', 'ADMINISTRATOR', '192.0.2.40'))->isAccepted());
    }

    public function testStoredBucketKeysDoNotContainUsernamesOrAddresses(): void
    {
        $username = 'private-user@example.test';
        $address = '198.51.100.48';
        $this->limiter()->consume($this->loginRequest('member_login', $username, $address));
        $keys = $this->connection()->fetchFirstColumn('SELECT key_hash FROM authentication_login_rate_limits ORDER BY key_hash');

        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A persisted rate-limit key must be a string.');
            }
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $key);
            self::assertStringNotContainsString($username, $key);
            self::assertStringNotContainsString($address, $key);
        }
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('The test database connection was not initialized.');
    }

    private function limiter(): DatabaseLoginRateLimiter
    {
        return $this->limiter ?? throw new \LogicException('The login rate limiter was not initialized.');
    }

    private function loginRequest(string $route, string $username, string $address): Request
    {
        $path = 'admin_login' === $route ? '/admin/login' : '/konto/anmelden';
        $request = Request::create($path, Request::METHOD_POST, server: ['REMOTE_ADDR' => $address]);
        $request->attributes->set('_route', $route);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return $request;
    }
}
