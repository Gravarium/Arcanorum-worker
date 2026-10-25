<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\Video;
use App\Entity\VideoSubmission;
use App\Platform\Hosting\HostingAdapterDefinitionInterface;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingService;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Repository\VideoSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberSubmissionHistoryFlowTest extends WebTestCase
{
    public function testMemberSeesOnlyTheirPrivateSubmissionHistoryAndDeletionRetainsSubmission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $storage = $this->createMock(MemberSubmissionHistoryTestStorage::class);
        $storage->method('identifier')->willReturn('test.storage.member_history');
        $storage->method('service')->willReturn(HostingService::Storage);
        $storage->method('requiredCapabilities')->willReturn([HostingCapability::PrivateStorage]);
        $storage->method('priority')->willReturn(100);
        $storage->method('isFallback')->willReturn(false);
        $videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
        $storage
            ->expects($this->once())
            ->method('write')
            ->with(
                self::callback(static fn (string $objectId): bool => 1 === preg_match('/\A[a-f0-9]{64}\z/', $objectId)),
                $videoBytes,
            );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);

        $email = 'member-submission-'.bin2hex(random_bytes(6)).'@example.test';
        $otherEmail = 'other-submission-'.bin2hex(random_bytes(6)).'@example.test';
        $password = 'SubmissionHistory!2026';
        $handle = 'submission-'.bin2hex(random_bytes(4));
        $otherHandle = 'other-submission-'.bin2hex(random_bytes(4));
        $title = 'Member submission '.bin2hex(random_bytes(8));
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-member-video-');
        if (false === $path) {
            throw new \RuntimeException('Could not create the member video submission test file.');
        }
        if (false === file_put_contents($path, $videoBytes)) {
            unlink($path);
            throw new \RuntimeException('Could not write the member video submission test file.');
        }

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $member = new MemberAccount($email, $handle, 'Mitglied mit Einreichung');
        $member->setPassword($passwordHasher->hashPassword($member, $password));
        $otherMember = new MemberAccount($otherEmail, $otherHandle, 'Anderes Mitglied');
        $otherPassword = 'OtherHistory!2026';
        $otherMember->setPassword($passwordHasher->hashPassword($otherMember, $otherPassword));
        $entityManager->persist($member);
        $entityManager->persist($otherMember);
        $entityManager->flush();
        $memberId = $member->getId();
        $otherMemberId = $otherMember->getId();

        try {
            $client->request('GET', '/konto/anmelden');
            $loginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => $loginToken,
            ]);
            self::assertResponseRedirects('/konto/profil');

            $client->request('GET', '/video-submissions');
            self::assertResponseIsSuccessful();
            $client->submitForm('Zur Prüfung einreichen', [
                'title' => $title,
                'description' => 'A private member submission awaiting editorial review.',
                'category' => '',
                'video_file' => new UploadedFile($path, 'member-video.mp4', 'application/octet-stream', null, true),
                'website' => '',
            ]);
            self::assertResponseRedirects('/video-submissions');
            $client->followRedirect();
            self::assertSelectorTextContains('main', 'zur Prüfung vorgemerkt');

            $video = $entityManager->getRepository(Video::class)->findOneBy(['title' => $title]);
            self::assertInstanceOf(Video::class, $video);
            $videoId = $video->getId();
            $submission = $entityManager->getRepository(VideoSubmission::class)->findOneBy(['video' => $video]);
            self::assertInstanceOf(VideoSubmission::class, $submission);
            self::assertSame($memberId, $submission->getSubmitter()?->getId());

            $client->request('GET', '/konto/einreichungen');
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains('no-store', $cacheDirectives);
            self::assertContains('private', $cacheDirectives);
            self::assertSelectorTextContains('main', $title);
            self::assertSelectorTextContains('main', 'Wartet auf Prüfung');

            $submissionRepository = $entityManager->getRepository(VideoSubmission::class);
            self::assertInstanceOf(VideoSubmissionRepository::class, $submissionRepository);
            self::assertSame([], $submissionRepository->findForSubmitter($otherMember));

            $client->request('GET', '/konto/profil');
            $logoutToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/abmelden', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/konto/einreichungen');
            self::assertResponseRedirects('/konto/anmelden');

            $client->request('GET', '/konto/anmelden');
            $otherLoginToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/anmelden', [
                '_username' => $otherEmail,
                '_password' => $otherPassword,
                '_csrf_token' => $otherLoginToken,
            ]);
            self::assertResponseRedirects('/konto/einreichungen');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($title, (string) $client->getResponse()->getContent());

            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id = :id',
                ['id' => $memberId],
            );
            $entityManager->clear();
            $orphanedSubmission = $entityManager->getRepository(VideoSubmission::class)
                ->createQueryBuilder('submission')
                ->innerJoin('submission.video', 'video')
                ->andWhere('video.id = :videoId')
                ->setParameter('videoId', $videoId)
                ->getQuery()
                ->getOneOrNullResult();
            self::assertInstanceOf(VideoSubmission::class, $orphanedSubmission);
            self::assertNull($orphanedSubmission->getSubmitter());
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (SELECT id FROM videos WHERE title = :title)',
                ['title' => $title],
            );
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM member_accounts WHERE id IN (:member_id, :other_member_id)',
                ['member_id' => $memberId, 'other_member_id' => $otherMemberId],
            );
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

interface MemberSubmissionHistoryTestStorage extends PrivateObjectStorageInterface, HostingAdapterDefinitionInterface
{
}
