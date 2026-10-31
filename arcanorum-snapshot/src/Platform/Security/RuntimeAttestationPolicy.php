<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class RuntimeAttestationPolicy
{
    public const string POLICY_ID = 'arcanorum-hosting-control-matrix';

    public const string POLICY_VERSION = '1.0.0';

    public const string POLICY_DIGEST = '57f845052c8bf77f16cfeac8f285a03e4ef1ffd3c2de43a88b082510b465a861';

    /** @var list<string> */
    public array $requiredControlIds;

    /**
     * @param array<array-key, mixed> $requiredControlIds
     */
    public function __construct(
        public string $targetId,
        public string $policyId,
        public string $policyVersion,
        public string $policyDigest,
        array $requiredControlIds,
    ) {
        if (1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $targetId)) {
            throw new \InvalidArgumentException('Die Attestierungsrichtlinie benötigt eine gültige Zielkennung.');
        }

        if (1 !== preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $policyId)) {
            throw new \InvalidArgumentException('Die Attestierungsrichtlinie benötigt eine gültige Richtlinienkennung.');
        }

        if (1 !== preg_match('/\A[\x21-\x7E]{1,128}\z/D', $policyVersion)) {
            throw new \InvalidArgumentException('Die Attestierungsrichtlinie benötigt eine gültige Version.');
        }

        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $policyDigest)) {
            throw new \InvalidArgumentException('Der Richtlinien-Digest muss ein SHA-256-Hexwert sein.');
        }

        if ([] === $requiredControlIds || !array_is_list($requiredControlIds)) {
            throw new \InvalidArgumentException('Die Attestierungsrichtlinie benötigt eine Kontrollliste.');
        }

        $seen = [];
        $validatedControlIds = [];
        foreach ($requiredControlIds as $controlId) {
            if (!is_string($controlId) || 1 !== preg_match('/\A[A-Z][A-Z0-9-]{0,63}\z/D', $controlId)) {
                throw new \InvalidArgumentException('Die Attestierungsrichtlinie enthält eine ungültige Kontrollkennung.');
            }

            if (isset($seen[$controlId])) {
                throw new \InvalidArgumentException('Die Attestierungsrichtlinie enthält doppelte Kontrollkennungen.');
            }

            $seen[$controlId] = true;
            $validatedControlIds[] = $controlId;
        }

        $this->requiredControlIds = $validatedControlIds;
    }
}
