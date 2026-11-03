<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class HostingSecurityFeatureCatalog
{
    /**
     * @param array<array-key, mixed> $selectedFeatureIds
     * @return list<HostingSecurityFeatureChoice>
     */
    public function choices(HostingCapabilityReport $report, array $selectedFeatureIds): array
    {
        $selected = $this->normalizeFeatureIds($selectedFeatureIds);
        $selectedLookup = array_fill_keys($selected, true);
        $choices = [];

        foreach (HostingSecurityFeature::cases() as $feature) {
            $choices[] = new HostingSecurityFeatureChoice(
                $feature,
                $this->availability($feature, $report),
                isset($selectedLookup[$feature->value]),
            );
        }

        return $choices;
    }

    /**
     * Validates a replacement for the operator's optional feature selection.
     * A previously selected feature may remain selected while its host proof is
     * unavailable; it is reported as inactive until the proof is restored or the
     * operator explicitly removes it.
     *
     * @param array<array-key, mixed> $requestedFeatureIds
     * @param array<array-key, mixed> $previouslySelectedFeatureIds
     * @return list<string>
     */
    public function replaceSelection(
        array $requestedFeatureIds,
        array $previouslySelectedFeatureIds,
        HostingCapabilityReport $report,
    ): array {
        $requested = $this->normalizeFeatureIds($requestedFeatureIds);
        $previouslySelected = $this->normalizeFeatureIds($previouslySelectedFeatureIds);
        $previousLookup = array_fill_keys($previouslySelected, true);

        foreach ($requested as $featureId) {
            $feature = HostingSecurityFeature::from($featureId);
            if (!isset($previousLookup[$featureId])
                && CapabilityStatus::Verified !== $this->availability($feature, $report)
            ) {
                throw new \InvalidArgumentException('Eine zusätzliche Schutzfunktion ist für diesen Host nicht verifiziert.');
            }
        }

        $requestedLookup = array_fill_keys($requested, true);
        $ordered = [];
        foreach (HostingSecurityFeature::cases() as $feature) {
            if (isset($requestedLookup[$feature->value])) {
                $ordered[] = $feature->value;
            }
        }

        return $ordered;
    }

    private function availability(
        HostingSecurityFeature $feature,
        HostingCapabilityReport $report,
    ): CapabilityStatus {
        $statuses = array_map($report->statusFor(...), $feature->requiredCapabilities());

        if (in_array(CapabilityStatus::Failed, $statuses, true)) {
            return CapabilityStatus::Failed;
        }

        if (in_array(CapabilityStatus::NotAvailable, $statuses, true)) {
            return CapabilityStatus::NotAvailable;
        }

        if (in_array(CapabilityStatus::Unknown, $statuses, true)) {
            return CapabilityStatus::Unknown;
        }

        return CapabilityStatus::Verified;
    }

    /**
     * @param array<array-key, mixed> $featureIds
     * @return list<string>
     */
    private function normalizeFeatureIds(array $featureIds): array
    {
        if (!array_is_list($featureIds)) {
            throw new \InvalidArgumentException('Die Auswahl muss eine Liste von Schutzfunktionen sein.');
        }

        $normalized = [];
        foreach ($featureIds as $featureId) {
            if (!is_string($featureId)) {
                throw new \InvalidArgumentException('Die Auswahl enthält eine ungültige Schutzfunktion.');
            }

            $feature = HostingSecurityFeature::tryFrom($featureId);
            if (null === $feature) {
                throw new \InvalidArgumentException('Die Auswahl enthält eine unbekannte Schutzfunktion.');
            }

            if (isset($normalized[$feature->value])) {
                throw new \InvalidArgumentException('Die Auswahl enthält eine Schutzfunktion mehrfach.');
            }

            $normalized[$feature->value] = true;
        }

        return array_keys($normalized);
    }
}
