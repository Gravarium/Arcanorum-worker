<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MemberSubmissionManagementFlowTest extends WebTestCase
{
    public function testMemberCanReviseAndDeleteOnlyTheirUnapprovedSubmission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        $password = 'SubmissionManagement!2026';
        $owner = new MemberAccount(
            'submission-owner-'.bin2hex(random_bytes(6)).'@example.test',
            'submission-owner-'.bin2hex(random_bytes(4)),
            'Einreichendes Mitglied',
        );
        $owner->setPassword($passwordHasher->hashPassword($owner, $password));
        $other = new MemberAccount(
            'submission-other-'.bin2hex(random_bytes(6)).'@example.test',
            'submission-other-'.bin2hex(random_bytes(4)),
            'Anderes Mitglied',
        );
        $otherPassword = 'OtherSubmission!2026';
        $other->setPassword($passwordHasher->hashPassword($other, $otherPassword));
        $originalTitle = 'Manageable submission '.bin2hex(random_bytes(6));
        $video = new Video(
            $originalTitle,
            'A private external video suggestion.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            false,
        );
        $submission = new VideoSubmission($video, $owner);
        $submission->resolve(VideoSubmissionStatus::Rejected);
        $entityManager->persist($owner);
        $entityManager->persist($other);
        $entityManager->persist($video);
        $entityManager->persist($submission);
        $entityManager->flush();
        $submissionId = $submission->getId();
        $videoId = $video->getId();

        try {
            $this->login($client, $owner->getEmail(), $password, '/konto/profil');
            $client->request('GET', '/konto/einreichungen/'.$submissionId.'/bearbeiten');
            self::assertResponseIsSuccessful();
            $cacheDirectives = array_map(
                static fn (string $directive): string => strtolower(trim($directive)),
                explode(',', (string) $client->getResponse()->headers->get('Cache-Control')),
            );
            self::assertContains('no-store', $cacheDirectives);
            self::assertContains('private', $cacheDirectives);

            $client->submitForm('Änderungen speichern und erneut prüfen lassen', [
                'title' => 'Revised community video',
                'description' => 'A revised description awaiting moderation.',
                'category' => '',
            ]);
            self::assertResponseRedirects('/konto/einreichungen');
            $client->followRedirect();
            self::assertSelectorTextContains('main', 'Revised community video');

            $entityManager->clear();
            $storedSubmission = $entityManager->getRepository(VideoSubmission::class)->find($submissionId);
            self::assertInstanceOf(VideoSubmission::class, $storedSubmission);
            self::assertSame(VideoSubmissionStatus::Pending, $storedSubmission->getStatus());
            self::assertNull($storedSubmission->getReviewedAt());
            self::assertSame('Revised community video', $storedSubmission->getVideo()->getTitle());
            self::assertSame(PublishingStatus::Draft, $storedSubmission->getVideo()->getStatus());

            $client->request('GET', '/konto/profil');
            $logoutToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/abmelden', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/konto/anmelden');
            $this->login($client, $other->getEmail(), $otherPassword, '/konto/profil');

            $client->request('GET', '/konto/einreichungen/'.$submissionId.'/bearbeiten');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/konto/einreichungen/'.$submissionId.'/loeschen', ['_token' => 'not-the-owner-token']);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/konto/profil');
            $logoutToken = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/konto/abmelden', ['_csrf_token' => $logoutToken]);
            self::assertResponseRedirects('/konto/anmelden');
            $this->login($client, $owner->getEmail(), $password, '/konto/profil');
            $client->request('GET', '/konto/einreichungen');
            self::assertResponseIsSuccessful();
            $deleteToken = (string) $client->getCrawler()
                ->filter('form[action="/konto/einreichungen/'.$submissionId.'/loeschen"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', '/konto/einreichungen/'.$submissionId.'/loeschen', ['_token' => $deleteToken]);
            self::assertResponseRedirects('/konto/einreichungen');
            $client->followRedirect();

            $entityManager->clear();
            self::assertNull($entityManager->getRepository(VideoSubmission::class)->find($submissionId));
            self::assertNull($entityManager->getRepository(Video::class)->find($videoId));
        } finally {
            $entityManager->clear();
            $storedSubmission = $entityManager->getRepository(VideoSubmission::class)->find($submissionId);
            if ($storedSubmission instanceof VideoSubmission) {
                $entityManager->remove($storedSubmission);
            }
            $storedVideo = $entityManager->getRepository(Video::class)->find($videoId);
            if ($storedVideo instanceof Video) {
                $entityManager->remove($storedVideo);
            }
            $storedOwner = $entityManager->getRepository(MemberAccount::class)->find($owner->getId());
            if ($storedOwner instanceof MemberAccount) {
                $entityManager->remove($storedOwner);
            }
            $storedOther = $entityManager->getRepository(MemberAccount::class)->find($other->getId());
            if ($storedOther instanceof MemberAccount) {
                $entityManager->remove($storedOther);
            }
            $entityManager->flush();
        }
    }

    private function login(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $email, string $password, string $target): void
    {
        $client->request('GET', '/konto/anmelden');
        $token = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/konto/anmelden', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects($target);
    }
}
