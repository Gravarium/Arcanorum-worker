<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\MemberActivityModerationStatus;
use App\Entity\MemberActivityType;
use App\Entity\MemberFollow;
use App\Entity\NewsArticle;
use App\Entity\Playlist;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\MemberFollowRepository;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberActivityAdminFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('A', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testAdminCanFilterHideRestoreAndRemoveFeedEntriesWithoutChangingContent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        $suffix = bin2hex(random_bytes(8));
        $password = 'MemberActivityAdmin-'.$suffix.'-Z6!';
        $admin = new AdminUser('activity-admin-'.$suffix.'@example.test');
        $admin->setRoles([AdminRole::Administrator->value]);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);

        $viewer = new MemberAccount('activity-viewer-'.$suffix.'@example.test', 'viewer'.$suffix, 'Feed viewer '.$suffix);
        $author = new MemberAccount('activity-author-'.$suffix.'@example.test', 'author'.$suffix, 'Public author '.$suffix);
        $author->updateProfile($author->getDisplayName(), '', true);
        $video = new Video(
            '<script>alert("activity-'.$suffix.'")</script>',
            'Video source must remain published after activity moderation.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/member-activity-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $submission = new VideoSubmission($video, $author);
        $submission->resolve(VideoSubmissionStatus::Approved);
        $playlist = new Playlist($author, 'Public activity playlist '.$suffix, '', true);
        $article = new NewsArticle(
            'Public activity news '.$suffix,
            'member-activity-'.$suffix,
            null,
            null,
            'The news article remains unchanged after comment moderation.',
            PublishingStatus::Published,
            false,
            true,
            'Test editor',
        );
        $videoComment = new ContentComment(
            CommentContentType::Video,
            $video->getId(),
            $video->getTitle(),
            'Public author',
            'Video comment body stays private in the admin feed '.$suffix,
        );
        $videoComment->attributeToMember($author);
        $videoComment->approve();
        $newsComment = new ContentComment(
            CommentContentType::News,
            $article->getId(),
            $article->getTitle(),
            'Public author',
            'News comment body stays private in the admin feed '.$suffix,
        );
        $newsComment->attributeToMember($author);
        $newsComment->approve();

        $activities = [
            [MemberActivityType::VideoSubmission, $submission->getId()],
            [MemberActivityType::Playlist, $playlist->getId()],
            [MemberActivityType::VideoComment, $videoComment->getId()],
            [MemberActivityType::NewsComment, $newsComment->getId()],
        ];

        try {
            foreach ([$admin, $viewer, $author, $video, $submission, $playlist, $article, $videoComment, $newsComment, new MemberFollow($viewer, $author)] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $client->request('GET', '/admin/member-activities');
            self::assertResponseRedirects('/admin/login');
            $this->loginAsAdministrator($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', '/admin/member-activities?q=author'.$suffix.'&type=video_comment&status=all');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertSelectorTextContains('main', $videoComment->getContentTitle());
            $responseContent = $client->getResponse()->getContent();
            self::assertIsString($responseContent);
            self::assertStringNotContainsString($author->getEmail(), $responseContent);
            self::assertStringNotContainsString($videoComment->getBody(), $responseContent);
            self::assertStringNotContainsString('<script>alert("activity-'.$suffix.'")</script>', $responseContent);
            self::assertStringContainsString('&lt;script&gt;', $responseContent);

            $client->request('GET', '/admin/member-activities?q=author%25&type=video_comment');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Keine öffentlichen Aktivitäten gefunden.');

            $client->request('GET', '/admin/member-activities?type[]=video_comment');
            self::assertResponseStatusCodeSame(400);

            $feedRepository = $client->getContainer()->get(MemberFollowRepository::class);
            self::assertInstanceOf(MemberFollowRepository::class, $feedRepository);
            self::assertCount(4, $feedRepository->findPublicActivityFor($viewer, 20, 0));

            $token = $this->moderationToken($client);
            $client->request('POST', '/admin/member-activities/moderate', $this->moderationPayload(
                $token,
                'hide',
                $activities,
            ));
            self::assertResponseRedirects();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertCount(0, $feedRepository->findPublicActivityFor($viewer, 20, 0));
            self::assertSame('published', $connection->fetchOne('SELECT status FROM videos WHERE id = :id', ['id' => $video->getId()]));
            self::assertSame('approved', $connection->fetchOne('SELECT status FROM video_submissions WHERE id = :id', ['id' => $submission->getId()]));
            self::assertSame('Public activity playlist '.$suffix, $connection->fetchOne('SELECT name FROM member_playlists WHERE id = :id', ['id' => $playlist->getId()]));
            self::assertSame('Video comment body stays private in the admin feed '.$suffix, $connection->fetchOne('SELECT body FROM content_comments WHERE id = :id', ['id' => $videoComment->getId()]));

            $client->request('GET', '/admin/member-activities?status=hidden&type=all');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Ausgeblendet');
            self::assertSame(4, self::databaseInteger($connection->fetchOne('SELECT COUNT(*) FROM member_activity_moderations WHERE status = :status', ['status' => MemberActivityModerationStatus::Hidden->value])));

            $token = $this->moderationToken($client);
            $client->request('POST', '/admin/member-activities/moderate', $this->moderationPayload(
                $token,
                'restore',
                $activities,
            ));
            self::assertResponseRedirects();
            self::assertCount(4, $feedRepository->findPublicActivityFor($viewer, 20, 0));

            $token = $this->moderationToken($client);
            $client->request('POST', '/admin/member-activities/moderate', $this->moderationPayload(
                'invalid-token',
                'remove',
                [[$activities[0][0], $activities[0][1]]],
            ));
            self::assertResponseStatusCodeSame(403);
            self::assertCount(4, $feedRepository->findPublicActivityFor($viewer, 20, 0));

            $client->request('POST', '/admin/member-activities/moderate', $this->moderationPayload(
                $token,
                'remove',
                [[$activities[0][0], $activities[0][1]], [MemberActivityType::Playlist, str_repeat('f', 32)]],
            ));
            self::assertResponseStatusCodeSame(409);
            self::assertCount(4, $feedRepository->findPublicActivityFor($viewer, 20, 0));

            $client->request('POST', '/admin/member-activities/moderate', $this->moderationPayload(
                $token,
                'remove',
                [[$activities[0][0], $activities[0][1]]],
            ));
            self::assertResponseRedirects();
            self::assertCount(3, $feedRepository->findPublicActivityFor($viewer, 20, 0));
            self::assertSame(1, self::databaseInteger($connection->fetchOne(
                'SELECT COUNT(*) FROM member_activity_moderations WHERE activity_type = :type AND video_submission_id = :id AND status = :status',
                ['type' => MemberActivityType::VideoSubmission->value, 'id' => $submission->getId(), 'status' => MemberActivityModerationStatus::Removed->value],
            )));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testCommentModeratorCannotManageTheMemberActivityStream(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $password = 'CommentModerator-'.$suffix.'-H5!';
        $moderator = new AdminUser('comment-moderator-'.$suffix.'@example.test');
        $moderator->setRoles([AdminRole::CommentModerator->value]);
        $moderator->setPassword($hasher->hashPassword($moderator, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($moderator);

        try {
            $entityManager->persist($moderator);
            $entityManager->flush();
            $this->loginAsAdministrator($client, $moderator, $password);
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/admin/member-activities');
            self::assertResponseStatusCodeSame(403);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    /** @param list<array{MemberActivityType, string}> $activities
     * @return array{_token: string, action: string, activities: list<string>, q: string, type: string, status: string, page: string}
     */
    private function moderationPayload(string $token, string $action, array $activities): array
    {
        return [
            '_token' => $token,
            'action' => $action,
            'activities' => array_map(static fn (array $activity): string => $activity[0]->value.':'.$activity[1], $activities),
            'q' => '',
            'type' => 'all',
            'status' => 'all',
            'page' => '1',
        ];
    }

    private function moderationToken(KernelBrowser $client): string
    {
        $client->request('GET', '/admin/member-activities');
        self::assertResponseIsSuccessful();
        $token = $client->getCrawler()->filter('form[action="/admin/member-activities/moderate"] input[name="_token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private function loginAsAdministrator(KernelBrowser $client, AdminUser $admin, string $password): void
    {
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
