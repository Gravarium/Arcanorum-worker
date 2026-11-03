<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingCapabilityReport;
use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureCatalog;
use App\Platform\Hosting\HostingSecurityFeatureChoice;
use PHPUnit\Framework\TestCase;

final class HostingSecurityFeatureCatalogTest extends TestCase
{
    public function testNoOptionalFeatureIsAvailableWithoutEvidence(): void
    {
        $choices = (new HostingSecurityFeatureCatalog())->choices($this->report(), []);

        self::assertCount(count(HostingSecurityFeature::cases()), $choices);
        foreach ($choices as $choice) {
            self::assertSame(CapabilityStatus::Unknown, $choice->availability);
            self::assertFalse($choice->isAvailable());
            self::assertFalse($choice->isEnabled());
            self::assertFalse($choice->selected);
        }
    }

    public function testCompositeRedisChoiceNeedsBothVersionAndPrivateEndpointEvidence(): void
    {
        $report = $this->report([
            HostingCapability::RedisServer8->value => CapabilityStatus::Verified,
            HostingCapability::RedisPrivateEndpoint->value => CapabilityStatus::Unknown,
        ]);
        $choice = $this->choice(HostingSecurityFeature::PrivateRedisEndpoint, $report);

        self::assertSame(CapabilityStatus::Unknown, $choice->availability);
        self::assertFalse($choice->isAvailable());
    }

    public function testOnlyFreshVerifiedEvidenceMakesASelectedFeatureEffective(): void
    {
        $report = $this->report([
            HostingCapability::PrivateStorage->value => CapabilityStatus::Verified,
        ]);
        $choice = $this->choice(HostingSecurityFeature::PrivateStorage, $report, ['private_storage']);

        self::assertTrue($choice->selected);
        self::assertTrue($choice->isAvailable());
        self::assertTrue($choice->isEnabled());
        self::assertFalse($choice->hasSelectionDrift());
    }

    public function testSelectedFeatureRemainsSelectedButInactiveWhenEvidenceBecomesUnknown(): void
    {
        $report = $this->report([
            HostingCapability::PrivateStorage->value => CapabilityStatus::Unknown,
        ]);
        $catalog = new HostingSecurityFeatureCatalog();
        $choice = $this->choice(HostingSecurityFeature::PrivateStorage, $report, ['private_storage']);

        self::assertSame(['private_storage'], $catalog->replaceSelection(
            ['private_storage'],
            ['private_storage'],
            $report,
        ));
        self::assertTrue($choice->selected);
        self::assertFalse($choice->isEnabled());
        self::assertTrue($choice->hasSelectionDrift());
    }

    public function testOperatorCannotSelectAnUnverifiedFeature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nicht verifiziert');

        (new HostingSecurityFeatureCatalog())->replaceSelection(
            ['private_storage'],
            [],
            $this->report(),
        );
    }

    public function testExplicitFailureAndUnavailableEvidenceKeepFeaturesInactive(): void
    {
        $catalog = new HostingSecurityFeatureCatalog();
        foreach ([CapabilityStatus::Failed, CapabilityStatus::NotAvailable] as $status) {
            $report = $this->report([
                HostingCapability::PrivateStorage->value => $status,
            ]);
            $choice = $this->choice(HostingSecurityFeature::PrivateStorage, $report);

            self::assertSame($status, $choice->availability);
            self::assertFalse($choice->isEnabled());

            try {
                $catalog->replaceSelection(['private_storage'], [], $report);
                self::fail('A feature without verified host evidence must not be newly selected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStaleVerifiedEvidenceIsTreatedAsUnknown(): void
    {
        $staleAt = new \DateTimeImmutable('-61 seconds', new \DateTimeZone('UTC'));
        $report = $this->report(
            [HostingCapability::PrivateStorage->value => CapabilityStatus::Verified],
            [HostingCapability::PrivateStorage->value => $staleAt],
        );
        $choice = $this->choice(HostingSecurityFeature::PrivateStorage, $report, ['private_storage']);

        self::assertSame(CapabilityStatus::Unknown, $choice->availability);
        self::assertFalse($choice->isEnabled());
        self::assertTrue($choice->hasSelectionDrift());
    }

    public function testOperatorMayExplicitlyRemoveAPreviouslySelectedFeatureAfterProofLoss(): void
    {
        $selection = (new HostingSecurityFeatureCatalog())->replaceSelection(
            [],
            ['private_storage'],
            $this->report(),
        );

        self::assertSame([], $selection);
    }

    public function testBaselineControlsAndUnknownIdsCannotBeSelected(): void
    {
        $allCapabilities = array_fill_keys(
            array_map(static fn (HostingCapability $capability): string => $capability->value, HostingCapability::cases()),
            CapabilityStatus::Verified,
        );

        foreach (['csrf', 'admin_mfa', 'future_feature'] as $featureId) {
            try {
                (new HostingSecurityFeatureCatalog())->replaceSelection(
                    [$featureId],
                    [],
                    $this->report($allCapabilities),
                );
                self::fail('Unknown or mandatory controls must not become selectable features.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDuplicateAndAssociativeSelectionsAreRejected(): void
    {
        $catalog = new HostingSecurityFeatureCatalog();
        $report = $this->report([
            HostingCapability::PrivateStorage->value => CapabilityStatus::Verified,
        ]);

        try {
            $catalog->replaceSelection(['private_storage', 'private_storage'], [], $report);
            self::fail('Duplicate feature IDs must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('mehrfach', $exception->getMessage());
        }

        try {
            $catalog->replaceSelection(['private_storage' => 'private_storage'], [], $report);
            self::fail('Associative request data must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Liste', $exception->getMessage());
        }
    }

    public function testSelectionOutputUsesStableCatalogOrder(): void
    {
        $report = $this->report(array_fill_keys(
            array_map(static fn (HostingCapability $capability): string => $capability->value, HostingCapability::cases()),
            CapabilityStatus::Verified,
        ));

        self::assertSame(
            array_map(static fn (HostingSecurityFeature $feature): string => $feature->value, HostingSecurityFeature::cases()),
            (new HostingSecurityFeatureCatalog())->replaceSelection(
                array_reverse(array_map(static fn (HostingSecurityFeature $feature): string => $feature->value, HostingSecurityFeature::cases())),
                [],
                $report,
            ),
        );
    }

    /**
     * @param list<string> $selected
     */
    private function choice(
        HostingSecurityFeature $feature,
        HostingCapabilityReport $report,
        array $selected = [],
    ): HostingSecurityFeatureChoice {
        foreach ((new HostingSecurityFeatureCatalog())->choices($report, $selected) as $choice) {
            if ($choice->feature === $feature) {
                return $choice;
            }
        }

        self::fail('Every registered hosting security feature needs one catalog choice.');
    }

    /**
     * @param array<string, CapabilityStatus> $overrides
     * @param array<string, \DateTimeImmutable> $checkedAtOverrides
     */
    private function report(array $overrides = [], array $checkedAtOverrides = []): HostingCapabilityReport
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $checks = [];
        foreach (HostingCapability::cases() as $capability) {
            $checks[$capability->value] = new CapabilityEvidence(
                $capability,
                $overrides[$capability->value] ?? CapabilityStatus::Unknown,
                self::class,
                'Capability evidence used by a catalog regression.',
                $checkedAtOverrides[$capability->value] ?? $checkedAt,
            );
        }

        return new HostingCapabilityReport($checks);
    }
}
