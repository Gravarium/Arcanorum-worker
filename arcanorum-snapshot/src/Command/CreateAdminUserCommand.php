<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:create',
    description: 'Legt interaktiv ein Administrator-, Moderations- oder Redaktionskonto mit MFA an.',
)]
final class CreateAdminUserCommand extends Command
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
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminMfaProvisioningService $mfaProvisioner,
        private readonly RuntimeAttestationReportProviderInterface $runtimeAttestationReportProvider,
        private readonly RuntimeAttestationActionGuard $runtimeAttestationActionGuard,
        private readonly RuntimeAttestationIncidentReporterInterface $incidentReporter,
        private readonly InteractiveConsoleTerminalInterface $interactiveTerminal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'role',
            null,
            InputOption::VALUE_REQUIRED,
            'Kontorolle (ROLE_ADMIN, ROLE_MODERATOR, ROLE_MODERATOR_VIDEO, ROLE_MODERATOR_COMMENTS oder ROLE_CONTENT_EDITOR).',
            AdminRole::Administrator->value,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive() || !$this->interactiveTerminal->isAttachedToTerminal($input, $output)) {
            $io->error('Das Konto muss in einem interaktiven Terminal angelegt werden, damit Passwort und MFA-Einmalcodes nicht in umgeleitete Ausgaben oder Protokolle geraten.');

            return Command::FAILURE;
        }

        $roleValue = $input->getOption('role');
        $role = is_string($roleValue) ? AdminRole::tryFrom($roleValue) : null;
        if (null === $role) {
            $io->error('Die Kontorolle ist unbekannt. Verwende ausschließlich eine der aufgeführten Rollen.');

            return Command::FAILURE;
        }

        if (!$this->hasRequiredRuntimeAttestation()) {
            $io->error('Das Administratorkonto ist wegen fehlender Sicherheitsnachweise derzeit gesperrt.');

            return Command::FAILURE;
        }

        $emailInput = $io->ask('E-Mail-Adresse');
        if (!is_string($emailInput) || false === filter_var(trim($emailInput), FILTER_VALIDATE_EMAIL)) {
            $io->error('Bitte eine gültige E-Mail-Adresse eingeben.');

            return Command::FAILURE;
        }

        $email = mb_strtolower(trim($emailInput));
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error('Für diese E-Mail-Adresse besteht bereits ein Konto.');

            return Command::FAILURE;
        }

        $password = $io->askHidden('Passwort (mindestens 16 Zeichen)');
        if (!is_string($password) || mb_strlen($password) < 16) {
            $io->error('Das Passwort muss mindestens 16 Zeichen lang sein.');

            return Command::FAILURE;
        }

        $confirmation = $io->askHidden('Passwort wiederholen');
        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $io->error('Die Passwörter stimmen nicht überein.');

            return Command::FAILURE;
        }

        $user = new AdminUser($email);
        $user->setRoles([$role->value]);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        if (!$this->hasRequiredRuntimeAttestation()) {
            $io->error('Das Administratorkonto ist wegen fehlender Sicherheitsnachweise derzeit gesperrt.');

            return Command::FAILURE;
        }

        try {
            $mfaSetup = $this->mfaProvisioner->provision($user);
        } catch (\Throwable) {
            $io->error('Das Administratorkonto mit MFA konnte nicht sicher gespeichert werden.');

            return Command::FAILURE;
        }

        $accountLabel = match ($role) {
            AdminRole::Administrator => 'Administratorkonto',
            AdminRole::ContentEditor => 'Redaktionskonto',
            default => 'Moderationskonto',
        };
        $io->success($accountLabel.' wurde angelegt und MFA aktiviert.');
        $this->displayOneTimeSetup($io, $mfaSetup);

        return Command::SUCCESS;
    }

    private function displayOneTimeSetup(SymfonyStyle $io, AdminMfaProvisioningResult $result): void
    {
        $io->warning('Speichere das TOTP-Geheimnis und die Wiederherstellungscodes jetzt sicher. Sie werden nicht erneut angezeigt.');
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
                $this->incidentReporter->report('app:admin:create', $status, self::REQUIRED_CONTROL_IDS);
            } catch (\Throwable) {
                // Der fehlende Alarm ändert nichts an der fail-closed-Sperre.
            }

            return false;
        }
    }
}
