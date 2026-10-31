<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Security\AdminMfaProvisioningConflict;
use App\Platform\Security\AdminMfaProvisioningResult;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\InteractiveConsoleTerminalInterface;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\RuntimeAttestationUnavailable;
use App\Repository\AdminUserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:admin:mfa:provision',
    description: 'Richtet Mehr-Faktor-Anmeldung für ein Administrator- oder Moderationskonto ein oder neu ein.',
)]
final class ProvisionAdminMfaCommand extends Command
{
    /** @var list<string> */
    private const array REQUIRED_CONTROL_IDS = [
        'INV-AUTH-001',
        'INV-APP-INTEGRITY-001',
        'INV-WEBSERVER-CONFIG-001',
        'INV-INPUT-OUTPUT-001',
    ];

    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly AdminMfaProvisioningService $mfaProvisioner,
        private readonly RuntimeAttestationReportProviderInterface $runtimeAttestationReportProvider,
        private readonly RuntimeAttestationActionGuard $runtimeAttestationActionGuard,
        private readonly RuntimeAttestationIncidentReporterInterface $incidentReporter,
        private readonly InteractiveConsoleTerminalInterface $interactiveTerminal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive() || !$this->interactiveTerminal->isAttachedToTerminal($input, $output)) {
            $io->error('Die MFA-Provisionierung muss in einem interaktiven Terminal erfolgen, damit Einmalcodes nicht in umgeleitete Ausgaben oder Protokolle geraten.');

            return Command::FAILURE;
        }

        if (!$this->hasRequiredRuntimeAttestation()) {
            $io->error('Die MFA-Provisionierung ist wegen fehlender Sicherheitsnachweise derzeit gesperrt.');

            return Command::FAILURE;
        }

        $emailInput = $io->ask('E-Mail-Adresse des Kontos');
        if (!is_string($emailInput) || false === filter_var(trim($emailInput), FILTER_VALIDATE_EMAIL)) {
            $io->error('Bitte eine gültige E-Mail-Adresse eingeben.');

            return Command::FAILURE;
        }

        $email = mb_strtolower(trim($emailInput));
        $user = $this->users->findOneByEmail($email);
        if (!$user instanceof AdminUser || !AdminRole::hasStaffAccess($user->getRoles())) {
            $io->error('Für diese E-Mail-Adresse wurde kein unterstütztes Verwaltungskonto gefunden.');

            return Command::FAILURE;
        }

        $confirmation = $io->ask('E-Mail-Adresse zur Bestätigung erneut eingeben');
        if (!is_string($confirmation) || !hash_equals($email, mb_strtolower(trim($confirmation)))) {
            $io->error('Die Bestätigung stimmt nicht überein; MFA wurde nicht verändert.');

            return Command::FAILURE;
        }

        if (!$this->hasRequiredRuntimeAttestation()) {
            $io->error('Die MFA-Provisionierung ist wegen fehlender Sicherheitsnachweise derzeit gesperrt.');

            return Command::FAILURE;
        }

        try {
            $result = $this->mfaProvisioner->provision($user);
        } catch (AdminMfaProvisioningConflict) {
            $io->error('Die MFA wurde zwischenzeitlich geändert. Starte den Befehl erneut; die aktuelle Einrichtung bleibt unverändert.');

            return Command::FAILURE;
        } catch (\Throwable) {
            $io->error('Die MFA-Provisionierung ist fehlgeschlagen; das Konto wurde nicht freigegeben.');

            return Command::FAILURE;
        }

        $this->displayOneTimeSetup($io, $result);

        return Command::SUCCESS;
    }

    private function displayOneTimeSetup(SymfonyStyle $io, AdminMfaProvisioningResult $result): void
    {
        $io->success('MFA wurde eingerichtet. Das TOTP-Geheimnis und die Wiederherstellungscodes werden nur jetzt angezeigt.');
        $io->warning('Speichere diese Angaben sicher. Die Wiederherstellungscodes sind einzeln verwendbar und werden nicht erneut angezeigt.');
        $io->text([
            'TOTP-Geheimnis: '.$result->secretBase32,
            'Authenticator-URI: '.$result->otpauthUri,
            'Wiederherstellungscodes:',
        ]);
        $io->listing($result->recoveryCodes);
    }

    /** @phpstan-impure */
    private function hasRequiredRuntimeAttestation(): bool
    {
        try {
            $report = $this->runtimeAttestationReportProvider->verifyCurrent();
            $this->runtimeAttestationActionGuard->requireVerified($report, ...self::REQUIRED_CONTROL_IDS);

            return true;
        } catch (\Throwable $failure) {
            $status = $failure instanceof RuntimeAttestationUnavailable
                ? $failure->status
                : RuntimeAttestationStatus::Unknown;

            try {
                $this->incidentReporter->report('app:admin:mfa:provision', $status, self::REQUIRED_CONTROL_IDS);
            } catch (\Throwable) {
                // Der fehlende Alarm ändert nichts an der fail-closed-Sperre.
            }

            return false;
        }
    }
}
