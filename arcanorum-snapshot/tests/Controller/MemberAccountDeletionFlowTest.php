<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Command\CleanupPrivateObjectsCommand;
use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\ContentRating;
use App\Entity\ContentRatingValue;
use App\Entity\MemberAccount;
use App\Entity\MemberAccountBan;
use App\Entity\MemberExternalIdentity;
use App\Entity\MemberSavedVideo;
use App\Entity\MemberVideoCollection;
use App\Entity\MemberVideoHistory;
use App\Entity\Playlist;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Entity\VideoSubtitleTrack;
use App\Platform\Hosting\HostingAdapterDefinitionInterface;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingService;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\PrivateObjectDeletionJobRepository;
use App\Service\PrivateObjectDeletionService;
use App\Service\VideoSocialPreviewGenerator;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberAccountDeletionFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('D', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testDeletionRequiresAdminCsrfAndExplicitConfirmation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->provideVerifiedRuntime($client);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('member-delete-security-'.$suffix.'@example.test', 'delsec'.$suffix, 'Delete security member');
        $memberId = $member->getId();
        $adminPassword = 'DeleteSecurity!2026-'.$suffix;
        $admin = $this->createAdministrator($client, 'admin-delete-security-'.$suffix.'@example.test', $adminPassword);
        $entityManager->persist($member);
        $entityManager->persist($admin);
        $entityManager->flush();

        try {
            $deleteUrl = '/admin/members/'.$memberId.'/delete';
            $client->request('POST', $deleteUrl, []);
            self::assertResponseRedirects('/admin/login');

            $this->loginAsAdministrator($client, $admin, $adminPassword);
            $client->followRedirect();
            $editUrl = '/admin/members/'.$memberId;
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s/delete"] input[name="_token"]', $editUrl))
                ->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', $deleteUrl, ['_token' => 'invalid-token', 'confirm' => '1']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $deleteUrl, ['_token' => $token, 'confirm' => '1', 'unexpected' => 'value']);
            self::assertResponseStatusCodeSame(400);
            $client->request('POST', $deleteUrl, ['_token' => $token, 'confirm' => 'yes']);
            self::assertResponseStatusCodeSame(422);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertNotNull($entityManager->find(MemberAccount::class, $memberId));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    public function testDeletionRetainsPublishedContentAndPublicCommentsButRemovesUnapprovedDraftAssets(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->provideVerifiedRuntime($client);
        $storage = $this->createMock(MemberAccountDeletionTestStorage::class);
        $storage->method('identifier')->willReturn('test.storage.member_account_deletion');
        $storage->method('service')->willReturn(HostingService::Storage);
        $storage->method('requiredCapabilities')->willReturn([HostingCapability::PrivateStorage]);
        $storage->method('priority')->willReturn(100);
        $storage->method('isFallback')->willReturn(false);
        $objectIds = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
        $objectIds[] = VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($objectIds[1]);
        $storage->expects($this->exactly(4))->method('delete')->willReturnCallback(
            static function (string $objectId) use ($objectIds): void {
                self::assertContains($objectId, $objectIds);
            },
        );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $member = new MemberAccount('member-delete-retention-'.$suffix.'@example.test', 'delret'.$suffix, 'Delete retention member');
        $memberId = $member->getId();
        $adminPassword = 'DeleteRetention!2026-'.$suffix;
        $admin = $this->createAdministrator($client, 'admin-delete-retention-'.$suffix.'@example.test', $adminPassword);

        $published = new Video(
            'Published retention '.$suffix,
            'Published content retained after member deletion.',
            null,
            VideoSourceType::Url,
            'https://www.youtube.com/watch?v=retention'.$suffix,
            PublishingStatus::Published,
            false,
            true,
        );
        $publishedSubmission = new VideoSubmission($published, $member);
        $publishedSubmission->resolve(VideoSubmissionStatus::Approved);
        $publicComment = new ContentComment(
            CommentContentType::Video,
            $published->getId(),
            $published->getTitle(),
            $member->getDisplayName(),
            'A public comment that must remain anonymous.',
        );
        $publicComment->attributeToMember($member);
        $publicComment->approve();
        $playlist = new Playlist($member, 'Private playlist '.$suffix, 'Deleted with its owner.');
        $playlist->addVideo($published);
        $savedVideo = MemberSavedVideo::create($member, $published, MemberVideoCollection::WatchLater);
        $rating = new ContentRating($member, $published, null, ContentRatingValue::Like);
        $history = MemberVideoHistory::create($member, $published);
        $identity = new MemberExternalIdentity($member, 'google', 'provider-'.$suffix);
        $ban = new MemberAccountBan($member, $admin, 'Account data retention test', true);

        $draft = new Video(
            'Pending deletion '.$suffix,
            'Unapproved content and assets are removed.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            true,
        );
        $draft->attachUploadedMedia($objectIds[0], 'video/mp4', 1024);
        $draft->attachThumbnail($objectIds[1], 'image/png', 32);
        $submission = new VideoSubmission($draft, $member);
        $subtitle = new VideoSubtitleTrack(
            $draft,
            'en',
            'English',
            $objectIds[2],
            64,
            hash('sha256', 'subtitle fixture'),
        );
        $rejectedDraft = new Video(
            'Rejected deletion '.$suffix,
            'Rejected unapproved draft is removed.',
            null,
            VideoSourceType::Url,
            'https://media.example.test/rejected-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $rejectedSubmission = new VideoSubmission($rejectedDraft, $member);
        $rejectedSubmission->resolve(VideoSubmissionStatus::Rejected);

        $entityManager->persist($member);
        $entityManager->persist($admin);
        $entityManager->persist($published);
        $entityManager->persist($publishedSubmission);
        $entityManager->persist($publicComment);
        $entityManager->persist($playlist);
        $entityManager->persist($savedVideo);
        $entityManager->persist($rating);
        $entityManager->persist($history);
        $entityManager->persist($identity);
        $entityManager->persist($ban);
        $entityManager->persist($draft);
        $entityManager->persist($submission);
        $entityManager->persist($subtitle);
        $entityManager->persist($rejectedDraft);
        $entityManager->persist($rejectedSubmission);
        $entityManager->flush();
        $publishedId = $published->getId();
        $publishedSubmissionId = $publishedSubmission->getId();
        $publicCommentId = $publicComment->getId();
        $draftId = $draft->getId();
        $submissionId = $submission->getId();
        $rejectedDraftId = $rejectedDraft->getId();
        $rejectedSubmissionId = $rejectedSubmission->getId();

        try {
            $this->loginAsAdministrator($client, $admin, $adminPassword);
            $client->followRedirect();
            $editUrl = '/admin/members/'.$memberId;
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s/delete"] input[name="_token"]', $editUrl))
                ->attr('value');
            $client->request('POST', $editUrl.'/delete', ['_token' => $token, 'confirm' => '1']);
            self::assertResponseRedirects('/admin/members');
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));

            $entityManager->clear();
            self::assertNull($entityManager->find(MemberAccount::class, $memberId));
            self::assertNotNull($entityManager->find(Video::class, $publishedId));
            self::assertNull($entityManager->find(Video::class, $draftId));
            self::assertNull($entityManager->find(Video::class, $rejectedDraftId));
            self::assertNull($entityManager->find(VideoSubmission::class, $submissionId));
            self::assertNull($entityManager->find(VideoSubmission::class, $rejectedSubmissionId));
            $retainedSubmission = $entityManager->find(VideoSubmission::class, $publishedSubmissionId);
            self::assertInstanceOf(VideoSubmission::class, $retainedSubmission);
            self::assertNull($retainedSubmission->getSubmitter());
            $retainedComment = $entityManager->find(ContentComment::class, $publicCommentId);
            self::assertInstanceOf(ContentComment::class, $retainedComment);
            self::assertNull($retainedComment->getMember());
            self::assertSame('Gelöschtes Mitglied', $retainedComment->getAuthorName());
            self::assertNull($retainedComment->getFilteredByTerm());
            foreach ([
                'member_playlists' => 'owner_id',
                'member_saved_videos' => 'member_id',
                'content_ratings' => 'member_id',
                'member_video_history' => 'member_id',
                'member_external_identities' => 'member_id',
                'member_account_bans' => 'member_id',
            ] as $table => $memberColumn) {
                self::assertSame(0, self::databaseInteger($entityManager->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM '.$table.' WHERE '.$memberColumn.' = :member_id',
                    ['member_id' => $memberId],
                )));
            }
            $jobs = $client->getContainer()->get(PrivateObjectDeletionJobRepository::class);
            self::assertInstanceOf(PrivateObjectDeletionJobRepository::class, $jobs);
            self::assertSame(0, $jobs->countPendingForObjects($objectIds));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id IN (:published_id, :draft_id, :rejected_id)', [
                'published_id' => $publishedId,
                'draft_id' => $draftId,
                'rejected_id' => $rejectedDraftId,
            ]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    public function testStorageFailureLeavesPrivateRetryJobAndCleanupCommandReportsOnlyCounts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->provideVerifiedRuntime($client);
        $storage = $this->createMock(MemberAccountDeletionTestStorage::class);
        $storage->method('identifier')->willReturn('test.storage.member_account_deletion');
        $storage->method('service')->willReturn(HostingService::Storage);
        $storage->method('requiredCapabilities')->willReturn([HostingCapability::PrivateStorage]);
        $storage->method('priority')->willReturn(100);
        $storage->method('isFallback')->willReturn(false);
        $objectId = bin2hex(random_bytes(32));
        $deleteAttempts = 0;
        $storage->expects($this->exactly(2))->method('delete')->with($objectId)->willReturnCallback(
            static function (string $givenObjectId) use (&$deleteAttempts): void {
                ++$deleteAttempts;
                if (1 === $deleteAttempts) {
                    throw new PrivateStorageOperationFailed();
                }
            },
        );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $memberEmail = 'member-delete-retry-'.$suffix.'@example.test';
        $member = new MemberAccount($memberEmail, 'delretry'.$suffix, 'Delete retry member');
        $memberId = $member->getId();
        $adminPassword = 'DeleteRetry!2026-'.$suffix;
        $admin = $this->createAdministrator($client, 'admin-delete-retry-'.$suffix.'@example.test', $adminPassword);
        $draft = new Video(
            'Retry deletion '.$suffix,
            'Private file cleanup is retried safely.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            true,
        );
        $draft->attachUploadedMedia($objectId, 'video/mp4', 1024);
        $submission = new VideoSubmission($draft, $member);
        $entityManager->persist($member);
        $entityManager->persist($admin);
        $entityManager->persist($draft);
        $entityManager->persist($submission);
        $entityManager->flush();
        $draftId = $draft->getId();

        try {
            $this->loginAsAdministrator($client, $admin, $adminPassword);
            $client->followRedirect();
            $editUrl = '/admin/members/'.$memberId;
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()
                ->filter(sprintf('form[action="%s/delete"] input[name="_token"]', $editUrl))
                ->attr('value');
            $client->request('POST', $editUrl.'/delete', ['_token' => $token, 'confirm' => '1']);
            self::assertResponseRedirects('/admin/members');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertIsArray($entityManager->getConnection()->fetchAssociative(
                'SELECT id FROM private_object_deletion_jobs WHERE object_id = :object_id AND attempt_count = 1',
                ['object_id' => $objectId],
            ));
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM member_accounts WHERE id = :id)',
                ['id' => $memberId],
            ));
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM videos WHERE id = :id)',
                ['id' => $draftId],
            ));

            $entityManager->getConnection()->executeStatement(
                'UPDATE private_object_deletion_jobs SET available_at = :available_at WHERE object_id = :object_id',
                [
                    'available_at' => new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'object_id' => $objectId,
                ],
            );
            $deletions = $client->getContainer()->get(PrivateObjectDeletionService::class);
            self::assertInstanceOf(PrivateObjectDeletionService::class, $deletions);
            $command = new CleanupPrivateObjectsCommand($deletions);
            $commandTester = new CommandTester($command);
            self::assertSame(0, $commandTester->execute(['--limit' => '1']));
            self::assertStringContainsString('1 gelöscht', $commandTester->getDisplay());
            self::assertStringNotContainsString($objectId, $commandTester->getDisplay());
            self::assertStringNotContainsString($memberEmail, $commandTester->getDisplay());
            self::assertFalse((bool) $entityManager->getConnection()->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM private_object_deletion_jobs WHERE object_id = :object_id)',
                ['object_id' => $objectId],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $draftId]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $entityManager->getConnection()->executeStatement('DELETE FROM private_object_deletion_jobs WHERE object_id = :id', ['id' => $objectId]);
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
    }

    private function provideVerifiedRuntime(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
    }

    private function createAdministrator(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $email,
        string $password,
    ): AdminUser {
        $admin = new AdminUser($email);
        $admin->setRoles([AdminRole::Administrator->value]);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);

        return $admin;
    }

    private function loginAsAdministrator(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        AdminUser $admin,
        string $password,
    ): void {
        $encryptedSecret = $admin->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $admin->getUserIdentifier(),
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
        self::assertResponseRedirects('/admin');
    }

    private static function databaseInteger(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new \UnexpectedValueException('Expected an integer value from the database.');
        }

        return $integer;
    }
}

interface MemberAccountDeletionTestStorage extends PrivateObjectStorageInterface, HostingAdapterDefinitionInterface
{
}
