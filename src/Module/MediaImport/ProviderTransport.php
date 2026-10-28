<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

interface ProviderTransport
{
    /**
     * The host supplies credentials at runtime, maps only the fixed HTTPS URI
     * from the request, verifies TLS, disables redirects, uses a short timeout,
     * and stops reading at ProviderResponse::MAX_BODY_BYTES. It must not log
     * authorization headers or response bodies.
     */
    public function send(ProviderRequest $request): ProviderResponse;
}
