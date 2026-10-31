<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026140000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MemberSocialLoginMigrationTest extends KernelTestCase
{
    public function testDowngradeRefusesToDisableProvidersOrDiscardTheirLinkedIdentities(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('social-migration-'.$suffix.'@example.test', 'social-migration-'.$suffix, 'Social migration');
        $identity = new MemberExternalIdentity($member, 'facebook', 'facebook-subject-'.$suffix);
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE site_settings SET social_facebook_enabled = FALSE, social_twitter_enabled = FALSE WHERE id = 1');
            $entityManager->persist($member);
            $entityManager->persist($identity);
            $entityManager->flush();

            try {
                new Version20261026140000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve linked Facebook identities.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Verknüpfte Facebook- und Twitter-Identitäten dürfen durch den Migrationsrückbau nicht unbrauchbar werden.',
                    $exception->getMessage(),
                );
            }

            $connection->executeStatement('DELETE FROM member_external_identities WHERE member_id = :id', ['id' => $member->getId()]);
            $connection->executeStatement('UPDATE site_settings SET social_facebook_enabled = TRUE WHERE id = 1');

            try {
                new Version20261026140000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve enabled social sign-in.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Aktivierte soziale Anmeldedienste dürfen durch den Migrationsrückbau nicht abgeschaltet werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
