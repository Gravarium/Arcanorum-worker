<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class HostingAdapterSelector
{
    /** @var list<HostingAdapterDefinitionInterface> */
    private array $adapters;

    /**
     * @param iterable<HostingAdapterDefinitionInterface> $adapters
     */
    public function __construct(
        #[AutowireIterator('app.hosting_adapter')]
        iterable $adapters,
    ) {
        $normalizedAdapters = [];
        $identifiers = [];

        foreach ($adapters as $adapter) {
            $identifier = $adapter->identifier();
            if (1 !== preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $identifier)) {
                throw new \InvalidArgumentException('Hostingadapter benötigen eine gültige, stabile Kennung.');
            }

            if (isset($identifiers[$identifier])) {
                throw new \InvalidArgumentException('Hostingadapter-Kennungen müssen eindeutig sein.');
            }

            if ($adapter->priority() < 0) {
                throw new \InvalidArgumentException('Die Priorität eines Hostingadapters darf nicht negativ sein.');
            }

            $requirements = $adapter->requiredCapabilities();
            $seenRequirements = [];
            foreach ($requirements as $requirement) {
                if (isset($seenRequirements[$requirement->value])) {
                    throw new \InvalidArgumentException('Hostingadapter dürfen keine Fähigkeit doppelt verlangen.');
                }

                $seenRequirements[$requirement->value] = true;
            }

            $identifiers[$identifier] = true;
            $normalizedAdapters[] = $adapter;
        }

        $this->adapters = $normalizedAdapters;
    }

    public function select(
        HostingService $service,
        HostingCapabilityReport $report,
    ): HostingAdapterDefinitionInterface {
        $eligible = [];

        foreach ($this->adapters as $adapter) {
            if ($adapter->service() !== $service) {
                continue;
            }

            $requirementsMet = true;
            foreach ($adapter->requiredCapabilities() as $capability) {
                if (!$report->supports($capability)) {
                    $requirementsMet = false;

                    break;
                }
            }

            if ($requirementsMet) {
                $eligible[] = $adapter;
            }
        }

        if ([] === $eligible) {
            throw new HostingAdapterUnavailable($service);
        }

        usort($eligible, static function (
            HostingAdapterDefinitionInterface $left,
            HostingAdapterDefinitionInterface $right,
        ): int {
            if ($left->isFallback() !== $right->isFallback()) {
                return $left->isFallback() ? 1 : -1;
            }

            return $right->priority() <=> $left->priority();
        });

        $selected = $eligible[0];
        $next = $eligible[1] ?? null;
        if (null !== $next
            && $selected->isFallback() === $next->isFallback()
            && $selected->priority() === $next->priority()
        ) {
            throw new HostingAdapterAmbiguous($service);
        }

        return $selected;
    }
}
