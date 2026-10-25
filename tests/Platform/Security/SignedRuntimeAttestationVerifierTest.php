<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\ReplayProtectionDecision;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationChallenge;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationPolicy;
use App\Platform\Security\RuntimeAttestationReplayProtectionInterface;
use App\Platform\Security\RuntimeAttestationReport;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationRequestGuardSubscriber;
use App\Platform\Security\RuntimeAttestationRequestPolicy;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\RuntimeAttestationUnavailable;
use App\Platform\Security\SignedRuntimeAttestationVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SignedRuntimeAttestationVerifierTest extends TestCase
{
    private const string TARGET_ID = 'site-123';

    private const string POLICY_ID = 'arcanorum-hosting-control-matrix';

    private const string POLICY_VERSION = '1.0.0';

    private const string POLICY_DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string AUTH_CONTROL = 'INV-AUTH-001';

    private const string CONFIG_CONTROL = 'INV-WEBSERVER-CONFIG-001';

    private const int NOW = 1000;

    private string $secretKey = '';

    private string $publicKey = '';

    private string $nonce = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** @var array<string, array{public_key: string, attestor_id: string, attestor_version: string}> */
    private array $trustedKeys = [];

    private ?RuntimeAttestationPolicy $policy = null;

    protected function setUp(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        $this->publicKey = sodium_crypto_sign_publickey($keyPair);
        $this->trustedKeys = [
            'attestor-key-1' => [
                'public_key' => self::base64UrlEncode($this->publicKey),
                'attestor_id' => 'external-monitor',
                'attestor_version' => '2.3.0',
            ],
        ];
        $this->policy = new RuntimeAttestationPolicy(
            self::TARGET_ID,
            self::POLICY_ID,
            self::POLICY_VERSION,
            self::POLICY_DIGEST,
            [self::AUTH_CONTROL, self::CONFIG_CONTROL],
        );
    }

    public function testFreshBoundSignatureIsAcceptedOnlyAfterRemoteReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection
            ->expects($this->once())
            ->method('consume')
            ->with(
                self::TARGET_ID,
                $this->nonce,
                4,
                self::equalTo(new \DateTimeImmutable('@1040')),
            )
            ->willReturn(ReplayProtectionDecision::Accepted);

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            $this->signedAttestation(),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Verified, $report->status);
        self::assertEquals(new \DateTimeImmutable('@'.self::NOW), $report->checkedAt);
        self::assertEquals(new \DateTimeImmutable('@1040'), $report->expiresAt);
        self::assertSame(RuntimeAttestationStatus::Verified, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Verified, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testMismatchedChallengeFailsBeforeRemoteReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            $this->signedAttestation(),
            $this->policy(),
            $this->trustedKeys,
            self::base64UrlEncode(str_repeat("\x01", RuntimeAttestationChallenge::BYTE_LENGTH)),
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame(RuntimeAttestationStatus::Failed, $report->controlStatuses[self::AUTH_CONTROL]);
    }

    public function testMalformedExpectedChallengeRemainsUnknownWithoutReplayCall(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        foreach (['', str_repeat('A', 32), str_repeat('B', 43)] as $invalidChallenge) {
            $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
                $this->signedAttestation(),
                $this->policy(),
                $this->trustedKeys,
                $invalidChallenge,
                new \DateTimeImmutable('@'.self::NOW),
            );

            self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
            self::assertSame('challenge_invalid', $report->reason);
            self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::AUTH_CONTROL]);
        }
    }

    public function testMissingAttestationIsUnknownAndDoesNotUseReplayStore(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            null,
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
        self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::AUTH_CONTROL]);
    }

    public function testMissingTrustAnchorOrRemoteReplayProtectionNeverVerifies(): void
    {
        $verifier = new SignedRuntimeAttestationVerifier();

        $withoutKeys = $verifier->verify(
            $this->signedAttestation(),
            $this->policy(),
            [],
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );
        $withoutReplayStore = $verifier->verify(
            $this->signedAttestation(),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Unknown, $withoutKeys->status);
        self::assertSame(RuntimeAttestationStatus::Unknown, $withoutReplayStore->status);
        self::assertSame(RuntimeAttestationStatus::Unknown, $withoutReplayStore->controlStatuses[self::AUTH_CONTROL]);
    }

    public function testMalformedTrustedKeyConfigurationRemainsUnknownWithoutCallingReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');
        $verifier = new SignedRuntimeAttestationVerifier($replayProtection);

        $invalidTrustedKeySets = [
            ['attestor-key-1' => 'malformed-entry'],
            ['attestor-key-1' => ['attestor_id' => 'external-monitor', 'attestor_version' => '2.3.0']],
            ['attestor-key-1' => [
                'public_key' => null,
                'attestor_id' => 'external-monitor',
                'attestor_version' => '2.3.0',
            ]],
            [[
                'public_key' => self::base64UrlEncode($this->publicKey),
                'attestor_id' => 'external-monitor',
                'attestor_version' => '2.3.0',
            ]],
            ['attestor-key-1' => [
                'public_key' => self::base64UrlEncode($this->publicKey),
                'attestor_id' => 'external-monitor',
                'attestor_version' => '2.3.0',
                'unexpected_metadata' => true,
            ]],
            ['attestor-key-1' => [
                'public_key' => self::base64UrlEncode($this->publicKey),
                'attestor_id' => "external\nmonitor",
                'attestor_version' => '2.3.0',
            ]],
        ];

        foreach ($invalidTrustedKeySets as $trustedKeys) {
            $report = $verifier->verify(
                $this->signedAttestation(),
                $this->policy(),
                $trustedKeys,
                $this->nonce,
                new \DateTimeImmutable('@'.self::NOW),
            );

            self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
            self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::AUTH_CONTROL]);
        }
    }

    public function testMalformedUnselectedTrustedKeyInvalidatesTheWholeSetBeforeReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $trustedKeys = $this->trustedKeys;
        $trustedKeys['unused-attestor-key'] = [
            'public_key' => 'not-a-canonical-ed25519-public-key',
            'attestor_id' => 'unused-monitor',
            'attestor_version' => '1.0.0',
        ];

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            $this->signedAttestation(),
            $this->policy(),
            $trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
        self::assertSame('trust_anchor_invalid', $report->reason);
        self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::AUTH_CONTROL]);
    }

    public function testInvalidSignatureFailsAllControls(): void
    {
        $parts = explode('.', $this->signedAttestation());
        $parts[2] = self::base64UrlEncode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));

        $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            implode('.', $parts),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame(RuntimeAttestationStatus::Failed, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Failed, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testDuplicateSignedClaimsAreRejectedBeforeReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            $this->signedAttestationWithDuplicateTargetId(),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame('claims_invalid', $report->reason);
    }

    public function testUnknownSignedClaimIsRejectedBeforeReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            $this->signedAttestation(['future_downgrade_mode' => 'verified']),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame('claims_invalid', $report->reason);
        self::assertSame(RuntimeAttestationStatus::Failed, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Failed, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testWrongTargetPolicyOrAttestorBindingFailsClosed(): void
    {
        $invalidBindings = [
            ['target_id' => 'another-site'],
            ['policy_id' => 'another-policy'],
            ['policy_version' => '2.0.0'],
            ['policy_digest' => str_repeat('b', 64)],
            ['attestor_id' => 'another-attestor'],
            ['attestor_version' => '2.4.0'],
        ];

        foreach ($invalidBindings as $bindingOverrides) {
            $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
                $this->signedAttestation($bindingOverrides),
                $this->policy(),
                $this->trustedKeys,
                $this->nonce,
                new \DateTimeImmutable('@'.self::NOW),
            );

            self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        }
    }

    public function testExpiredOrFutureAttestationIsUnknown(): void
    {
        $expired = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation(['issued_at' => 940, 'expires_at' => 999]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );
        $future = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation(['issued_at' => 1001, 'expires_at' => 1040]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Unknown, $expired->status);
        self::assertSame(RuntimeAttestationStatus::Unknown, $future->status);
    }

    public function testOversizedAttestationLifetimeFails(): void
    {
        $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation(['issued_at' => 900, 'expires_at' => 1001]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
    }

    public function testMissingRequiredControlRemainsUnknownWhileOtherControlCanBeVerified(): void
    {
        $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation(['control_results' => [self::AUTH_CONTROL => 'verified']]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
        self::assertSame(RuntimeAttestationStatus::Verified, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testMissingControlResultSetRemainsUnknown(): void
    {
        $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation(['control_results' => null]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
        self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testFailedControlIsDistinctFromUnknown(): void
    {
        $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation([
                'control_results' => [
                    self::AUTH_CONTROL => 'failed',
                    self::CONFIG_CONTROL => 'unknown',
                ],
            ]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame(RuntimeAttestationStatus::Failed, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Unknown, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testNotAvailableControlRemainsDistinctAndBlocksOverallReport(): void
    {
        $report = $this->verifier(ReplayProtectionDecision::Accepted)->verify(
            $this->signedAttestation([
                'control_results' => [
                    self::AUTH_CONTROL => 'not_available',
                    self::CONFIG_CONTROL => 'verified',
                ],
            ]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::NotAvailable, $report->status);
        self::assertSame(RuntimeAttestationStatus::NotAvailable, $report->controlStatuses[self::AUTH_CONTROL]);
        self::assertSame(RuntimeAttestationStatus::Verified, $report->controlStatuses[self::CONFIG_CONTROL]);
    }

    public function testReplayOrSequenceRollbackFailsAndUnavailableReplayStoreIsUnknown(): void
    {
        $replayed = $this->verifier(ReplayProtectionDecision::ReplayDetected)->verify(
            $this->signedAttestation(),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );
        $unavailable = $this->verifier(ReplayProtectionDecision::Unavailable)->verify(
            $this->signedAttestation(),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $replayed->status);
        self::assertSame(RuntimeAttestationStatus::Unknown, $unavailable->status);
    }

    public function testUnlistedControlIsRejectedAfterConsumingTheSignedChallenge(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection
            ->expects($this->once())
            ->method('consume')
            ->willReturn(ReplayProtectionDecision::Accepted);

        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            $this->signedAttestation([
                'control_results' => [
                    self::AUTH_CONTROL => 'verified',
                    self::CONFIG_CONTROL => 'verified',
                    'INV-UNLISTED-999' => 'verified',
                ],
            ]),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            new \DateTimeImmutable('@'.self::NOW),
        );

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
    }

    public function testLocalRejectionRemainsFailedAtTheActionBoundary(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $freshClaims = [
            'issued_at' => $now->getTimestamp() - 1,
            'expires_at' => $now->getTimestamp() + 30,
        ];
        $parts = explode('.', $this->signedAttestation($freshClaims));
        $parts[2] = self::base64UrlEncode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
        $invalidReports = [
            'malformed-jws',
            implode('.', $parts),
            $this->signedAttestation(array_replace($freshClaims, ['target_id' => 'another-site'])),
            $this->signedAttestation(array_replace($freshClaims, ['nonce' => 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'])),
        ];
        $localExpiry = $now->add(new \DateInterval('PT'.RuntimeAttestationReport::MAX_FRESHNESS_SECONDS.'S'));

        foreach ($invalidReports as $compactJws) {
            $replayProtection = self::createMock(RuntimeAttestationReplayProtectionInterface::class);
            $replayProtection->expects(self::never())->method('consume');
            $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
                $compactJws,
                $this->policy(),
                $this->trustedKeys,
                $this->nonce,
                $now,
            );

            self::assertEquals($now, $report->checkedAt);
            self::assertEquals($localExpiry, $report->expiresAt);
            self::assertTrue($report->hasFreshEvidenceAt($now));
            self::assertFalse($report->hasFreshEvidenceAt($localExpiry));
            $this->assertFailedAtActionBoundary($report);
        }
    }

    public function testSignedRejectionRetainsTheSignedExpiry(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $now->getTimestamp() + 30;
        $cases = [
            [ReplayProtectionDecision::ReplayDetected, []],
            [ReplayProtectionDecision::Accepted, [
                'control_results' => [
                    self::AUTH_CONTROL => 'verified',
                    self::CONFIG_CONTROL => 'unexpected',
                ],
            ]],
        ];

        foreach ($cases as [$decision, $overrides]) {
            $claims = array_replace([
                'issued_at' => $now->getTimestamp() - 1,
                'expires_at' => $expiresAt,
            ], $overrides);
            $report = $this->verifier($decision)->verify(
                $this->signedAttestation($claims),
                $this->policy(),
                $this->trustedKeys,
                $this->nonce,
                $now,
            );
            $signedExpiry = new \DateTimeImmutable('@'.$expiresAt);

            self::assertEquals($now, $report->checkedAt);
            self::assertEquals($signedExpiry, $report->expiresAt);
            self::assertTrue($report->hasFreshEvidenceAt($now));
            self::assertFalse($report->hasFreshEvidenceAt($signedExpiry));
            $this->assertFailedAtActionBoundary($report);
        }
    }

    public function testInvalidSignatureIsReportedAsFailedForAdminRequest(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $parts = explode('.', $this->signedAttestation([
            'issued_at' => $now->getTimestamp() - 1,
            'expires_at' => $now->getTimestamp() + 30,
        ]));
        $parts[2] = self::base64UrlEncode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
        $replayProtection = self::createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects(self::never())->method('consume');
        $report = new SignedRuntimeAttestationVerifier($replayProtection)->verify(
            implode('.', $parts),
            $this->policy(),
            $this->trustedKeys,
            $this->nonce,
            $now,
        );
        $provider = self::createStub(RuntimeAttestationReportProviderInterface::class);
        $provider->method('verifyCurrent')->willReturn($report);
        $reporter = self::createMock(RuntimeAttestationIncidentReporterInterface::class);
        $reporter->expects(self::once())->method('report')->with(
            'admin.request',
            RuntimeAttestationStatus::Failed,
            ['INV-AUTH-001', 'INV-SESSION-001', 'INV-APP-INTEGRITY-001', 'INV-WEBSERVER-CONFIG-001'],
        );
        $event = new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/admin', Request::METHOD_GET),
            HttpKernelInterface::MAIN_REQUEST,
        );

        new RuntimeAttestationRequestGuardSubscriber(
            $provider,
            new RuntimeAttestationActionGuard(),
            new RuntimeAttestationRequestPolicy(),
            $reporter,
        )->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('signature_invalid', (string) $response->getContent());
    }

    private function assertFailedAtActionBoundary(RuntimeAttestationReport $report): void
    {
        try {
            new RuntimeAttestationActionGuard()->requireVerified($report, self::AUTH_CONTROL, self::CONFIG_CONTROL);
            self::fail('Ein aktueller Fehlerbefund muss die Aktion als Failed sperren.');
        } catch (RuntimeAttestationUnavailable $failure) {
            self::assertSame(RuntimeAttestationStatus::Failed, $failure->status);
            self::assertSame([self::AUTH_CONTROL, self::CONFIG_CONTROL], $failure->requiredControlIds);
        }

        self::assertFalse($report->allows(self::AUTH_CONTROL));
        self::assertFalse($report->allows(self::CONFIG_CONTROL));
    }

    private function policy(): RuntimeAttestationPolicy
    {
        return $this->policy ?? throw new \LogicException('Die Testpolicy wurde nicht initialisiert.');
    }

    private function verifier(ReplayProtectionDecision $decision): SignedRuntimeAttestationVerifier
    {
        $replayProtection = self::createStub(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->method('consume')->willReturn($decision);

        return new SignedRuntimeAttestationVerifier($replayProtection);
    }

    private function signedAttestationWithDuplicateTargetId(): string
    {
        $segments = explode('.', $this->signedAttestation());
        $header = $segments[0];
        $encodedPayload = $segments[1] ?? null;
        if (null === $encodedPayload) {
            throw new \LogicException('Der Test-JWS ist unvollständig.');
        }

        $padding = (4 - (strlen($encodedPayload) % 4)) % 4;
        $payloadJson = base64_decode(strtr($encodedPayload, '-_', '+/').str_repeat('=', $padding), true);
        if (false === $payloadJson || !str_starts_with($payloadJson, '{')) {
            throw new \LogicException('Der Test-Payload ist kein JSON-Objekt.');
        }

        $payloadJson = '{"target_id":"another-site",'.substr($payloadJson, 1);
        $encodedPayload = self::base64UrlEncode($payloadJson);
        $signingInput = $header.'.'.$encodedPayload;
        $secretKey = $this->secretKey;
        if ('' === $secretKey) {
            throw new \LogicException('Der Testschlüssel wurde nicht initialisiert.');
        }

        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function signedAttestation(array $overrides = []): string
    {
        $claims = array_replace([
            'target_id' => self::TARGET_ID,
            'policy_id' => self::POLICY_ID,
            'policy_version' => self::POLICY_VERSION,
            'policy_digest' => self::POLICY_DIGEST,
            'attestor_id' => 'external-monitor',
            'attestor_version' => '2.3.0',
            'control_results' => [
                self::AUTH_CONTROL => 'verified',
                self::CONFIG_CONTROL => 'verified',
            ],
            'nonce' => $this->nonce,
            'sequence' => 4,
            'issued_at' => 980,
            'expires_at' => 1040,
        ], $overrides);
        $header = self::base64UrlEncode(json_encode(
            ['alg' => 'EdDSA', 'kid' => 'attestor-key-1', 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        ));
        $payload = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $header.'.'.$payload;
        $secretKey = $this->secretKey;
        if ('' === $secretKey) {
            throw new \LogicException('Der Testschlüssel wurde nicht initialisiert.');
        }

        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
