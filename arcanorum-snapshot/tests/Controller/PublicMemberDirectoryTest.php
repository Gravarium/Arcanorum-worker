<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\Playlist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicMemberDirectoryTest extends WebTestCase
{
    public function testDirectoryShowsOnlyOptedInActiveMembersAndNeverExposesEmail(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(5));

        $public = new MemberAccount('public-'.$suffix.'@example.test', 'public-'.$suffix, 'Alpha Mitglied');
        $public->updateProfile('Alpha Mitglied', '<script>alert(1)</script>', true);
        $private = new MemberAccount('private-'.$suffix.'@example.test', 'private-'.$suffix, 'Geheimes Mitglied');
        $suspended = new MemberAccount('suspended-'.$suffix.'@example.test', 'suspended-'.$suffix, 'Sperrmitglied');
        $suspended->updateProfile('Sperrmitglied', '', true);
        $suspended->setActive(false);
        $publicPlaylist = new Playlist($public, 'Öffentliche Liste', '', true);
        $suspendedPlaylist = new Playlist($suspended, 'Gesperrte Liste', '', true);

        try {
            foreach ([$public, $private, $suspended, $publicPlaylist, $suspendedPlaylist] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $client->request('GET', '/mitglieder');
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertStringContainsString('private', $cacheControl);
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorExists('a[href="/kanal/'.$public->getHandle().'"]');
            self::assertSelectorNotExists('a[href="/kanal/'.$private->getHandle().'"]');
            self::assertSelectorNotExists('a[href="/kanal/'.$suspended->getHandle().'"]');
            self::assertSelectorNotExists('script');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($public->getEmail(), $html);
            self::assertStringNotContainsString($private->getEmail(), $html);
            self::assertStringNotContainsString($suspended->getEmail(), $html);

            $client->request('GET', '/mitglieder?q=alpha');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/kanal/'.$public->getHandle().'"]');
            $client->request('GET', '/mitglieder?q='.$private->getHandle());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/kanal/'.$private->getHandle().'"]');
            $client->request('GET', '/mitglieder?q=%25');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/kanal/'.$public->getHandle().'"]');
            $client->request('GET', '/mitglieder?q='.$public->getEmail());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/kanal/'.$public->getHandle().'"]');

            $client->request('GET', '/mitglieder?q='.str_repeat('a', 81));
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/mitglieder?q=%0A');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/mitglieder?q[]=alpha');
            self::assertResponseStatusCodeSame(400);

            $client->request('GET', '/kanal/'.$suspended->getHandle());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/wiedergabelisten/'.$suspendedPlaylist->getId());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/wiedergabelisten/'.$publicPlaylist->getId());
            self::assertResponseIsSuccessful();
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_playlists WHERE owner_id IN (:public_id, :suspended_id)',
                ['public_id' => $public->getId(), 'suspended_id' => $suspended->getId()],
            );
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id IN (:public_id, :private_id, :suspended_id)',
                ['public_id' => $public->getId(), 'private_id' => $private->getId(), 'suspended_id' => $suspended->getId()],
            );
        }
    }
}
