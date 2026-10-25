<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class HostingProfileDetector
{
    /** @var list<CapabilityProbeInterface> */
    private array $probes;

    /**
     * @param iterable<CapabilityProbeInterface> $probes
     */
    public function __construct(
        #[AutowireIterator('app.hosting_capability_probe')]
        iterable $probes,
    ) {
        $normalizedProbes = [];
        foreach ($probes as $probe) {
            $normalizedProbes[] = $probe;
        }

        $this->probes = $normalizedProbes;
    }

    public function detect(): HostingCapabilityReport
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $checks = [];
        $reported = [];
        $duplicates = [];

        foreach (HostingCapability::cases() as $capability) {
            $checks[$capability->value] = new CapabilityEvidence(
                $capability,
                CapabilityStatus::Unknown,
                self::class,
                'Für diese Fähigkeit liegt noch kein prüfbarer Nachweis vor.',
                $checkedAt,
            );
        }

        foreach ($this->probes as $probe) {
            foreach ($probe->probe() as $evidence) {
                $key = $evidence->capability->value;
                if (isset($reported[$key])) {
                    if (!isset($duplicates[$key])) {
                        $checks[$key] = new CapabilityEvidence(
                            $evidence->capability,
                            CapabilityStatus::Failed,
                            self::class,
                            'Mehrere Prüfer meldeten dieselbe Fähigkeit; der Nachweis ist mehrdeutig.',
                            $checkedAt,
                        );
                        $duplicates[$key] = true;
                    }

                    continue;
                }

                $checks[$key] = $evidence;
                $reported[$key] = true;
            }
        }

        return new HostingCapabilityReport($checks);
    }
}
