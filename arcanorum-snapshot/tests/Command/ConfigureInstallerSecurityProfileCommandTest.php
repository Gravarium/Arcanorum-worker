<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConfigureInstallerSecurityProfileCommand;
use App\Entity\HostingSecuritySelection;
use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureCatalog;
use App\Platform\Hosting\HostingSecurityFeatureChoice;
use App\Platform\Security\InteractiveConsoleTerminalInterface;
use App\Repository\HostingSecuritySelectionRepository;
use App\Service\HostingSecurityFeatureSelectionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigureInstallerSecurityProfileCommandTest extends KernelTestCase
{
    public function testInstallStepRequiresAnAttachedInteractiveTerminal(): void
    {
        $manager = static::getContainer()->get(HostingSecurityFeatureSelectionManager::class);
        self::assertInstanceOf(HostingSecurityFeatureSelectionManager::class, $manager);

        $nonInteractiveTerminal = self::createMock(InteractiveConsoleTerminalInterface::class);
        $nonInteractiveTerminal
            ->expects(self::never())
            ->method('isAttachedToTerminal');
        $nonInteractiveTester = new CommandTester(
            new ConfigureInstallerSecurityProfileCommand($manager, $nonInteractiveTerminal),
        );

        self::assertSame(
            Command::FAILURE,
            $nonInteractiveTester->execute([], ['interactive' => false]),
        );
        self::assertStringContainsString('interaktiven Terminal', $nonInteractiveTester->getDisplay());
        self::assertStringNotContainsString('Prüfung der Hosting-Umgebung', $nonInteractiveTester->getDisplay());

        $detachedTerminal = self::createMock(InteractiveConsoleTerminalInterface::class);
        $detachedTerminal
            ->expects(self::once())
            ->method('isAttachedToTerminal')
            ->willReturn(false);
        $detachedTester = new CommandTester(
            new ConfigureInstallerSecurityProfileCommand($manager, $detachedTerminal),
        );

        self::assertSame(
            Command::FAILURE,
            $detachedTester->execute([], ['interactive' => true]),
        );
        self::assertStringContainsString('interaktiven Terminal', $detachedTester->getDisplay());
        self::assertStringNotContainsString('Prüfung der Hosting-Umgebung', $detachedTester->getDisplay());
    }

    public function testInstallStepShowsFixedBaselineAndSavesOnlyVerifiedOptionalChoices(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(HostingSecuritySelectionRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(HostingSecuritySelectionRepository::class, $repository);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $probe = new readonly class implements CapabilityProbeInterface {
                /** @return iterable<CapabilityEvidence> */
                public function probe(): iterable
                {
                    yield new CapabilityEvidence(
                        HostingCapability::PrivateStorage,
                        CapabilityStatus::Verified,
                        'installer-security-profile-test',
                        'Test-only proof for private storage.',
                        new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    );
                }
            };
            $manager = new HostingSecurityFeatureSelectionManager(
                $entityManager,
                $repository,
                new HostingProfileDetector([$probe]),
                new HostingSecurityFeatureCatalog(),
            );
            $manager->replaceSelection([]);

            $terminal = self::createStub(InteractiveConsoleTerminalInterface::class);
            $terminal->method('isAttachedToTerminal')->willReturn(true);
            $invalidTester = new CommandTester(new ConfigureInstallerSecurityProfileCommand($manager, $terminal));
            $invalidTester->setInputs(['99']);

            self::assertSame(Command::FAILURE, $invalidTester->execute([], ['interactive' => true]));
            self::assertStringContainsString('Verbindlicher Basisschutz', $invalidTester->getDisplay());
            self::assertStringContainsString('Prüfung der Hosting-Umgebung', $invalidTester->getDisplay());
            self::assertStringContainsString('Privater Dateispeicher: Bestanden', $invalidTester->getDisplay());
            self::assertStringContainsString('PHP-Laufzeit: Unbekannt', $invalidTester->getDisplay());
            self::assertStringContainsString('immer aktiv', $invalidTester->getDisplay());
            self::assertStringContainsString('Privater Dateispeicher', $invalidTester->getDisplay());
            self::assertStringContainsString('derzeit nicht auswählbar', $invalidTester->getDisplay());
            self::assertStringContainsString('Laufzeitintegration: in dieser Version nicht enthalten', $invalidTester->getDisplay());

            $selection = $repository->findCurrent();
            self::assertInstanceOf(HostingSecuritySelection::class, $selection);
            self::assertSame([], $selection->getSelectedFeatureIds());

            $validTester = new CommandTester(new ConfigureInstallerSecurityProfileCommand($manager, $terminal));
            $validTester->setInputs(['1']);

            self::assertSame(Command::SUCCESS, $validTester->execute([], ['interactive' => true]));
            self::assertStringContainsString('1 optionale Schutzfunktion(en) ausgewählt; 1 davon sind nachgewiesen und in Arcanorum integriert.', $validTester->getDisplay());
            self::assertStringContainsString('Laufzeitintegration: vorhanden', $validTester->getDisplay());

            $selection = $repository->findCurrent();
            self::assertInstanceOf(HostingSecuritySelection::class, $selection);
            self::assertSame([HostingSecurityFeature::PrivateStorage->value], $selection->getSelectedFeatureIds());

            $savedChoice = array_find(
                $manager->choices(),
                static fn (HostingSecurityFeatureChoice $choice): bool => HostingSecurityFeature::PrivateStorage === $choice->feature,
            );
            self::assertNotNull($savedChoice);
            self::assertTrue($savedChoice->isEnabled());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }
}
