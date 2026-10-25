<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth\Support;

use App\Module\SocialAuth\Provider;
use App\Module\SocialAuth\ProviderIdentity;
use App\Module\SocialAuth\SocialIdentityRepository;

final class FakeIdentityRepository implements SocialIdentityRepository
{
    /** @var array<string, string> */
    public array $owners = [];

    /** @var array<string, ProviderIdentity> */
    public array $registered = [];

    /** @var array<string, list<string>> */
    public array $identitiesByAccount = [];

    /** @var array<string, int> */
    public array $additionalAuthenticationMethods = [];

    public int $registrationCalls = 0;

    public int $linkCalls = 0;

    public function accountIdFor(Provider $provider, string $subject): ?string
    {
        return $this->owners[$this->key($provider, $subject)] ?? null;
    }

    public function registerAccount(ProviderIdentity $identity): ?string
    {
        $this->registrationCalls++;
        $key = $this->key($identity->provider, $identity->subject);
        if (isset($this->owners[$key])) {
            return null;
        }

        $accountId = 'new-account';
        $this->owners[$key] = $accountId;
        $this->registered[$key] = $identity;
        $this->identitiesByAccount[$accountId][] = $key;

        return $accountId;
    }

    public function linkToAccount(string $accountId, ProviderIdentity $identity): bool
    {
        $this->linkCalls++;
        $key = $this->key($identity->provider, $identity->subject);
        if (isset($this->owners[$key])) {
            return false;
        }

        $this->owners[$key] = $accountId;
        $this->identitiesByAccount[$accountId][] = $key;

        return true;
    }

    public function unlinkIfAnotherMethodRemains(string $accountId, Provider $provider): bool
    {
        foreach ($this->identitiesByAccount[$accountId] ?? [] as $key) {
            if (!str_starts_with($key, $provider->value . ':')) {
                continue;
            }

            $methodCount = count($this->identitiesByAccount[$accountId] ?? []) + ($this->additionalAuthenticationMethods[$accountId] ?? 0);
            if ($methodCount <= 1) {
                return false;
            }

            unset($this->owners[$key]);
            $this->identitiesByAccount[$accountId] = array_values(array_filter(
                $this->identitiesByAccount[$accountId],
                static fn (string $candidate): bool => $candidate !== $key,
            ));

            return true;
        }

        return false;
    }

    public function seed(Provider $provider, string $subject, string $accountId): void
    {
        $key = $this->key($provider, $subject);
        $this->owners[$key] = $accountId;
        $this->identitiesByAccount[$accountId][] = $key;
    }

    public function registrationCallCount(): int
    {
        return $this->registrationCalls;
    }

    public function linkCallCount(): int
    {
        return $this->linkCalls;
    }

    private function key(Provider $provider, string $subject): string
    {
        return $provider->value . ':' . $subject;
    }
}
