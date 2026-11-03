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
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\TotpVerifier;
use App\Repository\AdminUserRepository;
use App\Repository\HostingSecuritySelectionRepository;
use App\Service\HostingSecurityFeatureSelectionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InstallerBootstrapFlowTest extends WebTestCase
{
    /** @var array<string, array{process: string|false, env_present: bool, env_value: mixed, server_present: bool, server_value: mixed}> */
    private static array $originalEnvironment = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['ADMIN_MFA_ENCRYPTION_KEY', 'APP_SECRET'] as $name) {
            self::$originalEnvironment[$name] = [
                'process' => getenv($name),
                'env_present' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
                'server_present' => array_key_exists($name, $_SERVER),
                'server_value' => $_SERVER[$name] ?? null,
            ];
        }

        $testApplicationSecret = str_repeat('I', 48);
        putenv('ADMIN_MFA_ENCRYPTION_KEY=');
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = '';
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = '';
        putenv('APP_SECRET='.$testApplicationSecret);
        $_ENV['APP_SECRET'] = $testApplicationSecret;
        $_SERVER['APP_SECRET'] = $testApplicationSecret;

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$originalEnvironment as $name => $values) {
            if (false === $values['process']) {
                putenv($name);
            } else {
                putenv($name.'='.$values['process']);
            }

            if ($values['env_present']) {
                $_ENV[$name] = $values['env_value'];
            } else {
                unset($_ENV[$name]);
            }

            if ($values['server_present']) {
                $_SERVER[$name] = $values['server_value'];
            } else {
                unset($_SERVER[$name]);
            }
        }

        parent::tearDownAfterClass();
    }

    public function testBrowserInstallFlowFromDatabaseProbeThroughHostSelectionToFirstAdmin(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));

        $managerRegistry = $client->getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $managerRegistry);
        $entityManager = $managerRegistry->getManagerForClass(AdminUser::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $adminUsers = new AdminUserRepository($managerRegistry);
        $client->getContainer()->set(AdminUserRepository::class, $adminUsers);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM admin_users');
        $entityManager->clear();

        $client->getContainer()->set(
            HostingSecurityFeatureSelectionManager::class,
            $this->managerWithVerifiedPrivateStorage($client),
        );

        $email = 'installer-flow-'.bin2hex(random_bytes(6)).'@example.test';
        $password = bin2hex(random_bytes(24)).'Aa!';

        try {
            $client->request('GET', '/install/database');
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('#installer-database-probe-form');
            $client->request('POST', '/install/database', [
                'token' => 'invalid',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/install/database');
            $databaseToken = $this->csrfToken($client, '#installer-database-probe-form');
            $client->request('POST', '/install/database', [
                'token' => $databaseToken,
                'database_url' => 'postgresql://untrusted:secret@192.0.2.10:5432/target',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertStringNotContainsString('untrusted:secret', (string) $client->getResponse()->getContent());

            $client->request('POST', '/install/database', ['token' => $databaseToken]);
            self::assertResponseRedirects('/install/security-profile', Response::HTTP_SEE_OTHER);
            $this->assertPrivateNoStore($client);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertSelectorTextContains('h1', 'Hosting-Sicherheitsprofil');
            self::assertSelectorExists('#mandatory-security-heading');
            self::assertSelectorExists('input[name="selected_features[]"][value="private_storage"]:not([disabled])');
            self::assertSelectorExists('input[name="selected_features[]"][value="secret_store"][disabled]');
            self::assertStringNotContainsString('/private/config', (string) $client->getResponse()->getContent());

            $profileToken = $this->csrfToken($client, '#installer-hosting-security-form');
            $client->request('POST', '/install/security-profile', [
                'token' => $profileToken,
                'selected_features' => ['secret_store'],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

            $client->request('GET', '/install/security-profile');
            $profileToken = $this->csrfToken($client, '#installer-hosting-security-form');
            $client->request('POST', '/install/security-profile', [
                'token' => $profileToken,
                'selected_features' => ['private_storage'],
            ]);
            self::assertResponseRedirects('/install/security-profile', Response::HTTP_FOUND);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);

            $manager = $client->getContainer()->get(HostingSecurityFeatureSelectionManager::class);
            self::assertInstanceOf(HostingSecurityFeatureSelectionManager::class, $manager);
            $privateStorage = array_find(
                $manager->choices(),
                static fn (HostingSecurityFeatureChoice $choice): bool => HostingSecurityFeature::PrivateStorage === $choice->feature,
            );
            self::assertNotNull($privateStorage);
            self::assertTrue($privateStorage->isEnabled());

            self::assertSelectorExists('a[href="/install/administrator"]');
            $client->request('GET', '/install/administrator');
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertSelectorExists('#installer-first-admin-credentials-form');
            $credentialsToken = $this->csrfToken($client, '#installer-first-admin-credentials-form');

            $client->request('POST', '/install/administrator', [
                'token' => $credentialsToken,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            self::assertResponseRedirects('/install/administrator/mfa', Response::HTTP_SEE_OTHER);
            self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertSelectorExists('#installer-first-admin-mfa-form');
            $encodedSecret = trim($client->getCrawler()->filter('#installer-totp-secret')->text());
            self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $encodedSecret);
            $secret = $this->decodeBase32($encodedSecret);

            $totp = $client->getContainer()->get(TotpVerifier::class);
            self::assertInstanceOf(TotpVerifier::class, $totp);
            $mfaToken = $this->csrfToken($client, '#installer-first-admin-mfa-form');
            $client->request('POST', '/install/administrator/mfa', [
                'token' => $mfaToken,
                'code' => $totp->codeAtTime($secret, time()),
            ]);
            self::assertResponseRedirects('/install/administrator/recovery', Response::HTTP_SEE_OTHER);

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertSame(10, $client->getCrawler()->filter('#installer-recovery-codes li code')->count());
            self::assertStringNotContainsString($encodedSecret, (string) $client->getResponse()->getContent());

            $codes = $client->getCrawler()->filter('#installer-recovery-codes li code')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertCount(10, array_unique($codes));

            foreach ([
                '/install/database',
                '/install/security-profile',
                '/install/administrator',
                '/install/administrator/mfa',
                '/install/administrator/recovery',
            ] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $path);
                self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            }

            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            $admin = $adminUsers->findOneByEmail($email);
            self::assertInstanceOf(AdminUser::class, $admin);
            self::assertContains(AdminRole::Administrator->value, $admin->getRoles());
            self::assertNotNull($admin->getMfaSecretCiphertext());

            $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
            $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
            self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            self::assertSame($secret, $cipher->decrypt($admin->getMfaSecretCiphertext()));
            self::assertTrue($hasher->isPasswordValid($admin, $password));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function assertPrivateNoStore(KernelBrowser $client): void
    {
        $directives = array_map(
            trim(...),
            explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))),
        );
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
    }

    private function csrfToken(KernelBrowser $client, string $formSelector): string
    {
        $token = $client->getCrawler()->filter($formSelector.' input[name="token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private function decodeBase32(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $decoded = '';

        foreach (str_split($encoded) as $character) {
            $value = strpos($alphabet, $character);
            self::assertNotFalse($value);
            $buffer = ($buffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $decoded;
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
            'installer-bootstrap-test',
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
