<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\Playlist;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberPlaylistFlowTest extends WebTestCase
{
    public function testMemberCreatesSavesAndPublishesPlaylistOnlyWithPublicProfile(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $email = 'playlist-'.bin2hex(random_bytes(6)).'@example.test';
        $handle = 'playlist-'.bin2hex(random_bytes(4));
        $password = 'PlaylistMember!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $member = self::createMember($entityManager, $passwordHasher, $email, $handle, $password);
        $videoId = null;

        try {
            self::authenticate($client, $email, $password);

            $client->request('POST', '/konto/wiedergabelisten', [
                '_token' => 'invalid',
                'name' => 'Nicht gespeichert',
                'description' => '',
                'visibility' => 'private',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/konto/wiedergabelisten');
            self::assertResponseIsSuccessful();
            $createToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/wiedergabelisten', [
                '_token' => $createToken,
                'name' => 'Meine Auswahl',
                'description' => 'Videos für später',
                'visibility' => 'public',
            ]);
            self::assertResponseRedirects('/konto/wiedergabelisten');

            $playlist = $entityManager->getRepository(Playlist::class)->findOneBy([
                'owner' => $member,
                'name' => 'Meine Auswahl',
            ]);
            self::assertInstanceOf(Playlist::class, $playlist);
            self::assertTrue($playlist->isPublic());

            $video = new Video(
                'Veröffentlichtes Video',
                'Beschreibung',
                null,
                VideoSourceType::Embed,
                'https://www.youtube.com/watch?v=abcdefghijk',
                PublishingStatus::Published,
                false,
                true,
            );
            $entityManager->persist($video);
            $entityManager->flush();
            $videoId = $video->getId();

            $client->request('GET', '/wiedergabelisten/'.$playlist->getId());
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            $addToken = (string) $client->getCrawler()->filter(
                'form[action="/konto/wiedergabelisten/video/'.$video->getId().'"] input[name="_token"]',
            )->attr('value');
            $client->request('POST', '/konto/wiedergabelisten/video/'.$video->getId(), [
                '_token' => $addToken,
                'playlist_id' => $playlist->getId(),
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId());

            $client->request('GET', '/konto/wiedergabelisten');
            self::assertSelectorTextContains('main', 'Veröffentlichtes Video');
            self::assertSelectorTextContains('main', 'Die Liste bleibt verborgen');

            $client->request('GET', '/konto/profil');
            $profileToken = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/konto/profil', [
                '_token' => $profileToken,
                'display_name' => 'Playlist Mitglied',
                'bio' => '',
                'visibility' => 'public',
            ]);
            self::assertResponseRedirects('/konto/profil');

            $client->request('GET', '/wiedergabelisten/'.$playlist->getId());
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
            self::assertSelectorTextContains('h1', 'Meine Auswahl');
            self::assertSelectorTextContains('main', 'Veröffentlichtes Video');
            self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());

            $client->request('GET', '/kanal/'.$handle);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Öffentliche Wiedergabelisten');
            self::assertStringContainsString('/wiedergabelisten/'.$playlist->getId(), (string) $client->getResponse()->getContent());

            $client->request('GET', '/konto/wiedergabelisten');
            $updateToken = (string) $client->getCrawler()->filter(
                'form[action="/konto/wiedergabelisten/'.$playlist->getId().'/bearbeiten"] input[name="_token"]',
            )->attr('value');
            $client->request('POST', '/konto/wiedergabelisten/'.$playlist->getId().'/bearbeiten', [
                '_token' => $updateToken,
                'name' => 'Nur für mich',
                'description' => '',
                'visibility' => 'private',
            ]);
            self::assertResponseRedirects('/konto/wiedergabelisten#playlist-'.$playlist->getId());
            $client->request('GET', '/wiedergabelisten/'.$playlist->getId());
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/konto/wiedergabelisten');
            $removeToken = (string) $client->getCrawler()->filter(
                'form[action="/konto/wiedergabelisten/'.$playlist->getId().'/video/'.$video->getId().'/entfernen"] input[name="_token"]',
            )->attr('value');
            $client->request('POST', '/konto/wiedergabelisten/'.$playlist->getId().'/video/'.$video->getId().'/entfernen', [
                '_token' => $removeToken,
            ]);
            self::assertResponseRedirects('/konto/wiedergabelisten#playlist-'.$playlist->getId());

            $client->request('GET', '/wiedergabelisten/'.$playlist->getId());
            self::assertResponseStatusCodeSame(404);
        } finally {
            if (is_string($videoId)) {
                $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $videoId]);
            }
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE email = :email', ['email' => $email]);
        }
    }

    public function testPrivatePlaylistIsNotPublicAndForeignDeleteTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $ownerEmail = 'owner-'.bin2hex(random_bytes(5)).'@example.test';
        $otherEmail = 'other-'.bin2hex(random_bytes(5)).'@example.test';
        $ownerPassword = 'OwnerPlaylist!2026';
        $otherPassword = 'OtherPlaylist!2026';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $owner = self::createMember($entityManager, $passwordHasher, $ownerEmail, 'owner-'.bin2hex(random_bytes(4)), $ownerPassword);
        $other = self::createMember($entityManager, $passwordHasher, $otherEmail, 'other-'.bin2hex(random_bytes(4)), $otherPassword);
        $playlist = new Playlist($owner, 'Privat', '');
        $otherPlaylist = new Playlist($other, 'Eigene Liste', '');
        $entityManager->persist($playlist);
        $entityManager->persist($otherPlaylist);
        $entityManager->flush();

        try {
            $client->request('GET', '/wiedergabelisten/'.$playlist->getId());
            self::assertResponseStatusCodeSame(404);

            self::authenticate($client, $otherEmail, $otherPassword);
            $client->request('GET', '/konto/wiedergabelisten');
            $deleteToken = (string) $client->getCrawler()->filter(
                'form[action="/konto/wiedergabelisten/'.$otherPlaylist->getId().'/loeschen"] input[name="_token"]',
            )->attr('value');
            $client->request('POST', '/konto/wiedergabelisten/'.$playlist->getId().'/loeschen', [
                '_token' => $deleteToken,
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(
                1,
                $entityManager->getRepository(Playlist::class)->count(['id' => $playlist->getId()]),
            );
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $owner->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $other->getId()]);
        }
    }

    private static function createMember(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        string $email,
        string $handle,
        string $password,
    ): MemberAccount {
        $member = new MemberAccount($email, $handle, 'Mitglied');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $entityManager->persist($member);
        $entityManager->flush();

        return $member;
    }

    private static function authenticate(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/konto/anmelden');
        $token = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/konto/anmelden', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/konto/profil');
    }
}
