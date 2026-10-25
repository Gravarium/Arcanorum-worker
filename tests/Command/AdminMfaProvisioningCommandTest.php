<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateAdminUserCommand;
use App\Command\ProvisionAdminMfaCommand;
use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\InteractiveConsoleTerminalInterface;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Repository\AdminUserRepository;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminMfaProvisioningCommandTest extends KernelTestCase
{
    public function testCreateCommandRejectsMissingInteractiveTerminalBeforeAttestation(): void
    {
        $reportProvider = $this->createMock(RuntimeAttestationReportProviderInterface::class);
        $reportProvider->expects(self::never())->method('verifyCurrent');

        $terminal = $this->createMock(InteractiveConsoleTerminalInterface::class);
        $terminal->expects(self::once())
            ->method('isAttachedToTerminal')
            ->willReturn(false);

        $users = $this->userRepository();
        $command = new CreateAdminUserCommand(
            $users,
            $this->passwordHasher(),
            $this->mfaProvisioner($users),
            $reportProvider,
            new RuntimeAttestationActionGuard(),
            self::createStub(RuntimeAttestationIncidentReporterInterface::class),
            $terminal,
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('interaktiven Terminal', $tester->getDisplay());
    }

    public function testProvisionCommandRejectsMissingInteractiveTerminalBeforeAttestation(): void
    {
        $reportProvider = $this->createMock(RuntimeAttestationReportProviderInterface::class);
        $reportProvider->expects(self::never())->method('verifyCurrent');

        $terminal = $this->createMock(InteractiveConsoleTerminalInterface::class);
        $terminal->expects(self::once())
            ->method('isAttachedToTerminal')
            ->willReturn(false);

        $users = $this->userRepository();
        $command = new ProvisionAdminMfaCommand(
            $users,
            $this->mfaProvisioner($users),
            $reportProvider,
            new RuntimeAttestationActionGuard(),
            self::createStub(RuntimeAttestationIncidentReporterInterface::class),
            $terminal,
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('interaktiven Terminal', $tester->getDisplay());
    }

    public function testCreateCommandRejectsUnknownRoleBeforeAttestation(): void
    {
        $reportProvider = $this->createMock(RuntimeAttestationReportProviderInterface::class);
        $reportProvider->expects(self::never())->method('verifyCurrent');

        $terminal = $this->createMock(InteractiveConsoleTerminalInterface::class);
        $terminal->expects(self::once())
            ->method('isAttachedToTerminal')
            ->willReturn(true);

        $users = $this->userRepository();
        $command = new CreateAdminUserCommand(
            $users,
            $this->passwordHasher(),
            $this->mfaProvisioner($users),
            $reportProvider,
            new RuntimeAttestationActionGuard(),
            self::createStub(RuntimeAttestationIncidentReporterInterface::class),
            $terminal,
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['--role' => 'ROLE_UNKNOWN'], ['interactive' => true]));
        self::assertStringContainsString('Kontorolle ist unbekannt', $tester->getDisplay());
    }

    public function testVideoModeratorAccountCanBeCreatedAndHaveMfaReprovisioned(): void
    {
        $users = $this->userRepository();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $email = 'moderator-command-'.bin2hex(random_bytes(6)).'@example.test';
        $password = 'ModeratorCommand!2026';
        $terminal = self::createStub(InteractiveConsoleTerminalInterface::class);
        $terminal->method('isAttachedToTerminal')->willReturn(true);
        $reportProvider = new VerifiedRuntimeAttestationReportProvider();

        try {
            $createCommand = new CreateAdminUserCommand(
                $users,
                $this->passwordHasher(),
                $this->mfaProvisioner($users),
                $reportProvider,
                new RuntimeAttestationActionGuard(),
                self::createStub(RuntimeAttestationIncidentReporterInterface::class),
                $terminal,
            );
            $createTester = new CommandTester($createCommand);
            $createTester->setInputs([$email, $password, $password]);

            self::assertSame(
                Command::SUCCESS,
                $createTester->execute(['--role' => AdminRole::VideoModerator->value], ['interactive' => true]),
            );

            $moderator = $users->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $moderator);
            self::assertSame([AdminRole::VideoModerator->value, 'ROLE_USER'], $moderator->getRoles());

            $provisionCommand = new ProvisionAdminMfaCommand(
                $users,
                $this->mfaProvisioner($users),
                $reportProvider,
                new RuntimeAttestationActionGuard(),
                self::createStub(RuntimeAttestationIncidentReporterInterface::class),
                $terminal,
            );
            $provisionTester = new CommandTester($provisionCommand);
            $provisionTester->setInputs([$email, $email]);

            self::assertSame(Command::SUCCESS, $provisionTester->execute([], ['interactive' => true]));
        } finally {
            $moderator = $users->findOneByEmail($email);
            if ($moderator instanceof AdminUser) {
                $entityManager->remove($moderator);
                $entityManager->flush();
            }
        }
    }

    private function userRepository(): AdminUserRepository
    {
        $users = static::getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(AdminUserRepository::class, $users);

        return $users;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function mfaProvisioner(AdminUserRepository $users): AdminMfaProvisioningService
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return new AdminMfaProvisioningService(
            $entityManager,
            $users,
            new AdminMfaSecretCipher(base64_encode(str_repeat('k', 32))),
        );
    }
}
