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
use App\Service\HostingSecurityFeatureSelectionManager;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\DBAL\Connection;
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
            HostingSecurityFeatureSelectionManager::class,
            $this->managerWithVerifiedPrivateStorage($client),
        );

        try {
            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            self::assertSame(0, $adminUsers->countForRoleManagement());

            $client->request('GET', '/install/security-profile');
            self::assertResponseIsSuccessful();
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('h1', 'Hosting-Sicherheitsprofil');
            self::assertSelectorTextContains('#mandatory-security-heading', 'Verbindlicher Basisschutz');
            self::assertSelectorExists('input[name="selected_features[]"][value="private_storage"]:not([disabled])');
            self::assertSelectorExists('input[name="selected_features[]"][value="secret_store"][disabled]');
            self::assertStringNotContainsString('Test-only verified host capability.', (string) $client->getResponse()->getContent());

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
            $admin = new AdminUser($email);
            $admin->setRoles([AdminRole::Administrator->value]);
            $admin->setPassword($hasher->hashPassword($admin, 'InstallerSecurity!2026'));
            $provisioner->provision($admin);

            $client->request('GET', '/install/security-profile');
            self::assertResponseStatusCodeSame(404);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

            $client->request('POST', '/install/security-profile', [
                'token' => 'untrusted',
                'selected_features' => ['private_storage'],
            ]);
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function managerWithVerifiedPrivateStorage(KernelBrowser $client): HostingSecurityFeatureSelectionManager
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $repository = $client->getContainer()->get(HostingSecuritySelectionRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(HostingSecuritySelectionRepository::class, $repository);
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
            new HostingProfileDetector([$probe]),
            new HostingSecurityFeatureCatalog(),
        );
    }
}
