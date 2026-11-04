<?php

declare(strict_types=1);

namespace App\Command;

use App\Platform\Hosting\HostingSecurityFeatureChoice;
use App\Platform\Security\InteractiveConsoleTerminalInterface;
use App\Service\HostingSecurityFeatureSelectionManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:installer:security-profile',
    description: 'Prüft Hosting-Fähigkeiten und wählt im Installationsablauf zusätzliche Schutzfunktionen aus.',
)]
final class ConfigureInstallerSecurityProfileCommand extends Command
{
    public function __construct(
        private readonly HostingSecurityFeatureSelectionManager $selectionManager,
        private readonly InteractiveConsoleTerminalInterface $interactiveTerminal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive() || !$this->interactiveTerminal->isAttachedToTerminal($input, $output)) {
            $io->error('Die Host-Auswahl muss direkt in einem interaktiven Terminal erfolgen.');

            return Command::FAILURE;
        }

        $io->title('Installationsschritt: Hosting-Sicherheitsprofil');
        $io->section('Verbindlicher Basisschutz');
        $io->listing([
            'Anmeldung, Rollen und Berechtigungen',
            'MFA für privilegierte Konten',
            'CSRF- und Sitzungsschutz',
            'Eingabe-, Ausgabe- und Upload-Prüfungen',
        ]);
        $io->note('Der Basisschutz bleibt immer aktiv. Die folgende Auswahl kann ihn nicht abschalten.');

        try {
            $overview = $this->selectionManager->overview();
        } catch (DbalException|\RuntimeException) {
            $io->error('Das Hosting-Profil konnte nicht geladen werden. Bitte Datenbankverbindung und Migrationen prüfen.');

            return Command::FAILURE;
        }

        $io->section('Prüfung der Hosting-Umgebung');
        foreach ($overview->capabilityReport->all() as $evidence) {
            $io->text(sprintf(
                '%s: %s — %s',
                $evidence->capability->label(),
                $evidence->status->label(),
                $evidence->details,
            ));
        }

        $choices = $overview->optionalFeatures;
        $selectable = [];
        $numberToFeatureId = [];
        $defaultNumbers = [];
        $io->section('Vom Host nachweisbare Zusatzfunktionen');

        foreach ($choices as $choice) {
            $status = $this->statusLabel($choice);
            $io->text(sprintf('%s — %s', $choice->feature->label(), $status));
            $io->text('  '.$choice->feature->description());
            $io->text('  Laufzeitintegration: '.($choice->hasRuntimeIntegration() ? 'vorhanden' : 'in dieser Version nicht enthalten'));

            if (!$choice->isAvailable() && !$choice->selected) {
                continue;
            }

            $number = count($selectable) + 1;
            $selectable[] = $choice;
            $numberToFeatureId[$number] = $choice->feature->value;
            if ($choice->selected) {
                $defaultNumbers[] = $number;
            }

            $io->text(sprintf('  [%d] %s', $number, $choice->feature->label()));
        }

        if ([] === $selectable) {
            $io->note('Derzeit ist keine zusätzliche Schutzfunktion technisch verifiziert. Der verbindliche Basisschutz bleibt aktiv.');

            return Command::SUCCESS;
        }

        $default = [] === $defaultNumbers ? '0' : implode(',', $defaultNumbers);
        $answer = $io->ask(
            'Nummern durch Komma getrennt eingeben (0 = keine Zusatzfunktion; leer = aktuelle Auswahl)',
            $default,
        );
        if (!is_string($answer)) {
            $io->error('Die Auswahl konnte nicht gelesen werden.');

            return Command::FAILURE;
        }

        try {
            $requestedFeatureIds = $this->parseSelection($answer, $numberToFeatureId);
            $this->selectionManager->replaceSelection($requestedFeatureIds);
            $savedChoices = $this->selectionManager->choices();
        } catch (\InvalidArgumentException) {
            $io->error('Bitte nur angezeigte Nummern ohne Duplikate auswählen. Die Host-Prüfung wird beim Speichern erneut ausgeführt.');

            return Command::FAILURE;
        } catch (DbalException|\RuntimeException) {
            $io->error('Die Auswahl konnte nicht sicher gespeichert werden. Bitte Datenbankverbindung und Migrationen prüfen.');

            return Command::FAILURE;
        }

        $selectedCount = count(array_filter(
            $savedChoices,
            static fn (HostingSecurityFeatureChoice $choice): bool => $choice->selected,
        ));
        $enabledCount = count(array_filter(
            $savedChoices,
            static fn (HostingSecurityFeatureChoice $choice): bool => $choice->isEnabled(),
        ));
        $io->success(sprintf(
            '%d optionale Schutzfunktion(en) ausgewählt; %d davon sind nachgewiesen und in Arcanorum integriert.',
            $selectedCount,
            $enabledCount,
        ));
        $io->note('Eine gespeicherte Auswahl aktiviert keine fehlende Integration. Jede Funktion prüft ihre Hostvoraussetzungen vor der Nutzung erneut.');

        return Command::SUCCESS;
    }

    /** @param array<int, string> $numberToFeatureId
     *
     * @return list<string>
     */
    private function parseSelection(string $answer, array $numberToFeatureId): array
    {
        $answer = trim($answer);
        if ('0' === $answer || '' === $answer) {
            return [];
        }

        if (strlen($answer) > 128) {
            throw new \InvalidArgumentException('Die Auswahl ist zu lang.');
        }

        $selected = [];
        foreach (explode(',', $answer) as $rawNumber) {
            $number = trim($rawNumber);
            if (1 !== preg_match('/\A[1-9][0-9]*\z/', $number)) {
                throw new \InvalidArgumentException('Die Auswahl enthält keine gültige Nummer.');
            }

            $featureId = $numberToFeatureId[(int) $number] ?? null;
            if (!is_string($featureId) || in_array($featureId, $selected, true)) {
                throw new \InvalidArgumentException('Die Auswahl enthält eine unbekannte oder doppelte Nummer.');
            }

            $selected[] = $featureId;
        }

        return $selected;
    }

    private function statusLabel(HostingSecurityFeatureChoice $choice): string
    {
        if ($choice->isEnabled()) {
            return 'Hostnachweis verifiziert, ausgewählt und integriert';
        }

        if ($choice->hasSelectionDrift()) {
            return 'ausgewählt, aber derzeit nicht nutzbar: der Hostnachweis fehlt';
        }

        if ($choice->hasIntegrationGap()) {
            return 'ausgewählt und hostseitig verifiziert, aber nicht nutzbar: die Integration fehlt';
        }

        if ($choice->isAvailable()) {
            return $choice->hasRuntimeIntegration()
                ? 'Hostnachweis verifiziert und auswählbar'
                : 'Hostnachweis verifiziert; Laufzeitintegration fehlt';
        }

        return $choice->availability->label().' — derzeit nicht auswählbar';
    }
}
