<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

interface DraftApprovalPolicy
{
    /** The host checks the current moderator role, resource scope, and verified CSRF context. */
    public function mayApprove(string $actorId, MediaProvider $provider, string $externalId): bool;
}
