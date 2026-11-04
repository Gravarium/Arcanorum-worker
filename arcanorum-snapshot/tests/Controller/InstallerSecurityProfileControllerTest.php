<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureCatalog;
use App\Platform\Hosting\HostingSecurityFeatureChoice;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Repository\AdminUserRepository;
use App\Repository\HostingSecuritySelectionRepository;
use App\Service\AdminPhpRuntimeSummary;
use App\Service\HostingSecurityFeatureSelectionManager;
use App\Service\PhpRuntimeInspectorInterface;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InstallerSecurityProfileControllerTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('I', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testVisitorCanChooseOnlyCurrentlyVerifiedHostingProtectionsWithCsrf(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $client->getContainer()->set(
            AdminPhpRuntimeSummary::class,
            new AdminPhpRuntimeSummary(new InstallerProfileRuntimeInspector(), 'prod'),
        );
        $client->getContainer()->set(
            HostingSecurityFeatureSelectionManager::class,
            $this->managerWithVerifiedPrivateStorage($client),
        );

        try {
            $connection->executeStatement('DELETE FROM admin_users');
            $connection->executeStatement("UPDATE hosting_security_selection SET selected_feature_ids = '[]'::json WHERE id = 1");
            $entityManager->clear();

            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());

            $client->request('GET', '/install/security-profile');
            self::assertResponseIsSuccessful();
            $cacheControl = array_map(trim(...), explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
            self::assertContains('private', $cacheControl);
            self::assertContains('no-store', $cacheControl);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('h1', 'Hosting-Sicherheitsprofil');
            self::assertSelectorExists('#installer-php-runtime');
            self::assertSelectorTextContains('#installer-php-runtime-notices', 'PHP-URL-Includes sind aktiviert und sollten deaktiviert werden.');
            self::assertSelectorTextContains('#installer-php-runtime-notices', 'PHP-Fehlerausgabe ist in der Produktionsumgebung aktiviert.');
            self::assertSelectorTextContains('#installer-php-runtime', 'Maximale Uploadgröße');
            self::assertSelectorTextContains('#installer-php-runtime', 'Maximale POST-Größe');
            self::assertSelectorTextContains('#installer-php-runtime', 'PHP-Erweiterungen');
            self::assertStringNotContainsString('/private/config', (string) $client->getResponse()->getContent());
            self::assertSelectorTextContains('#mandatory-security-heading', 'Verbindlicher Basisschutz');
            self::assertSelectorTextContains('.hosting-capability-explanation', 'Unbekannt heißt: Arcanorum kann diese Fähigkeit derzeit nicht nachweisen. Das bedeutet nicht automatisch, dass der Hoster sie nicht unterstützt.');
            self::assertSelectorTextContains('#installer-hosting-capabilities', 'Diese Fähigkeit wird direkt in der aktiven PHP-Weblaufzeit geprüft.');
            self::assertSelectorTextContains('#installer-hosting-capabilities', 'Die Serverversion wird über die von der Anwendung verwendete Datenbankverbindung abgefragt.');
            self::assertSelectorTextContains('#installer-hosting-capabilities', 'DNS-Namen, Unix-Sockets und Cluster bleiben unbekannt.');
            self::assertSelectorTextContains('#installer-hosting-capabilities', 'Arcanorum kann sie in dieser Installation nicht unabhängig prüfen; sie bleibt unbekannt und wird nicht aktiviert.');
            self::assertSelectorTextContains('#installer-hosting-capabilities', 'Es liegt kein ausreichender aktueller Nachweis vor. Behandeln Sie diese Fähigkeit nicht als verfügbar.');
            self::assertSelectorExists('input[name="selected_features[]"][value="private_storage"]:not([disabled])');
            self::assertSelectorExists('input[name="selected_features[]"][value="secret_store"][disabled]');
            self::assertSelectorTextContains('#installer-hosting-security-form', 'Erforderliche Hosting-Fähigkeiten');
            self::assertSelectorTextContains('#installer-hosting-security-form', 'Laufzeitintegration: In Arcanorum integriert');
            self::assertSelectorTextContains('#installer-hosting-security-form', 'In dieser Version nicht integriert');
            self::assertSelectorTextContains('#installer-hosting-security-form', 'Privater Dateispeicher: Bestanden');
            self::assertSelectorTextContains('#installer-hosting-security-form', 'Redis 8: Unbekannt');
            self::assertStringNotContainsString('Test-only verified host capability.', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('App\\Platform\\Hosting\\', (string) $client->getResponse()->getContent());

            $client->request('POST', '/install/security-profile', ['selected_features' => ['private_storage']]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/install/security-profile');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/install/security-profile', [
                'token' => $token,
                'selected_features' => ['secret_store'],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'Bitte prüfen Sie die Auswahl und den aktuellen Hostingnachweis.');

            $client->request('GET', '/install/security-profile');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/install/security-profile', [
                'token' => $token,
                'selected_features' => ['private_storage'],
            ]);
            self::assertResponseRedirects('/install/security-profile');

            $manager = $client->getContainer()->get(HostingSecurityFeatureSelectionManager::class);
            self::assertInstanceOf(HostingSecurityFeatureSelectionManager::class, $manager);
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

    public function testInstallerSelectionClosesAfterFirstAdministratorExists(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $email = 'installer-gate-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $connection->executeStatement('DELETE FROM admin_users');
            $connection->executeStatement("UPDATE hosting_security_selection SET selected_feature_ids = '[]'::json WHERE id = 1");
            $entityManager->clear();
            $client->getContainer()->set(
                HostingSecurityFeatureSelectionManager::class,
                $this->managerWithVerifiedPrivateStorage($client),
            );
            $client->request('GET', '/install/security-profile');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            self::assertNotSame('', $token);

            $admin = new AdminUser($email);
            $admin->setRoles([AdminRole::Administrator->value]);
            $admin->setPassword($hasher->hashPassword($admin, 'InstallerSecurity!2026'));
            $provisioner->provision($admin);

            $client->request('GET', '/install/security-profile');
            self::assertResponseStatusCodeSame(404);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

            $client->request('POST', '/install/security-profile', [
                'token' => $token,
                'selected_features' => ['private_storage'],
            ]);
            self::assertResponseStatusCodeSame(404);

            $selectionRepository = $client->getContainer()->get(HostingSecuritySelectionRepository::class);
            self::assertInstanceOf(HostingSecuritySelectionRepository::class, $selectionRepository);
            $saved = $selectionRepository->findCurrent();
            self::assertInstanceOf(\App\Entity\HostingSecuritySelection::class, $saved);
            self::assertSame([], $saved->getSelectedFeatureIds());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
            $this->removeAdmin($client, $email);
            $entityManager->clear();
        }
    }

    private function removeAdmin(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $admin = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);

        if ($admin instanceof AdminUser) {
            $entityManager->remove($admin);
            $entityManager->flush();
        }
    }

    private function managerWithVerifiedPrivateStorage(KernelBrowser $client): HostingSecurityFeatureSelectionManager
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $repository = $client->getContainer()->get(HostingSecuritySelectionRepository::class);
        $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(HostingSecuritySelectionRepository::class, $repository);
        self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
        $evidence = [new CapabilityEvidence(
            HostingCapability::PrivateStorage,
            CapabilityStatus::Verified,
            'installer-hosting-profile-test',
            'Test-only verified host capability.',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        )];
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
            $adminUsers,
            new HostingProfileDetector([$probe]),
            new HostingSecurityFeatureCatalog(),
        );
    }
}

/** @internal */
final readonly class InstallerProfileRuntimeInspector implements PhpRuntimeInspectorInterface
{
    public function version(): string
    {
        return '8.5.4';
    }

    public function sapi(): string
    {
        return 'fpm-fcgi';
    }

    public function iniValue(string $name): ?string
    {
        return match ($name) {
            'upload_max_filesize' => '40M',
            'post_max_size' => '42M',
            'memory_limit' => '256M',
            'max_execution_time' => '30',
            'max_input_time' => '60',
            'max_input_vars' => '1000',
            'max_file_uploads' => '20',
            'default_socket_timeout' => '60',
            'display_errors', 'allow_url_include' => '1',
            'log_errors', 'allow_url_fopen', 'opcache.enable', 'zend.assertions' => '1',
            'expose_php' => '0',
            default => null,
        };
    }

    public function extensionLoaded(string $name): bool
    {
        return true;
    }
}
