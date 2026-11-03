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
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\HostingSecuritySelectionRepository;
use App\Service\HostingSecurityFeatureSelectionManager;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminHostingSecurityControllerTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('H', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAdminNeedsCsrfAndOnlyVerifiedChoicesCanBeSaved(): void
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
        $email = 'hosting-security-'.bin2hex(random_bytes(6)).'@example.test';
        try {
            $this->persistAdmin($client, $email);
            $this->loginAdmin($client, $email);

            $client->request('GET', '/admin/system/security');
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            $cacheControlDirectives = array_map(trim(...), explode(',', strtolower($cacheControl)));
            self::assertContains('private', $cacheControlDirectives);
            self::assertContains('no-store', $cacheControlDirectives);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('#mandatory-security-heading', 'Verbindlicher Basisschutz');
            self::assertSelectorExists('input[name="selected_features[]"][value="private_storage"]:not([disabled])');

            $client->request('POST', '/admin/system/security', ['selected_features' => ['private_storage']]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/system/security');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/system/security', [
                'token' => $token,
                'selected_features' => ['csrf'],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role="alert"]', 'Bitte prüfen Sie die Auswahl und den aktuellen Hostingnachweis.');

            $client->request('GET', '/admin/system/security');
            $token = (string) $client->getCrawler()->filter('form input[name="token"]')->attr('value');
            $client->request('POST', '/admin/system/security', [
                'token' => $token,
                'selected_features' => ['private_storage'],
            ]);
            self::assertResponseRedirects('/admin/system/security');

            $manager = $client->getContainer()->get(HostingSecurityFeatureSelectionManager::class);
            self::assertInstanceOf(HostingSecurityFeatureSelectionManager::class, $manager);
            $choices = $manager->choices();
            $selected = array_find(
                $choices,
                static fn (HostingSecurityFeatureChoice $choice): bool => HostingSecurityFeature::PrivateStorage === $choice->feature,
            );
            self::assertNotNull($selected);
            self::assertTrue($selected->isEnabled());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
            $this->removeAdmin($client, $email);
            $entityManager->clear();
        }
    }

    public function testMissingSavedSelectionReturnsGenericUnavailableResponse(): void
    {
        $this->expectOutputRegex('/\\A\\[error\\] Die Host-Sicherheitsauswahl konnte nicht gelesen werden\\.\\s*\\z/');
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
        $email = 'hosting-security-missing-'.bin2hex(random_bytes(6)).'@example.test';

        try {
            $this->persistAdmin($client, $email);
            $this->loginAdmin($client, $email);
            $connection->executeStatement('DELETE FROM hosting_security_selection WHERE id = 1');
            $client->request('GET', '/admin/system/security');

            self::assertResponseStatusCodeSame(503);
            self::assertSame(
                'Die Auswahl konnte nicht geladen werden. Bitte versuchen Sie es erneut.',
                $client->getResponse()->getContent(),
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
            $this->removeAdmin($client, $email);
            $entityManager->clear();
        }
    }

    public function testUnauthenticatedVisitorCannotOpenTheAdminSelection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/system/security');

        self::assertResponseRedirects('/admin/login');
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
            'admin-hosting-security-test',
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

    private function persistAdmin(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);

        $user = new AdminUser($email);
        $user->setRoles([AdminRole::Administrator->value]);
        $user->setPassword($hasher->hashPassword($user, 'HostingSecurity!2026'));
        $provisioner->provision($user);
    }

    private function loginAdmin(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(AdminUser::class, $user);
        $encryptedSecret = $user->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        self::assertInstanceOf(TotpVerifier::class, $totp);
        $code = $totp->codeAtTime($cipher->decrypt($encryptedSecret), time());

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $email,
            '_password' => 'HostingSecurity!2026',
            '_mfa_code' => $code,
        ]);
        self::assertResponseRedirects('/admin');
    }

    private function removeAdmin(KernelBrowser $client, string $email): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = $entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if ($user instanceof AdminUser) {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM admin_activity_entries WHERE actor_id = :actor_id',
                ['actor_id' => $user->getId()],
            );
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }
}
