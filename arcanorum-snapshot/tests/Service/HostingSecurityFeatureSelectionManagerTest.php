<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\HostingSecuritySelection;
use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureCatalog;
use App\Repository\HostingSecuritySelectionRepository;
use App\Service\HostingSecurityFeatureSelectionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HostingSecurityFeatureSelectionManagerTest extends KernelTestCase
{
    public function testOnlyVerifiedOptionalSelectionsArePersistedAndEvidenceLossDisablesThem(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(HostingSecuritySelectionRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(HostingSecuritySelectionRepository::class, $repository);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $manager = $this->manager($entityManager, $repository, [
                HostingCapability::PrivateStorage->value => CapabilityStatus::Verified,
            ]);
            $manager->replaceSelection(['private_storage']);

            $selection = $repository->findCurrent();
            self::assertInstanceOf(HostingSecuritySelection::class, $selection);
            self::assertSame(['private_storage'], $selection->getSelectedFeatureIds());

            $choices = $manager->choices();
            $privateStorage = $this->choiceFor(HostingSecurityFeature::PrivateStorage, $choices);
            self::assertTrue($privateStorage->selected);
            self::assertTrue($privateStorage->isEnabled());

            $withoutProof = $this->manager($entityManager, $repository, []);
            $inactive = $this->choiceFor(HostingSecurityFeature::PrivateStorage, $withoutProof->choices());
            self::assertSame(CapabilityStatus::Unknown, $inactive->availability);
            self::assertTrue($inactive->hasSelectionDrift());
            self::assertFalse($inactive->isEnabled());

            $withoutProof->replaceSelection(['private_storage']);
            self::assertSame(['private_storage'], $repository->findCurrent()?->getSelectedFeatureIds());

            $withoutProof->replaceSelection([]);
            self::assertSame([], $repository->findCurrent()?->getSelectedFeatureIds());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testMalformedAndUnverifiedSelectionsAreRejectedWithoutChangingStoredChoice(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(HostingSecuritySelectionRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(HostingSecuritySelectionRepository::class, $repository);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $manager = $this->manager($entityManager, $repository, []);
            foreach ([
                'private_storage',
                ['csrf'],
                ['private_storage', 'private_storage'],
                [4 => 'private_storage'],
            ] as $invalidSelection) {
                try {
                    $manager->replaceSelection($invalidSelection);
                    self::fail('The selection manager must reject malformed or unverified choices.');
                } catch (\InvalidArgumentException) {
                    self::assertSame([], $repository->findCurrent()?->getSelectedFeatureIds());
                }
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    /**
     * @param array<string, CapabilityStatus> $statuses
     */
    private function manager(
        EntityManagerInterface $entityManager,
        HostingSecuritySelectionRepository $repository,
        array $statuses,
    ): HostingSecurityFeatureSelectionManager {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $evidence = [];
        foreach ($statuses as $capabilityId => $status) {
            $capability = HostingCapability::from($capabilityId);
            $evidence[] = new CapabilityEvidence(
                $capability,
                $status,
                'selection-manager-test',
                'Test capability evidence.',
                $now,
            );
        }

        $probe = new readonly class($evidence) implements CapabilityProbeInterface {
            /** @param list<CapabilityEvidence> $evidence */
            public function __construct(private array $evidence)
            {
            }

            /** @return iterable<CapabilityEvidence> */
            public function probe(): iterable
            {
                return $this->evidence;
            }
        };

        return new HostingSecurityFeatureSelectionManager(
            $entityManager,
            $repository,
            new HostingProfileDetector([$probe]),
            new HostingSecurityFeatureCatalog(),
        );
    }

    /**
     * @param list<\App\Platform\Hosting\HostingSecurityFeatureChoice> $choices
     */
    private function choiceFor(HostingSecurityFeature $feature, array $choices): \App\Platform\Hosting\HostingSecurityFeatureChoice
    {
        foreach ($choices as $choice) {
            if ($choice->feature === $feature) {
                return $choice;
            }
        }

        self::fail('The hosting security feature must be present in the catalog.');
    }
}
