<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class RuntimeAttestationUnavailable extends \RuntimeException
{
    /**
     * @param list<string> $requiredControlIds
     */
    public function __construct(
        public readonly RuntimeAttestationStatus $status,
        public readonly array $requiredControlIds,
    ) {
        parent::__construct(sprintf(
            'Die für diese Aktion erforderliche Laufzeitattestierung ist nicht bestätigt (%s).',
            $status->value,
        ));
    }
}
