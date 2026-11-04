<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoAccessFlowTest extends WebTestCase
{
    public function testMembersOnlyVideoHidesPlayerAndThumbnailUntilVerifiedMemberSignsIn(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);

        $suffix = bin2hex(random_bytes(6));
        $slug = 'members-only-video-'.$suffix;
        $sourceUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $objectId = hash('sha256', 'members-only-thumbnail-'.$suffix);
        $thumbnail = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnail);

        $video = new Video(
            'Members-only video '.$suffix,
            'This description must remain hidden from guests.',
            null,
            VideoSourceType::Embed,
            $sourceUrl,
            PublishingStatus::Published,
            false,
            true,
        );
        $video->updateEditorialMetadata($slug, 'Editorial Desk', true, true);
        $video->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $video->attachThumbnail($objectId, 'image/png', strlen($thumbnail));

        $member = new MemberAccount(
            'video-access-'.$suffix.'@example.test',
            'videoaccess'.$suffix,
            'Verified video member',
        );
        $inactiveMember = new MemberAccount(
            'video-access-inactive-'.$suffix.'@example.test',
            'videoinactive'.$suffix,
            'Inactive video member',
        );
        $inactiveMember->setActive(false);

        $entityManager->persist($video);
        $entityManager->persist($member);
        $entityManager->persist($inactiveMember);
        $entityManager->flush();
        $storage->write($objectId, $thumbnail);

        $videoId = $video->getId();
        $memberId = $member->getId();
        $inactiveMemberId = $inactiveMember->getId();
        $connection = $entityManager->getConnection();

        try {
            $client->request('GET', '/videos/watch/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Dieses Video ist registrierten Mitgliedern vorbehalten.');
            self::assertStringNotContainsString($sourceUrl, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('This description must remain hidden', (string) $client->getResponse()->getContent());
            self::assertTrue(
                in_array($connection->fetchOne('SELECT view_count FROM videos WHERE id = :id', ['id' => $videoId]), [0, '0'], true),
                'An anonymous visit must not increment a members-only video view count.',
            );

            $client->request('GET', '/videos/'.$videoId.'/thumbnail');
            self::assertResponseStatusCodeSame(404);
            self::assertTrue(
                $client->getResponse()->headers->getCacheControlDirective('no-store'),
                'Cache-Control value: '.(string) $client->getResponse()->headers->get('Cache-Control'),
            );

            $client->request('POST', '/comments/video/'.$videoId);
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($inactiveMember, 'members');
            $client->request('GET', '/videos/watch/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Dieses Video ist registrierten Mitgliedern vorbehalten.');
            $client->request('GET', '/videos/'.$videoId.'/thumbnail');
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($member, 'members');
            $client->request('GET', '/videos/watch/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('youtube-nocookie.com/embed/', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $client->request('GET', '/videos/'.$videoId.'/thumbnail');
            self::assertResponseIsSuccessful();
            self::assertSame($thumbnail, $client->getResponse()->getContent());
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('private'));
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $entityManager->clear();
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $videoId]);
            $connection->executeStatement(
                'DELETE FROM member_accounts WHERE id IN (:member, :inactive_member)',
                ['member' => $memberId, 'inactive_member' => $inactiveMemberId],
            );
            $storage->delete($objectId);
        }
    }
}
