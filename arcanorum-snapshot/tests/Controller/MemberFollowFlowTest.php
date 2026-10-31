<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\MemberFollow;
use App\Entity\NewsArticle;
use App\Entity\Playlist;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Repository\MemberFollowRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberFollowFlowTest extends WebTestCase
{
    public function testFollowingFeedSuggestionsPrivacyAndRateLimit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);
        $remoteAddress = inet_ntop(random_bytes(16));
        if (false === $remoteAddress) {
            throw new \RuntimeException('Could not generate a test client address.');
        }
        $client->setServerParameter('REMOTE_ADDR', $remoteAddress);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $followRepository = $client->getContainer()->get(MemberFollowRepository::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(MemberFollowRepository::class, $followRepository);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $suffix = bin2hex(random_bytes(6));
        $password = 'Member-Follow-'.$suffix.'-T8!';
        $viewer = new MemberAccount('follow-viewer-'.$suffix.'@example.test', 'viewer'.$suffix, 'Feed owner '.$suffix);
        $viewer->updateProfile('Feed owner '.$suffix, '', true);
        $viewer->setPassword($passwordHasher->hashPassword($viewer, $password));
        $followed = new MemberAccount('followed-'.$suffix.'@example.test', 'followed'.$suffix, 'Public author '.$suffix);
        $followed->updateProfile('Public <script>alert(1)</script> author '.$suffix, '', true);
        $otherFollower = new MemberAccount('other-follower-'.$suffix.'@example.test', 'other'.$suffix, 'Other public member '.$suffix);
        $otherFollower->updateProfile('Other public member '.$suffix, '', true);
        $private = new MemberAccount('private-follow-'.$suffix.'@example.test', 'private'.$suffix, 'Private member '.$suffix);
        $suspended = new MemberAccount('suspended-follow-'.$suffix.'@example.test', 'suspend'.$suffix, 'Suspended member '.$suffix);
        $suspended->updateProfile('Suspended member '.$suffix, '', true);
        $suspended->setActive(false);
        $unverified = new MemberAccount('unverified-follow-'.$suffix.'@example.test', 'unverified'.$suffix, 'Unverified member '.$suffix);
        $unverified->updateProfile('Unverified member '.$suffix, '', true);
        $unverified->requireEmailVerification();

        $publicVideo = new Video(
            'Public feed video '.$suffix,
            'A published video from a followed member.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/public-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $approvedSubmission = new VideoSubmission($publicVideo, $followed);
        $approvedSubmission->resolve(VideoSubmissionStatus::Approved);
        $draftVideo = new Video(
            'Private draft video '.$suffix,
            'A draft must never appear in the following feed.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/draft-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $pendingSubmission = new VideoSubmission($draftVideo, $followed);
        $unfollowedVideo = new Video(
            'Unfollowed member video '.$suffix,
            'This event belongs to a public member the viewer does not follow.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/unfollowed-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $unfollowedSubmission = new VideoSubmission($unfollowedVideo, $otherFollower);
        $unfollowedSubmission->resolve(VideoSubmissionStatus::Approved);
        $publicPlaylist = new Playlist($followed, 'Public feed playlist '.$suffix, '', true);
        $privatePlaylist = new Playlist($followed, 'Private feed playlist '.$suffix, '', false);
        $news = new NewsArticle(
            'Public feed article '.$suffix,
            'public-feed-'.$suffix,
            null,
            null,
            'Published article body.',
            PublishingStatus::Published,
            false,
            true,
            'Test editor',
        );
        $approvedVideoComment = new ContentComment(
            CommentContentType::Video,
            $publicVideo->getId(),
            $publicVideo->getTitle(),
            'Public author',
            'Approved public comment '.$suffix,
        );
        $approvedVideoComment->attributeToMember($followed);
        $approvedVideoComment->approve();
        $pendingVideoComment = new ContentComment(
            CommentContentType::Video,
            $publicVideo->getId(),
            $publicVideo->getTitle(),
            'Public author',
            'Pending private comment '.$suffix,
        );
        $pendingVideoComment->attributeToMember($followed);
        $approvedNewsComment = new ContentComment(
            CommentContentType::News,
            $news->getId(),
            $news->getTitle(),
            'Public author',
            'Approved news comment '.$suffix,
        );
        $approvedNewsComment->attributeToMember($followed);
        $approvedNewsComment->approve();
        $suggestedMembers = [];
        for ($index = 0; $index < 21; ++$index) {
            $candidate = new MemberAccount(
                'suggestion-'.$index.'-'.$suffix.'@example.test',
                'suggest-'.$suffix.'-'.$index,
                'Suggestion '.$index.' '.$suffix,
            );
            $candidate->updateProfile($candidate->getDisplayName(), '', true);
            $suggestedMembers[] = $candidate;
        }

        $allAccounts = [$viewer, $followed, $otherFollower, $private, $suspended, $unverified, ...$suggestedMembers];
        $allComments = [$approvedVideoComment, $pendingVideoComment, $approvedNewsComment];
        try {
            foreach ([
                ...$allAccounts,
                $publicVideo,
                $approvedSubmission,
                $draftVideo,
                $pendingSubmission,
                $unfollowedVideo,
                $unfollowedSubmission,
                $publicPlaylist,
                $privatePlaylist,
                $news,
                ...$allComments,
                new MemberFollow($otherFollower, $viewer),
            ] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $followNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            self::assertFalse($followRepository->follow($viewer, $viewer, $followNow));
            self::assertFalse($followRepository->follow($viewer, $private, $followNow));
            self::assertFalse($followRepository->follow($viewer, $unverified, $followNow));

            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $viewer->getEmail(),
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');

            $client->request('GET', '/kanal/'.$followed->getHandle());
            self::assertResponseIsSuccessful();
            $action = '/konto/folgen/'.$followed->getHandle().'/folgen';
            $token = (string) $client->getCrawler()->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', $action, ['_token' => $token, 'unexpected' => 'rejected']);
            self::assertResponseStatusCodeSame(400);
            self::assertContains($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM member_follows WHERE follower_id = :follower AND followed_id = :followed',
                ['follower' => $viewer->getId(), 'followed' => $followed->getId()],
            ), [0, '0']);

            $client->request('POST', $action, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $action, ['_token' => $token]);
            self::assertResponseRedirects('/kanal/'.$followed->getHandle(), 303);
            $client->request('POST', $action, ['_token' => $token]);
            self::assertResponseRedirects('/kanal/'.$followed->getHandle(), 303);
            self::assertContains($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM member_follows WHERE follower_id = :follower AND followed_id = :followed',
                ['follower' => $viewer->getId(), 'followed' => $followed->getId()],
            ), [1, '1']);

            $client->request('GET', '/konto/folgen?view=following');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $followed->getDisplayName());
            self::assertStringNotContainsString($followed->getEmail(), (string) $client->getResponse()->getContent());
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertNotEmpty($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('GET', '/konto/folgen?view=followers');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $otherFollower->getDisplayName());
            self::assertStringNotContainsString($otherFollower->getEmail(), (string) $client->getResponse()->getContent());

            $client->request('GET', '/konto/folgen?view=suggestions');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav.pagination a');
            self::assertStringNotContainsString($viewer->getDisplayName(), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($private->getDisplayName(), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($suspended->getDisplayName(), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($unverified->getDisplayName(), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($followed->getDisplayName(), (string) $client->getResponse()->getContent());
            $client->request('GET', '/konto/folgen?view=suggestions&page=2');
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $client->getCrawler()->filter('article.content-card')->count());
            $client->request('GET', '/konto/folgen?view=suggestions&page[]=1');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/konto/folgen?view=unknown');
            self::assertResponseStatusCodeSame(400);

            $client->request('GET', '/konto/folgen?view=feed');
            self::assertResponseIsSuccessful();
            $feedHtml = (string) $client->getResponse()->getContent();
            foreach ([
                $publicVideo->getTitle(),
                $publicPlaylist->getName(),
                $news->getTitle(),
            ] as $publicActivityTitle) {
                self::assertStringContainsString($publicActivityTitle, $feedHtml);
            }
            self::assertStringContainsString('hat ein veröffentlichtes Video kommentiert:', $feedHtml);
            self::assertStringContainsString('hat einen veröffentlichten Artikel kommentiert:', $feedHtml);
            self::assertStringNotContainsString($draftVideo->getTitle(), $feedHtml);
            self::assertStringNotContainsString($privatePlaylist->getName(), $feedHtml);
            self::assertStringNotContainsString($pendingVideoComment->getBody(), $feedHtml);
            self::assertStringNotContainsString($unfollowedVideo->getTitle(), $feedHtml);
            self::assertStringNotContainsString('<script>', $feedHtml);

            $client->request('GET', '/kanal/'.$followed->getHandle());
            $unfollowAction = '/konto/folgen/'.$followed->getHandle().'/entfolgen';
            $unfollowToken = (string) $client->getCrawler()->filter('form[action="'.$unfollowAction.'"] input[name="_token"]')->attr('value');
            $client->request('POST', $unfollowAction, ['_token' => $unfollowToken]);
            self::assertResponseRedirects('/kanal/'.$followed->getHandle(), 303);
            self::assertContains($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM member_follows WHERE follower_id = :follower AND followed_id = :followed',
                ['follower' => $viewer->getId(), 'followed' => $followed->getId()],
            ), [0, '0']);

            for ($attempt = 0; $attempt < 27; ++$attempt) {
                $client->request('POST', $action, ['_token' => $token]);
                self::assertResponseRedirects('/kanal/'.$followed->getHandle(), 303);
            }
            $client->request('POST', $action, ['_token' => $token]);
            self::assertResponseStatusCodeSame(429);

            $currentEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $currentEntityManager);
            $managedFollowed = $currentEntityManager->getRepository(MemberAccount::class)->find($followed->getId());
            self::assertInstanceOf(MemberAccount::class, $managedFollowed);
            $managedFollowed->updateProfile($managedFollowed->getDisplayName(), '', false);
            $currentEntityManager->flush();
            $currentFollowRepository = $client->getContainer()->get(MemberFollowRepository::class);
            self::assertInstanceOf(MemberFollowRepository::class, $currentFollowRepository);
            $storedProfileVisibility = $currentEntityManager->getConnection()->fetchOne(
                'SELECT public_profile FROM member_accounts WHERE id = :id',
                ['id' => $managedFollowed->getId()],
            );
            self::assertFalse(
                filter_var($storedProfileVisibility, FILTER_VALIDATE_BOOLEAN),
                'The private profile setting must be stored before reading the activity feed.',
            );
            $privateActivities = $currentFollowRepository->findPublicActivityFor($viewer, 100, 0);
            $privateVideoActivity = array_values(array_filter(
                $privateActivities,
                static fn (array $activity): bool => $activity['target_title'] === $publicVideo->getTitle(),
            ));
            self::assertNotContains(
                $publicVideo->getTitle(),
                array_column($privateActivities, 'target_title'),
                'Private video activity still appears in the query; matching rows: '.json_encode($privateVideoActivity, JSON_THROW_ON_ERROR),
            );
            $client->request('GET', '/konto/folgen?view=following');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($followed->getDisplayName(), (string) $client->getResponse()->getContent());
            $client->request('GET', '/konto/folgen?view=feed');
            self::assertResponseIsSuccessful();
            $feedHtml = (string) $client->getResponse()->getContent();
            self::assertFalse(
                str_contains($feedHtml, $publicVideo->getTitle()),
                'The activity page must exclude video activity from a private profile.',
            );
            self::assertFalse(
                str_contains($feedHtml, $publicPlaylist->getName()),
                'The activity page must exclude playlist activity from a private profile.',
            );
            $client->request('GET', '/konto/folgen?view=suggestions');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($followed->getDisplayName(), (string) $client->getResponse()->getContent());

            $client->request('GET', '/konto/folgen?view=feed&page=2');
            self::assertResponseIsSuccessful();
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupEntityManager);
            $connection = $cleanupEntityManager->getConnection();
            $accountIds = array_map(static fn (MemberAccount $account): string => $account->getId(), $allAccounts);
            $connection->executeStatement(
                'DELETE FROM member_follows WHERE follower_id IN (:follower_ids) OR followed_id IN (:followed_ids)',
                ['follower_ids' => $accountIds, 'followed_ids' => $accountIds],
                ['follower_ids' => ArrayParameterType::STRING, 'followed_ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement('DELETE FROM content_comments WHERE id IN (:ids)', [
                'ids' => array_map(static fn (ContentComment $comment): string => $comment->getId(), $allComments),
            ], ['ids' => ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM video_submissions WHERE video_id IN (:public_video, :draft_video)', [
                'public_video' => $publicVideo->getId(),
                'draft_video' => $draftVideo->getId(),
            ]);
            $connection->executeStatement('DELETE FROM video_submissions WHERE video_id = :video', ['video' => $unfollowedVideo->getId()]);
            $connection->executeStatement('DELETE FROM videos WHERE id IN (:public_video, :draft_video, :unfollowed_video)', [
                'public_video' => $publicVideo->getId(),
                'draft_video' => $draftVideo->getId(),
                'unfollowed_video' => $unfollowedVideo->getId(),
            ]);
            $connection->executeStatement('DELETE FROM member_playlists WHERE id IN (:public_playlist, :private_playlist)', [
                'public_playlist' => $publicPlaylist->getId(),
                'private_playlist' => $privatePlaylist->getId(),
            ]);
            $connection->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $news->getId()]);
            $connection->executeStatement(
                'DELETE FROM member_accounts WHERE id IN (:ids)',
                ['ids' => $accountIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }
}
