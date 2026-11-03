<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\Module\Installer\Service\InstallerDatabaseConnectionProbe;
use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class InstallerDatabaseControllerTest extends WebTestCase
{
    public function testDatabaseWizardUsesServerConfigurationAndRejectsConnectionCredentials(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));

        $managerRegistry = $client->getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $managerRegistry);
        $entityManager = $managerRegistry->getManagerForClass(AdminUser::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM admin_users');
        $entityManager->clear();

        try {
            $client->request('GET', '/install/database');
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorExists('#installer-database-probe-form');
            self::assertSame(1, $client->getCrawler()->filter('#installer-database-probe-form input')->count());
            $token = $this->csrfToken($client);

            $client->request('POST', '/install/database', [
                'token' => 'invalid-token',
            ]);
            self::assertResponseStatusCodeSame(403);

            $untrustedDsn = 'postgresql://attacker:secret@192.0.2.10:5432/target';
            $client->request('POST', '/install/database', [
                'token' => $token,
                'database_url' => $untrustedDsn,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringNotContainsString($untrustedDsn, (string) $client->getResponse()->getContent());

            $client->request('POST', '/install/database', [
                'token' => $token,
            ]);
            self::assertResponseRedirects('/install/security-profile', Response::HTTP_SEE_OTHER);
            $this->assertPrivateNoStore($client);
            $adminUsers = $client->getContainer()->get(AdminUserRepository::class);
            self::assertInstanceOf(AdminUserRepository::class, $adminUsers);
            self::assertSame(0, $adminUsers->countAdministratorsForRoleManagement());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testUnavailableDatabaseRendersPrivateGenericPageWithoutCallingDatabaseBackedSubscribers(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.'.random_int(1, 254));

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())
            ->method('getConnection')
            ->willThrowException(new \RuntimeException('private-connection-string-should-not-leak'));
        $client->getContainer()->set(
            InstallerDatabaseConnectionProbe::class,
            new InstallerDatabaseConnectionProbe($managerRegistry),
        );
        $client->request('GET', '/install/database');

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivateNoStore($client);
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('DATABASE_URL', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('private-connection-string-should-not-leak', (string) $client->getResponse()->getContent());
    }

    public function testDatabaseMetadataFailureDoesNotClaimRequiredTablesAreMissing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('createSchemaManager')
            ->willThrowException(new \RuntimeException('private-metadata-failure-must-not-leak'));

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);
        $client->getContainer()->set(
            InstallerDatabaseConnectionProbe::class,
            new InstallerDatabaseConnectionProbe($managerRegistry),
        );

        $client->request('GET', '/install/database');

        self::assertResponseStatusCodeSame(503);
        $this->assertPrivateNoStore($client);
        self::assertSelectorTextContains(
            '[role="alert"]',
            'Die Verbindung antwortet, aber die erforderlichen Anwendungstabellen konnten nicht geprüft werden.',
        );
        self::assertStringNotContainsString('fehlen.', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('private-metadata-failure-must-not-leak', (string) $client->getResponse()->getContent());
    }

    public function testDatabaseProbeRateLimitsRequestsByHmacProtectedClientAddress(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));

        $managerRegistry = $client->getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $managerRegistry);
        $entityManager = $managerRegistry->getManagerForClass(AdminUser::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM admin_users');
        $entityManager->clear();

        try {
            $client->request('GET', '/install/database');
            self::assertResponseIsSuccessful();
            $token = $this->csrfToken($client);

            for ($attempt = 0; $attempt < 9; ++$attempt) {
                $client->request('POST', '/install/database', ['token' => $token]);
                self::assertResponseRedirects('/install/security-profile', Response::HTTP_SEE_OTHER);
            }

            $client->request('POST', '/install/database', ['token' => $token]);
            self::assertResponseStatusCodeSame(429);
            self::assertSame('3600', $client->getResponse()->headers->get('Retry-After'));
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
            'trim',
            explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))),
        );

        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $token = $client->getCrawler()->filter('#installer-database-probe-form input[name="token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }
}
