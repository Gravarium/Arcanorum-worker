<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Module\Member\Security\MemberEmailChallengeManager;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityEmailSenderInterface;
use App\Tests\Support\RecordingMemberSecurityEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberSecurityFlowTest extends WebTestCase
{
    public function testPasswordRecoveryIsEnumerationSafeCsrfProtectedAndOneTime(): void
    {
        [$client, $sender] = $this->createClientWithSender();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $email = 'recovery-'.bin2hex(random_bytes(6)).'@example.test';
        $missingEmail = 'missing-'.bin2hex(random_bytes(6)).'@example.test';
        $oldPassword = 'ExistingMember!2026';
        $member = new MemberAccount($email, 'recovery-'.bin2hex(random_bytes(4)), 'Recovery member');
        $member->setPassword($passwordHasher->hashPassword($member, $oldPassword));
        $originalHash = $member->getPassword();
        $entityManager->persist($member);
        $entityManager->flush();
        $memberId = $member->getId();

        try {
            $missingMessage = $this->requestPasswordReset($client, $missingEmail);
            $knownMessage = $this->requestPasswordReset($client, $email);
            self::assertSame($missingMessage, $knownMessage);
            self::assertSame('Wenn ein passendes Konto besteht, erhältst du eine Nachricht. Prüfe auch deinen Spam-Ordner.', $knownMessage);
            self::assertCount(1, $sender->messages());
            self::assertSame(MemberEmailChallengePurpose::PasswordReset, $sender->messages()[0]['purpose']);

            $token = $sender->messages()[0]['token'];
            self::assertSame(43, strlen($token));
            self::assertSame(hash('sha256', $token), $entityManager->getConnection()->fetchOne(
                'SELECT token_hash FROM member_email_challenges WHERE member_id = :member AND purpose = :purpose',
                ['member' => $memberId, 'purpose' => MemberEmailChallengePurpose::PasswordReset->value],
            ));
            self::assertNotSame($token, $entityManager->getConnection()->fetchOne(
                'SELECT token_hash FROM member_email_challenges WHERE member_id = :member AND purpose = :purpose',
                ['member' => $memberId, 'purpose' => MemberEmailChallengePurpose::PasswordReset->value],
            ));

            $resetPath = '/konto/passwort-zuruecksetzen/'.$token;
            $client->request('GET', $resetPath);
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertStringContainsString('private', $cacheControl);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
            $csrf = (string) $client->getCrawler()->filter('form[action="'.$resetPath.'"] input[name="_token"]')->attr('value');
            $passwordForm = ['password' => 'RecoveredMember!2026', 'password_confirmation' => 'RecoveredMember!2026'];

            $client->request('POST', $resetPath, ['_token' => 'invalid'] + $passwordForm);
            self::assertResponseStatusCodeSame(403);
            self::assertSame($originalHash, $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));
            self::assertNull($entityManager->getConnection()->fetchOne(
                'SELECT consumed_at FROM member_email_challenges WHERE token_hash = :hash',
                ['hash' => hash('sha256', $token)],
            ));

            $client->request('POST', $resetPath, ['_token' => $csrf] + [
                'password' => $passwordForm['password'],
                'password_confirmation' => 'A different password!2026',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertNull($entityManager->getConnection()->fetchOne(
                'SELECT consumed_at FROM member_email_challenges WHERE token_hash = :hash',
                ['hash' => hash('sha256', $token)],
            ));

            $client->request('POST', $resetPath, ['_token' => $csrf] + $passwordForm);
            self::assertResponseRedirects('/konto/anmelden');
            $updatedHash = $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            self::assertNotSame($originalHash, $updatedHash);
            self::assertTrue(password_verify($passwordForm['password'], $updatedHash));
            self::assertFalse(password_verify($oldPassword, $updatedHash));

            $client->request('GET', $resetPath);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
        }
    }

    public function testVerificationRequestDoesNotEnumerateAccountsAndMailFailureRevokesLink(): void
    {
        [$client, $sender] = $this->createClientWithSender();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $email = 'unverified-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'unverified-'.bin2hex(random_bytes(4));
        $member = new MemberAccount($email, $handle, 'Unverified member');
        $member->requireEmailVerification();
        $member->updateProfile('Unverified member', '', true);
        $memberId = $member->getId();
        $entityManager->persist($member);
        $entityManager->flush();
        $sender->failDelivery();

        try {
            $client->request('GET', '/kanal/'.$handle);
            self::assertResponseStatusCodeSame(404);

            $missingMessage = $this->requestEmailVerification($client, 'missing-'.bin2hex(random_bytes(6)).'@example.test');
            $knownMessage = $this->requestEmailVerification($client, $email);
            self::assertSame($missingMessage, $knownMessage);
            self::assertSame('Wenn ein passendes Konto besteht, erhältst du eine Nachricht. Prüfe auch deinen Spam-Ordner.', $knownMessage);
            self::assertSame([], $sender->messages());
            self::assertSame(1, $this->countRows($entityManager,
                'SELECT COUNT(*) FROM member_email_challenges WHERE member_id = :member AND purpose = :purpose AND consumed_at IS NOT NULL',
                ['member' => $memberId, 'purpose' => MemberEmailChallengePurpose::EmailVerification->value],
            ));
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT email_verified FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
        }
    }

    public function testExpiredEmailVerificationLinkCannotVerifyAnAccount(): void
    {
        [$client] = $this->createClientWithSender();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $challenges = new MemberEmailChallengeManager($entityManager->getConnection());
        $email = 'expired-verification-'.bin2hex(random_bytes(6)).'@example.test';
        $member = new MemberAccount($email, 'expired-'.bin2hex(random_bytes(4)), 'Expired verification member');
        $member->requireEmailVerification();
        $memberId = $member->getId();
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            $token = $challenges->issue(
                $member,
                MemberEmailChallengePurpose::EmailVerification,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                3600,
            );
            $entityManager->getConnection()->executeStatement(
                'UPDATE member_email_challenges SET created_at = CURRENT_TIMESTAMP - INTERVAL \'2 hours\', expires_at = CURRENT_TIMESTAMP - INTERVAL \'1 minute\' WHERE token_hash = :hash',
                ['hash' => hash('sha256', $token)],
            );

            $client->request('GET', '/konto/verifizieren/'.$token);

            self::assertResponseStatusCodeSame(404);
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT email_verified FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
        }
    }

    public function testExpiredRecoveryLinkIsRejectedAndRepeatedRequestsAreLimited(): void
    {
        [$client, $sender] = $this->createClientWithSender();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $challenges = new MemberEmailChallengeManager($entityManager->getConnection());

        $email = 'limit-'.bin2hex(random_bytes(6)).'@example.test';
        $password = 'NeverChanged!2026';
        $member = new MemberAccount($email, 'limit-'.bin2hex(random_bytes(4)), 'Limit member');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $originalHash = $member->getPassword();
        $memberId = $member->getId();
        $entityManager->persist($member);
        $entityManager->flush();

        try {
            $token = $challenges->issue(
                $member,
                MemberEmailChallengePurpose::PasswordReset,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                1800,
            );
            $entityManager->getConnection()->executeStatement(
                'UPDATE member_email_challenges SET created_at = CURRENT_TIMESTAMP - INTERVAL \'1 hour\', expires_at = CURRENT_TIMESTAMP - INTERVAL \'1 minute\' WHERE token_hash = :hash',
                ['hash' => hash('sha256', $token)],
            );
            $client->request('GET', '/konto/passwort-zuruecksetzen/'.$token);
            self::assertResponseStatusCodeSame(404);

            $statuses = [];
            for ($attempt = 0; $attempt < 4; ++$attempt) {
                $client->request('GET', '/konto/passwort-vergessen');
                $csrf = (string) $client->getCrawler()->filter('form[action="/konto/passwort-vergessen"] input[name="_token"]')->attr('value');
                $client->request('POST', '/konto/passwort-vergessen', ['_token' => $csrf, 'email' => $email]);
                $statuses[] = $client->getResponse()->getStatusCode();
            }

            self::assertSame([200, 200, 200, 429], $statuses);
            self::assertCount(3, $sender->messages());
            self::assertSame($originalHash, $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
        }
    }

    public function testChangingAnAccountEmailInvalidatesPreviouslyIssuedRecoveryLinks(): void
    {
        [$client] = $this->createClientWithSender();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $challenges = new MemberEmailChallengeManager($entityManager->getConnection());

        $oldEmail = 'old-identity-'.bin2hex(random_bytes(6)).'@example.test';
        $member = new MemberAccount($oldEmail, 'old-identity-'.bin2hex(random_bytes(4)), 'Identity member');
        $member->setPassword($passwordHasher->hashPassword($member, 'OriginalMember!2026'));
        $entityManager->persist($member);
        $entityManager->flush();
        $token = $challenges->issue(
            $member,
            MemberEmailChallengePurpose::PasswordReset,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            1800,
        );
        $memberId = $member->getId();

        try {
            $member->updateIdentity('new-identity-'.bin2hex(random_bytes(6)).'@example.test', 'new-identity-'.bin2hex(random_bytes(4)));
            $entityManager->flush();

            self::assertFalse($member->isEmailVerified());
            self::assertSame(1, $member->getEmailVersion());
            self::assertNull($challenges->findEligibleMemberId(
                $token,
                MemberEmailChallengePurpose::PasswordReset,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ));
            self::assertSame($member->getPassword(), $entityManager->getConnection()->fetchOne(
                'SELECT password FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
        }
    }

    /** @return array{KernelBrowser, RecordingMemberSecurityEmailSender} */
    private function createClientWithSender(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.'.random_int(1, 254));
        $sender = new RecordingMemberSecurityEmailSender();
        $client->getContainer()->set(MemberSecurityEmailSenderInterface::class, $sender);

        return [$client, $sender];
    }

    private function requestPasswordReset(KernelBrowser $client, string $email): string
    {
        $client->request('GET', '/konto/passwort-vergessen');
        $csrf = (string) $client->getCrawler()->filter('form[action="/konto/passwort-vergessen"] input[name="_token"]')->attr('value');
        $client->request('POST', '/konto/passwort-vergessen', ['_token' => $csrf, 'email' => $email]);
        self::assertResponseIsSuccessful();

        return trim($client->getCrawler()->filter('[role="status"]')->text(''));
    }

    private function requestEmailVerification(KernelBrowser $client, string $email): string
    {
        $client->request('GET', '/konto/verifizierung');
        $csrf = (string) $client->getCrawler()->filter('form[action="/konto/verifizierung"] input[name="_token"]')->attr('value');
        $client->request('POST', '/konto/verifizierung', ['_token' => $csrf, 'email' => $email]);
        self::assertResponseIsSuccessful();

        return trim($client->getCrawler()->filter('[role="status"]')->text(''));
    }

    /** @param array<string, string> $parameters */
    private function countRows(EntityManagerInterface $entityManager, string $query, array $parameters = []): int
    {
        $count = $entityManager->getConnection()->fetchOne($query, $parameters);
        if (!is_numeric($count)) {
            self::fail('Die Datenbankabfrage zur Anzahl lieferte kein numerisches Ergebnis.');
        }

        return (int) $count;
    }
}
