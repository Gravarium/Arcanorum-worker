<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\Playlist;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberActivityFlowTest extends WebTestCase
{
    public function testMemberSeesOnlyOwnNewCommentsAndSubmissions(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $suffix = bin2hex(random_bytes(8));
        $password = 'Member-Activity-'.$suffix.'-K8!';
        $member = new MemberAccount('activity-'.$suffix.'@example.test', 'activity'.$suffix, 'My activity');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $other = new MemberAccount('other-activity-'.$suffix.'@example.test', 'other'.$suffix, 'Other activity');
        $ownPlaylist = new Playlist($member, 'Private activity list '.$suffix, 'Owner-only list details');
        $otherPlaylist = new Playlist($other, 'Foreign private list '.$suffix, 'Must stay hidden');
        $video = new Video(
            'Activity video '.$suffix,
            'Published video for member comments.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/activity-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $submittedVideo = new Video(
            'Own submitted video '.$suffix,
            'Private video submission.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/submitted-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $submission = new VideoSubmission($submittedVideo, $member);
        $otherBody = 'Other account comment '.$suffix;
        $otherComment = new ContentComment(CommentContentType::Video, $video->getId(), $video->getTitle(), 'Other', $otherBody);
        $otherComment->attributeToMember($other);
        $anonymousComment = new ContentComment(CommentContentType::Video, $video->getId(), $video->getTitle(), 'Anonymous', 'Anonymous historical '.$suffix);
        $ownBody = 'Own <script>alert(1)</script> activity '.$suffix;

        try {
            foreach ([$member, $other, $ownPlaylist, $otherPlaylist, $video, $submittedVideo, $submission, $otherComment, $anonymousComment] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $client->request('GET', '/konto/aktivitaeten');
            self::assertResponseRedirects('/konto/anmelden');

            $connection = $entityManager->getConnection();
            self::assertNull($connection->fetchOne('SELECT member_id FROM content_comments WHERE id = :id', ['id' => $anonymousComment->getId()]));

            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $member->getEmail(),
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/aktivitaeten');

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            $commentToken = (string) $client->getCrawler()->filter('form[action="/comments/video/'.$video->getId().'"] input[name="_token"]')->attr('value');
            self::assertNotEmpty($commentToken);
            $client->request('POST', '/comments/video/'.$video->getId(), [
                '_token' => $commentToken,
                'author_name' => 'My activity',
                'body' => $ownBody,
                'website' => '',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#comments');
            self::assertSame($member->getId(), $connection->fetchOne(
                'SELECT member_id FROM content_comments WHERE body = :body',
                ['body' => $ownBody],
            ));

            $client->request('GET', '/konto/aktivitaeten');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Meine Aktivitäten');
            self::assertSelectorTextContains('main', 'Own submitted video '.$suffix);
            self::assertSelectorTextContains('main', $ownBody);
            self::assertSelectorTextContains('main', 'Private activity list '.$suffix);
            self::assertSelectorTextContains('main', 'Owner-only list details');
            self::assertSelectorTextContains('main', 'Wartet auf Freigabe');
            self::assertStringNotContainsString($otherBody, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Foreign private list '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Must stay hidden', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Anonymous historical '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($member->getEmail(), (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('main script');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertNotEmpty($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('GET', '/konto/aktivitaeten?page[]=1');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/konto/aktivitaeten?page=2');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($ownBody, (string) $client->getResponse()->getContent());

            $client->request('GET', '/konto/aktivitaeten');
            $logoutToken = (string) $client->getCrawler()->filter('form[action="/konto/abmelden"] input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/abmelden', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/konto/anmelden');
            $client->request('GET', '/konto/aktivitaeten');
            self::assertResponseRedirects('/konto/anmelden');

            $connection->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $other->getId()]);
            self::assertNull($connection->fetchOne('SELECT member_id FROM content_comments WHERE id = :id', ['id' => $otherComment->getId()]));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_comments WHERE content_id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM videos WHERE id IN (:published, :submitted)', [
                'published' => $video->getId(),
                'submitted' => $submittedVideo->getId(),
            ]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id IN (:member, :other)', [
                'member' => $member->getId(),
                'other' => $other->getId(),
            ]);
        }
    }
}
