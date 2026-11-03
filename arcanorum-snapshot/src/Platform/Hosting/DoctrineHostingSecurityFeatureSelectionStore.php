<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Doctrine\DBAL\Connection;

final readonly class DoctrineHostingSecurityFeatureSelectionStore implements HostingSecurityFeatureSelectionStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<HostingSecurityFeature>
     */
    public function selectedFeatures(): array
    {
        try {
            $storedValue = $this->connection->fetchOne(
                'SELECT selected_feature_ids FROM hosting_security_selection WHERE id = :id',
                ['id' => 1],
            );
        } catch (\Throwable) {
            return [];
        }

        if (is_string($storedValue)) {
            try {
                $storedValue = json_decode($storedValue, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }

        if (!is_array($storedValue) || !array_is_list($storedValue)) {
            return [];
        }

        $features = [];
        foreach ($storedValue as $featureId) {
            if (!is_string($featureId)) {
                return [];
            }

            $feature = HostingSecurityFeature::tryFrom($featureId);
            if (null === $feature || in_array($feature, $features, true)) {
                return [];
            }

            $features[] = $feature;
        }

        return $features;
    }
}
