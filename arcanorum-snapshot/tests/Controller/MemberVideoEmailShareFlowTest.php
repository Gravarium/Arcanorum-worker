<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MemberVideoEmailShareFlowTest extends WebTestCase
{
    public function testVerifiedMemberCanSendOnlyTheCanonicalPublicVideoLink(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => 'attacker.example.test']);
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        [$entityManager, $member, $video, $password] = $this->createFixtures($client);
        $recipient = 'friend-'.bin2hex(random_bytes(5)).'@example.test';
        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $translator = $client->getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $locale = $provider->current()->getDefaultLocale();
        $client->getContainer()->set(
            EmailDeliveryConfiguration::class,
            new EmailDeliveryConfiguration('smtp://mail.example.test:2525', 'share@example.test', $provider),
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(
            static function (RawMessage $message) use ($recipient, $member, $video, $translator, $locale): bool {
                self::assertInstanceOf(Email::class, $message);
                self::assertCount(1, $message->getTo());
                self::assertSame($recipient, $message->getTo()[0]->getAddress());
                self::assertSame('share@example.test', $message->getFrom()[0]->getAddress());
                self::assertSame($translator->trans('Ein Video wurde mit dir geteilt', [], 'messages', $locale), $message->getSubject());
                self::assertSame(
                    $translator->trans('Ein Mitglied hat ein öffentliches Video mit dir geteilt:', [], 'messages', $locale)
                    ."\n\nhttps://arcanorum.example.test/videos/{$video->getId()}\n\n"
                    .$translator->trans('Diese Nachricht wurde auf deine ausdrückliche Anfrage hin versendet. Die Empfängeradresse wird nicht im CMS gespeichert.', [], 'messages', $locale),
                    $message->getTextBody(),
                );
                self::assertStringNotContainsString($member->getEmail(), $message->getTextBody());
                self::assertStringNotContainsString('attacker.example.test', $message->getTextBody());

                return true;
            },
        ));
        $client->getContainer()->set(MailerInterface::class, $mailer);

        try {
            $this->login($client, $member, $password);
            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/konto/videos/'.$video->getId().'/teilen"]');
            self::assertSelectorExists('form[action="/konto/videos/'.$video->getId().'/teilen"] input[name="consent"][required]');
            self::assertSelectorTextContains(
                '#video-email-share',
                $translator->trans('Die Empfängeradresse wird nur zur Zustellung an den eingerichteten E-Mail-Dienst weitergegeben und nicht im CMS gespeichert.', [], 'messages', $locale),
            );

            $token = (string) $client->getCrawler()
                ->filter('form[action="/konto/videos/'.$video->getId().'/teilen"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                '_token' => $token,
                'email' => $recipient,
                'consent' => '1',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#video-email-share');
            self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', $translator->trans('Der Videolink wurde per E-Mail versendet.', [], 'messages', $locale));
            self::assertStringNotContainsString($recipient, (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    public function testSharingRequiresAuthenticationPublicVideoCsrfAndExplicitPermission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        [$entityManager, $member, $video, $password, $draft] = $this->createFixtures($client, includeDraft: true);
        self::assertInstanceOf(Video::class, $draft);
        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $client->getContainer()->set(
            EmailDeliveryConfiguration::class,
            new EmailDeliveryConfiguration('smtp://mail.example.test:2525', 'share@example.test', $provider),
        );
        $translator = $client->getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $locale = $provider->current()->getDefaultLocale();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $client->getContainer()->set(MailerInterface::class, $mailer);

        try {
            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', []);
            self::assertResponseRedirects('/konto/anmelden');

            $this->login($client, $member, $password);
            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                '_token' => 'invalid-token',
                'email' => 'friend@example.test',
                'consent' => '1',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/konto/videos/'.$draft->getId().'/teilen', [
                '_token' => 'unused-token',
                'email' => 'friend@example.test',
                'consent' => '1',
            ]);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/'.$video->getId());
            $token = (string) $client->getCrawler()
                ->filter('form[action="/konto/videos/'.$video->getId().'/teilen"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                '_token' => $token,
                'email' => 'friend@example.test',
            ]);
            self::assertResponseRedirects('/videos/'.$video->getId().'#video-email-share');
            $client->followRedirect();
            self::assertSelectorTextContains(
                '[role="alert"]',
                $translator->trans('Bitte bestätige, dass du die Erlaubnis hast, den Videolink zu senden.', [], 'messages', $locale),
            );

            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                '_token' => $token,
                'email' => 'friend@example.test',
                'consent' => '1',
                'message' => 'Unexpected field',
            ]);
            self::assertResponseStatusCodeSame(400);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id IN (:public, :draft)', [
                'public' => $video->getId(),
                'draft' => $draft->getId(),
            ]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    public function testInvalidAddressAndRateLimitNeverLeakRecipientOrTransportDetails(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        [$entityManager, $member, $video, $password] = $this->createFixtures($client);
        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $translator = $client->getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $locale = $provider->current()->getDefaultLocale();
        $client->getContainer()->set(
            EmailDeliveryConfiguration::class,
            new EmailDeliveryConfiguration('smtp://mail.example.test:2525', 'share@example.test', $provider),
        );
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(5))->method('send');
        $client->getContainer()->set(MailerInterface::class, $mailer);

        try {
            $this->login($client, $member, $password);
            $client->request('GET', '/videos/'.$video->getId());
            $token = (string) $client->getCrawler()
                ->filter('form[action="/konto/videos/'.$video->getId().'/teilen"] input[name="_token"]')
                ->attr('value');

            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                '_token' => $token,
                'email' => "friend@example.test\r\nBcc: victim@example.test",
                'consent' => '1',
            ]);
            $client->followRedirect();
            self::assertSelectorTextContains(
                '[role="alert"]',
                $translator->trans('Bitte gib eine gültige Empfängeradresse ein.', [], 'messages', $locale),
            );
            self::assertStringNotContainsString('victim@example.test', (string) $client->getResponse()->getContent());

            for ($attempt = 1; $attempt <= 6; ++$attempt) {
                $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                    '_token' => $token,
                    'email' => 'friend-'.$attempt.'@example.test',
                    'consent' => '1',
                ]);
                self::assertResponseRedirects('/videos/'.$video->getId().'#video-email-share');
                $client->followRedirect();
                self::assertResponseIsSuccessful();
            }

            self::assertSelectorTextContains(
                '[role="alert"]',
                $translator->trans('Bitte warte, bevor du weitere Videolinks per E-Mail sendest.', [], 'messages', $locale),
            );
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    public function testMailTransportFailureReturnsGenericMessage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUniqueRemoteAddress($client);
        [$entityManager, $member, $video, $password] = $this->createFixtures($client);
        $recipient = 'failure-'.bin2hex(random_bytes(5)).'@example.test';
        $provider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $translator = $client->getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $locale = $provider->current()->getDefaultLocale();
        $client->getContainer()->set(
            EmailDeliveryConfiguration::class,
            new EmailDeliveryConfiguration('smtp://mail.example.test:2525', 'share@example.test', $provider),
        );
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('private transport secret'));
        $client->getContainer()->set(MailerInterface::class, $mailer);

        try {
            $this->login($client, $member, $password);
            $client->request('GET', '/videos/'.$video->getId());
            $token = (string) $client->getCrawler()
                ->filter('form[action="/konto/videos/'.$video->getId().'/teilen"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', '/konto/videos/'.$video->getId().'/teilen', [
                '_token' => $token,
                'email' => $recipient,
                'consent' => '1',
            ]);
            $client->followRedirect();

            self::assertSelectorTextContains(
                '[role="alert"]',
                $translator->trans('Der Videolink konnte derzeit nicht per E-Mail versendet werden.', [], 'messages', $locale),
            );
            self::assertStringNotContainsString('private transport secret', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($recipient, (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM member_accounts WHERE id = :id', ['id' => $member->getId()]);
        }
    }

    /** @return array{EntityManagerInterface, MemberAccount, Video, string, Video|null} */
    private function createFixtures(KernelBrowser $client, bool $includeDraft = false): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $suffix = bin2hex(random_bytes(8));
        $password = 'Video-Share-'.$suffix.'-P9!';
        $member = new MemberAccount('video-share-'.$suffix.'@example.test', 'share'.$suffix, 'Video Share Member');
        $member->setPassword($hasher->hashPassword($member, $password));
        $video = new Video(
            'Shareable video '.$suffix,
            'A public video for the email sharing flow.',
            null,
            VideoSourceType::Url,
            'https://media.example.test/video-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $entities = [$member, $video];
        $draft = null;
        if ($includeDraft) {
            $draft = new Video(
                'Private draft '.$suffix,
                'A private video that cannot be shared.',
                null,
                VideoSourceType::Url,
                'https://media.example.test/draft-'.$suffix.'.mp4',
                PublishingStatus::Draft,
                false,
                false,
            );
            $entities[] = $draft;
        }
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [$entityManager, $member, $video, $password, $draft];
    }

    private function login(KernelBrowser $client, MemberAccount $member, string $password): void
    {
        $client->request('GET', '/konto/anmelden');
        self::assertResponseIsSuccessful();
        $token = (string) $client->getCrawler()->filter('input[name="_csrf_token"]')->attr('value');
        $client->submitForm('Anmelden', [
            '_username' => $member->getEmail(),
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects();
    }

    private function setUniqueRemoteAddress(KernelBrowser $client): void
    {
        $address = inet_ntop(random_bytes(16));
        if (false === $address) {
            throw new \RuntimeException('Could not generate a test client address.');
        }

        $client->setServerParameter('REMOTE_ADDR', $address);
    }
}
