<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentRating;
use App\Entity\ContentRatingValue;
use App\Entity\MemberAccount;
use App\Entity\MemberSavedVideo;
use App\Entity\MemberVideoCollection;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentRatingRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class MemberVideoLibraryFlowTest extends WebTestCase
{
    public function testSavedListsRatingsAndPrivateHistoryRemainSeparateAndCanBeRemoved(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $member = new MemberAccount('library-'.$suffix.'@example.test', 'library'.$suffix, 'Private library member');
        $otherMember = new MemberAccount('library-other-'.$suffix.'@example.test', 'libother'.$suffix, 'Other private member');
        $visibleVideo = $this->publishedVideo('Visible library video '.$suffix);
        $otherVideo = $this->publishedVideo('Other member video '.$suffix);
        $draftVideo = new Video(
            'Unpublished library video '.$suffix,
            'This video must not appear in a member library.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=abcdefghijk',
            PublishingStatus::Draft,
            false,
            true,
        );
        $entityManager->persist($member);
        $entityManager->persist($otherMember);
        $entityManager->persist($visibleVideo);
        $entityManager->persist($otherVideo);
        $entityManager->persist($draftVideo);
        $entityManager->persist(MemberSavedVideo::create($otherMember, $otherVideo, MemberVideoCollection::Favorite));
        $entityManager->persist(MemberSavedVideo::create($member, $draftVideo, MemberVideoCollection::WatchLater));
        $entityManager->flush();
        $fixtureRatings = $entityManager->getRepository(ContentRating::class);
        self::assertInstanceOf(ContentRatingRepository::class, $fixtureRatings);
        $fixtureRatings->setForVideo($member, $draftVideo, ContentRatingValue::Like);

        $memberId = $member->getId();
        $otherMemberId = $otherMember->getId();
        $visibleVideoId = $visibleVideo->getId();
        $otherVideoId = $otherVideo->getId();
        $draftVideoId = $draftVideo->getId();

        try {
            $client->loginUser($member, 'members');
            $client->request('GET', '/videos/'.$visibleVideoId);
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertSelectorExists('form[action="/konto/bibliothek/watch-later/add/'.$visibleVideoId.'"]');
            self::assertSelectorExists('form[action="/konto/bibliothek/favorites/add/'.$visibleVideoId.'"]');
            self::assertSame(1, $this->countRows($client, 'member_video_history', $memberId, $visibleVideoId));

            $watchLaterToken = $this->tokenFor($client, '/konto/bibliothek/watch-later/add/'.$visibleVideoId);
            $client->request('POST', '/konto/bibliothek/watch-later/add/'.$visibleVideoId, ['_token' => $watchLaterToken]);
            self::assertResponseRedirects('/videos/'.$visibleVideoId, 303);
            $client->request('POST', '/konto/bibliothek/watch-later/add/'.$visibleVideoId, ['_token' => $watchLaterToken]);
            self::assertResponseRedirects('/videos/'.$visibleVideoId, 303);

            $client->request('GET', '/videos/'.$visibleVideoId);
            $favoriteToken = $this->tokenFor($client, '/konto/bibliothek/favorites/add/'.$visibleVideoId);
            $client->request('POST', '/konto/bibliothek/favorites/add/'.$visibleVideoId, ['_token' => $favoriteToken]);
            self::assertResponseRedirects('/videos/'.$visibleVideoId, 303);

            $ratings = $client->getContainer()->get(ContentRatingRepository::class);
            self::assertInstanceOf(ContentRatingRepository::class, $ratings);
            $currentEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $currentEntityManager);
            $freshMember = $currentEntityManager->find(MemberAccount::class, $memberId);
            $freshVideo = $currentEntityManager->find(Video::class, $visibleVideoId);
            self::assertInstanceOf(MemberAccount::class, $freshMember);
            self::assertInstanceOf(Video::class, $freshVideo);
            $ratings->setForVideo($freshMember, $freshVideo, ContentRatingValue::Like);

            $client->request('GET', '/konto/bibliothek?view=watch-later');
            self::assertResponseIsSuccessful();
            $this->assertPrivateNoStore($client);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertSelectorTextContains('h2', 'Visible library video '.$suffix);
            self::assertStringNotContainsString('Unpublished library video '.$suffix, (string) $client->getResponse()->getContent());
            self::assertSame(2, $this->countRows($client, 'member_saved_videos', $memberId, $visibleVideoId));
            self::assertSame(1, $this->countRowsForCollection($client, $memberId, $visibleVideoId, 'watch_later'));
            self::assertSame(1, $this->countRowsForCollection($client, $memberId, $visibleVideoId, 'favorite'));
            self::assertSame(0, $this->countRows($client, 'playlist_items', $memberId, $visibleVideoId));

            $client->request('GET', '/konto/bibliothek?view=favorites');
            self::assertSelectorTextContains('h2', 'Visible library video '.$suffix);
            self::assertStringNotContainsString('Other member video '.$suffix, (string) $client->getResponse()->getContent());
            self::assertSame(1, $this->countRowsForCollection($client, $otherMemberId, $otherVideoId, 'favorite'));

            $client->request('GET', '/konto/bibliothek?view=rated');
            self::assertSelectorTextContains('h2', 'Visible library video '.$suffix);
            self::assertStringNotContainsString('Unpublished library video '.$suffix, (string) $client->getResponse()->getContent());
            self::assertSelectorTextContains('.card-kicker', 'Gefällt mir');

            $client->request('GET', '/konto/bibliothek?view=history');
            self::assertSelectorTextContains('h2', 'Visible library video '.$suffix);
            self::assertStringNotContainsString($member->getEmail(), (string) $client->getResponse()->getContent());
            $clearToken = $this->tokenFor($client, '/konto/bibliothek/history/leeren');
            $client->request('POST', '/konto/bibliothek/history/leeren', ['_token' => $clearToken]);
            self::assertResponseRedirects('/konto/bibliothek?view=history', 303);
            self::assertSame(0, $this->countRows($client, 'member_video_history', $memberId, $visibleVideoId));

            $client->request('GET', '/konto/bibliothek?view=favorites');
            $removeToken = $this->tokenFor($client, '/konto/bibliothek/favorites/'.$visibleVideoId.'/entfernen');
            $client->request('POST', '/konto/bibliothek/favorites/'.$visibleVideoId.'/entfernen', ['_token' => $removeToken]);
            self::assertResponseRedirects('/konto/bibliothek?view=favorites', 303);
            self::assertSame(0, $this->countRowsForCollection($client, $memberId, $visibleVideoId, 'favorite'));

            $client->request('GET', '/konto/bibliothek?view=rated');
            $removeRatingToken = $this->tokenFor($client, '/konto/bibliothek/rated/'.$visibleVideoId.'/entfernen');
            $client->request('POST', '/konto/bibliothek/rated/'.$visibleVideoId.'/entfernen', ['_token' => $removeRatingToken]);
            self::assertResponseRedirects('/konto/bibliothek?view=rated', 303);
            self::assertSame(0, $this->countRows($client, 'content_ratings', $memberId, $visibleVideoId));

            $client->request('GET', '/konto/bibliothek?view=watch-later');
            $removeSavedToken = $this->tokenFor($client, '/konto/bibliothek/watch-later/'.$visibleVideoId.'/entfernen');
            $client->request('POST', '/konto/bibliothek/watch-later/'.$visibleVideoId.'/entfernen', ['_token' => $removeSavedToken]);
            self::assertResponseRedirects('/konto/bibliothek?view=watch-later', 303);
            self::assertSame(0, $this->countRows($client, 'member_saved_videos', $memberId, $visibleVideoId));

            $this->connectionFor($client)->executeStatement(
                'DELETE FROM videos WHERE id = :id',
                ['id' => $otherVideoId],
            );
            self::assertSame(0, $this->countRowsForCollection($client, $otherMemberId, $otherVideoId, 'favorite'));
        } finally {
            $connection = $this->connectionFor($client);
            $connection->executeStatement(
                'DELETE FROM videos WHERE id IN (:ids)',
                ['ids' => [$visibleVideoId, $otherVideoId, $draftVideoId]],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $memberId]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $otherMemberId]);
        }
    }

    public function testMemberLibraryRejectsInvalidQueryAndMutationPayloads(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(8));
        $member = new MemberAccount('library-invalid-'.$suffix.'@example.test', 'libinvalid'.$suffix, 'Private library member');
        $video = $this->publishedVideo('Invalid library video '.$suffix);
        $entityManager->persist($member);
        $entityManager->persist($video);
        $entityManager->flush();

        try {
            $client->loginUser($member, 'members');
            $brokenLimiter = self::createStub(RateLimiterFactoryInterface::class);
            $brokenLimiter->method('create')->willThrowException(new \RuntimeException('Simulated private limiter outage.'));
            $client->getContainer()->set('limiter.member_video_library_account', $brokenLimiter);

            $client->request('GET', '/konto/bibliothek?view=private');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/konto/bibliothek?view=watch-later&extra=value');
            self::assertResponseStatusCodeSame(400);
            $client->request('POST', '/konto/bibliothek/watch-later/add/'.$video->getId(), ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/videos/'.$video->getId());
            $mutationToken = $this->tokenFor($client, '/konto/bibliothek/watch-later/add/'.$video->getId());
            $client->request('POST', '/konto/bibliothek/watch-later/add/'.$video->getId(), [
                '_token' => $mutationToken,
                'unexpected' => 'value',
            ]);
            self::assertResponseStatusCodeSame(400);

            self::expectOutputRegex('/\[error\] Eine Mitgliedsmedienliste wurde wegen eines Drosselungsfehlers geschlossen\./');
            $client->request('POST', '/konto/bibliothek/watch-later/add/'.$video->getId(), ['_token' => $mutationToken]);
            self::assertResponseStatusCodeSame(503);
            self::assertSame(0, $this->countRows($client, 'member_saved_videos', $member->getId(), $video->getId()));
        } finally {
            $connection = $this->connectionFor($client);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    private function publishedVideo(string $title): Video
    {
        return new Video(
            $title,
            'A public video used by the member-library flow test.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=abcdefghijk',
            PublishingStatus::Published,
            false,
            true,
        );
    }

    private function tokenFor(KernelBrowser $client, string $action): string
    {
        $token = $client->getCrawler()->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private function countRows(KernelBrowser $client, string $table, string $memberId, string $videoId): int
    {
        self::assertContains($table, ['member_saved_videos', 'member_video_history', 'playlist_items', 'content_ratings']);
        $connection = $this->connectionFor($client);
        $sql = 'playlist_items' === $table
            ? 'SELECT COUNT(*) FROM playlist_items item INNER JOIN member_playlists playlist ON playlist.id = item.playlist_id WHERE playlist.owner_id = :member_id AND item.video_id = :video_id'
            : sprintf('SELECT COUNT(*) FROM %s WHERE member_id = :member_id AND video_id = :video_id', $table);

        return self::integerValue($connection->fetchOne($sql, ['member_id' => $memberId, 'video_id' => $videoId]));
    }

    private function countRowsForCollection(KernelBrowser $client, string $memberId, string $videoId, string $collection): int
    {
        $connection = $this->connectionFor($client);

        return self::integerValue($connection->fetchOne(
            'SELECT COUNT(*) FROM member_saved_videos WHERE member_id = :member_id AND video_id = :video_id AND collection = :collection',
            ['member_id' => $memberId, 'video_id' => $videoId, 'collection' => $collection],
        ));
    }

    private function assertPrivateNoStore(KernelBrowser $client): void
    {
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    private function connectionFor(KernelBrowser $client): Connection
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    private static function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        self::assertIsString($value);
        self::assertMatchesRegularExpression('/\A\d+\z/', $value);

        return (int) $value;
    }
}
