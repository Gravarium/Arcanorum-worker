<?php

declare(strict_types=1);

namespace App\Platform\Security;

interface RuntimeAttestationReportProviderInterface
{
    /**
     * Liefert für die konkrete Aktion einen frisch verifizierten Bericht.
     *
     * Konfigurierte Implementierungen erzeugen je Aufruf eine frische Challenge und dürfen keinen
     * alten Bericht wiederverwenden. Vertrauensanker und Replay-Zustand müssen
     * außerhalb des betroffenen Hosts verwaltet werden.
     *
     * @phpstan-impure
     */
    public function verifyCurrent(): RuntimeAttestationReport;
}
