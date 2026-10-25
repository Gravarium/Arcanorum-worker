<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class SignedRuntimeAttestationVerifier
{
    public const int MAX_COMPACT_JWS_LENGTH = 65536;

    public function __construct(
        private ?RuntimeAttestationReplayProtectionInterface $replayProtection = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $trustedKeys
     */
    public function verify(
        ?string $compactJws,
        ?RuntimeAttestationPolicy $policy,
        array $trustedKeys,
        string $expectedNonce,
        \DateTimeImmutable $now,
    ): RuntimeAttestationReport {
        if (null === $policy) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, [], 'policy_not_configured');
        }

        $unknownControls = $this->uniformControlStatuses($policy, RuntimeAttestationStatus::Unknown);
        if (!RuntimeAttestationChallenge::isValid($expectedNonce)) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'challenge_invalid');
        }

        if (null === $compactJws || '' === $compactJws) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'attestation_missing');
        }

        if ([] === $trustedKeys) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'trust_anchor_not_configured');
        }

        if (!$this->hasValidTrustedKeySet($trustedKeys)) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'trust_anchor_invalid');
        }

        if (strlen($compactJws) > self::MAX_COMPACT_JWS_LENGTH) {
            return $this->failed($policy, 'attestation_too_large', $now);
        }

        $segments = explode('.', $compactJws);
        if (3 !== count($segments) || '' === $segments[0] || '' === $segments[1] || '' === $segments[2]) {
            return $this->failed($policy, 'jws_malformed', $now);
        }

        $headerJson = self::decodeBase64Url($segments[0]);
        $payloadJson = self::decodeBase64Url($segments[1]);
        $signature = self::decodeBase64Url($segments[2]);
        if (null === $headerJson || null === $payloadJson || null === $signature) {
            return $this->failed($policy, 'jws_malformed', $now);
        }

        $header = self::decodeJsonObject($headerJson);
        if (null === $header
            || !isset($header['alg'], $header['kid'], $header['typ'])
            || 'EdDSA' !== $header['alg']
            || 'JWT' !== $header['typ']
            || !is_string($header['kid'])
            || 1 !== preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $header['kid'])
            || [] !== array_diff(array_keys($header), ['alg', 'kid', 'typ'])
        ) {
            return $this->failed($policy, 'jws_header_invalid', $now);
        }

        if (!array_key_exists($header['kid'], $trustedKeys)) {
            return $this->failed($policy, 'attestor_untrusted', $now);
        }

        $trustedKey = $trustedKeys[$header['kid']];
        if (!is_array($trustedKey)
            || array_is_list($trustedKey)
            || 3 !== count($trustedKey)
            || [] !== array_diff(array_keys($trustedKey), ['public_key', 'attestor_id', 'attestor_version'])
        ) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'trust_anchor_invalid');
        }

        $trustedPublicKey = $trustedKey['public_key'] ?? null;
        $trustedAttestorId = $trustedKey['attestor_id'] ?? null;
        $trustedAttestorVersion = $trustedKey['attestor_version'] ?? null;
        if (!is_string($trustedPublicKey)
            || '' === $trustedPublicKey
            || !is_string($trustedAttestorId)
            || 1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $trustedAttestorId)
            || !is_string($trustedAttestorVersion)
            || 1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $trustedAttestorVersion)
        ) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'trust_anchor_invalid');
        }

        $publicKey = self::decodeBase64Url($trustedPublicKey);
        if (null === $publicKey || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($publicKey)) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'trust_anchor_invalid');
        }

        if (SODIUM_CRYPTO_SIGN_BYTES !== strlen($signature)
            || !sodium_crypto_sign_verify_detached($signature, $segments[0].'.'.$segments[1], $publicKey)
        ) {
            return $this->failed($policy, 'signature_invalid', $now);
        }

        $payload = self::decodeJsonObject($payloadJson);
        if (null === $payload
            || [] !== array_diff(array_keys($payload), [
                'target_id',
                'policy_id',
                'policy_version',
                'policy_digest',
                'attestor_id',
                'attestor_version',
                'control_results',
                'nonce',
                'sequence',
                'issued_at',
                'expires_at',
            ])
        ) {
            return $this->failed($policy, 'claims_invalid', $now);
        }

        if (($payload['target_id'] ?? null) !== $policy->targetId
            || ($payload['policy_id'] ?? null) !== $policy->policyId
            || ($payload['policy_version'] ?? null) !== $policy->policyVersion
            || ($payload['policy_digest'] ?? null) !== $policy->policyDigest
            || ($payload['attestor_id'] ?? null) !== $trustedAttestorId
            || ($payload['attestor_version'] ?? null) !== $trustedAttestorVersion
        ) {
            return $this->failed($policy, 'attestation_binding_invalid', $now);
        }

        $nonce = $payload['nonce'] ?? null;
        $sequence = $payload['sequence'] ?? null;
        $issuedAt = $payload['issued_at'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;
        if (!is_string($nonce)
            || !RuntimeAttestationChallenge::isValid($nonce)
            || !is_int($sequence)
            || $sequence < 1
            || !is_int($issuedAt)
            || $issuedAt < 0
            || !is_int($expiresAt)
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > RuntimeAttestationReport::MAX_FRESHNESS_SECONDS
        ) {
            return $this->failed($policy, 'attestation_freshness_claims_invalid', $now);
        }

        if ($nonce !== $expectedNonce) {
            return $this->failed($policy, 'attestation_challenge_mismatch', $now);
        }

        $nowTimestamp = $now->getTimestamp();
        if ($issuedAt > $nowTimestamp || $expiresAt <= $nowTimestamp) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'attestation_not_fresh');
        }

        if (null === $this->replayProtection) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'replay_protection_not_configured');
        }

        try {
            $replayDecision = $this->replayProtection->consume(
                $policy->targetId,
                $nonce,
                $sequence,
                new \DateTimeImmutable('@'.$expiresAt),
            );
        } catch (\Throwable) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'replay_protection_unavailable');
        }

        if (ReplayProtectionDecision::Unavailable === $replayDecision) {
            return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $unknownControls, 'replay_protection_unavailable');
        }

        if (ReplayProtectionDecision::ReplayDetected === $replayDecision) {
            return $this->failed(
                $policy,
                'attestation_replayed_or_rolled_back',
                $now,
                new \DateTimeImmutable('@'.$expiresAt),
            );
        }

        // Consume the nonce before interpreting control results, including a
        // signed report that records a failed or malformed control.
        $controlStatuses = $this->decodeControlStatuses($payload['control_results'] ?? null, $policy);
        if (null === $controlStatuses) {
            return $this->failed(
                $policy,
                'control_results_invalid',
                $now,
                new \DateTimeImmutable('@'.$expiresAt),
            );
        }

        $status = RuntimeAttestationStatus::Verified;
        foreach ($controlStatuses as $controlStatus) {
            if (RuntimeAttestationStatus::Failed === $controlStatus) {
                $status = RuntimeAttestationStatus::Failed;
                break;
            }

            if (RuntimeAttestationStatus::Unknown === $controlStatus) {
                $status = RuntimeAttestationStatus::Unknown;

                continue;
            }

            if (RuntimeAttestationStatus::NotAvailable === $controlStatus && RuntimeAttestationStatus::Verified === $status) {
                $status = RuntimeAttestationStatus::NotAvailable;
            }
        }

        return new RuntimeAttestationReport(
            $status,
            $controlStatuses,
            'attestation_checked',
            $now,
            new \DateTimeImmutable('@'.$expiresAt),
        );
    }

    /**
     * @param array<array-key, mixed> $trustedKeys
     */
    private function hasValidTrustedKeySet(array $trustedKeys): bool
    {
        if (array_is_list($trustedKeys) || count($trustedKeys) > 16) {
            return false;
        }

        foreach ($trustedKeys as $keyId => $trustedKey) {
            if (!is_string($keyId)
                || 1 !== preg_match('/\\A[A-Za-z0-9._-]{1,64}\\z/D', $keyId)
                || !is_array($trustedKey)
                || array_is_list($trustedKey)
                || 3 !== count($trustedKey)
                || [] !== array_diff(array_keys($trustedKey), ['public_key', 'attestor_id', 'attestor_version'])
            ) {
                return false;
            }

            $trustedPublicKey = $trustedKey['public_key'] ?? null;
            $trustedAttestorId = $trustedKey['attestor_id'] ?? null;
            $trustedAttestorVersion = $trustedKey['attestor_version'] ?? null;
            if (!is_string($trustedPublicKey)
                || !is_string($trustedAttestorId)
                || 1 !== preg_match('/\\A[\\x21-\\x7E]{1,255}\\z/D', $trustedAttestorId)
                || !is_string($trustedAttestorVersion)
                || 1 !== preg_match('/\\A[\\x21-\\x7E]{1,255}\\z/D', $trustedAttestorVersion)
            ) {
                return false;
            }

            $publicKey = self::decodeBase64Url($trustedPublicKey);
            if (null === $publicKey || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($publicKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, RuntimeAttestationStatus>
     */
    private function uniformControlStatuses(
        RuntimeAttestationPolicy $policy,
        RuntimeAttestationStatus $status,
    ): array {
        $statuses = [];
        foreach ($policy->requiredControlIds as $controlId) {
            $statuses[$controlId] = $status;
        }

        return $statuses;
    }

    private function failed(
        RuntimeAttestationPolicy $policy,
        string $reason,
        \DateTimeImmutable $checkedAt,
        ?\DateTimeImmutable $expiresAt = null,
    ): RuntimeAttestationReport {
        // Ein lokaler Fehlerbefund bestätigt niemals eine Kontrolle.
        // Gültig gebundene Berichte behalten ihre signierte Ablaufgrenze.
        return new RuntimeAttestationReport(
            RuntimeAttestationStatus::Failed,
            $this->uniformControlStatuses($policy, RuntimeAttestationStatus::Failed),
            $reason,
            $checkedAt,
            $expiresAt ?? $checkedAt->add(new \DateInterval('PT'.RuntimeAttestationReport::MAX_FRESHNESS_SECONDS.'S')),
        );
    }

    /**
     * @return array<string, RuntimeAttestationStatus>|null
     */
    private function decodeControlStatuses(mixed $value, RuntimeAttestationPolicy $policy): ?array
    {
        if (null === $value) {
            return $this->uniformControlStatuses($policy, RuntimeAttestationStatus::Unknown);
        }

        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            return null;
        }

        foreach (array_keys($value) as $controlId) {
            if (!is_string($controlId) || !in_array($controlId, $policy->requiredControlIds, true)) {
                return null;
            }
        }

        $statuses = [];
        foreach ($policy->requiredControlIds as $controlId) {
            if (!array_key_exists($controlId, $value)) {
                $statuses[$controlId] = RuntimeAttestationStatus::Unknown;

                continue;
            }

            if (!is_string($value[$controlId])) {
                return null;
            }

            $status = match ($value[$controlId]) {
                'verified' => RuntimeAttestationStatus::Verified,
                'failed' => RuntimeAttestationStatus::Failed,
                'unknown' => RuntimeAttestationStatus::Unknown,
                'not_available' => RuntimeAttestationStatus::NotAvailable,
                default => null,
            };
            if (null === $status) {
                return null;
            }

            $statuses[$controlId] = $status;
        }

        return $statuses;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodeJsonObject(string $json): ?array
    {
        return StrictJsonObjectDecoder::decode($json, 16);
    }

    private static function decodeBase64Url(string $encoded): ?string
    {
        if ('' === $encoded || 1 !== preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded)) {
            return null;
        }

        $padding = (4 - (strlen($encoded) % 4)) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
        if (false === $decoded || self::encodeBase64Url($decoded) !== $encoded) {
            return null;
        }

        return $decoded;
    }

    private static function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
