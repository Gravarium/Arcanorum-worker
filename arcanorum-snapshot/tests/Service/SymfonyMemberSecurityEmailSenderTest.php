<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MemberAccount;
use App\Entity\SiteSettings;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\SymfonyMemberSecurityEmailSender;
use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SymfonyMemberSecurityEmailSenderTest extends KernelTestCase
{
    public function testMessageUsesConfiguredHttpsOriginInsteadOfRequestHost(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        $router->getContext()->setHost('untrusted-request-host.example');
        $mailer = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };
        $delivery = new EmailDeliveryConfiguration('smtp://mail.example.test', 'security@example.test', $settings);
        $sender = new SymfonyMemberSecurityEmailSender(static fn (): MailerInterface => $mailer, $router, 'https://canonical.example/arcanorum', $delivery, $settings, $this->translator());
        $member = new MemberAccount('member@example.test', 'security-test', 'Security test');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $sender->send($member, MemberEmailChallengePurpose::EmailVerification, $token);

        self::assertInstanceOf(Email::class, $mailer->message);
        self::assertStringContainsString('https://canonical.example/arcanorum/konto/verifizieren/'.$token, (string) $mailer->message->getTextBody());
        self::assertStringNotContainsString('untrusted-request-host.example', (string) $mailer->message->getTextBody());
        self::assertSame('member@example.test', $mailer->message->getTo()[0]->getAddress());
        self::assertSame('security@example.test', $mailer->message->getFrom()[0]->getAddress());
        self::assertSame(SiteSettings::DEFAULT_SITE_NAME, $mailer->message->getFrom()[0]->getName());
        $subject = $mailer->message->getSubject();
        self::assertIsString($subject);
        self::assertStringContainsString(SiteSettings::DEFAULT_SITE_NAME, $subject);
    }

    public function testMessageUsesTheConfiguredWebsiteName(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $settings = static::getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);
        self::assertSame([], $settings->update([
            'site_name' => 'Harmonia Video',
            'tagline' => 'Videos für alle',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
        ]));
        self::assertSame([], $settings->updateEmailSettings(['from_address' => 'notifications@example.test']));
        $provider = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $provider);
        $mailer = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };
        $delivery = new EmailDeliveryConfiguration('smtp://mail.example.test', 'fallback@example.test', $provider);
        $sender = new SymfonyMemberSecurityEmailSender(static fn (): MailerInterface => $mailer, $router, 'https://canonical.example', $delivery, $provider, $this->translator());

        try {
            $sender->send(
                new MemberAccount('member@example.test', 'security-test', 'Security test'),
                MemberEmailChallengePurpose::PasswordReset,
                rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            );

            self::assertInstanceOf(Email::class, $mailer->message);
            self::assertSame('notifications@example.test', $mailer->message->getFrom()[0]->getAddress());
            self::assertSame('Harmonia Video', $mailer->message->getFrom()[0]->getName());
            self::assertStringStartsWith("Videos für alle\n\n", (string) $mailer->message->getTextBody());
            $subject = $mailer->message->getSubject();
            self::assertIsString($subject);
            self::assertSame('Passwort für Harmonia Video zurücksetzen', $subject);
            self::assertStringContainsString('Öffne diesen Link, um ein neues Passwort zu setzen:', (string) $mailer->message->getTextBody());
            self::assertStringContainsString('Der Link läuft nach 30 Minuten ab und kann nur einmal verwendet werden.', (string) $mailer->message->getTextBody());
        } finally {
            $settings->update([
                'site_name' => SiteSettings::DEFAULT_SITE_NAME,
                'tagline' => SiteSettings::DEFAULT_TAGLINE,
                'locale' => SiteSettings::DEFAULT_LOCALE,
                'timezone' => SiteSettings::DEFAULT_TIMEZONE,
            ]);
            $settings->updateEmailSettings(['from_address' => '']);
        }
    }

    public function testConfiguredEnglishLocaleIsUsedForSecurityMessages(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $manager = static::getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        self::assertSame([], $manager->update([
            'site_name' => 'Harmonia Video',
            'tagline' => 'A secure video community',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]));

        $mailer = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
        $delivery = new EmailDeliveryConfiguration('smtp://mail.example.test', 'security@example.test', $settings);
        $sender = new SymfonyMemberSecurityEmailSender(
            static fn (): MailerInterface => $mailer,
            $router,
            'https://canonical.example',
            $delivery,
            $settings,
            $this->translator(),
        );
        $messages = [
            [
                MemberEmailChallengePurpose::EmailVerification,
                'Confirm your email address for Harmonia Video',
                'Open this link to confirm your email address:',
                'This link expires after one hour and can only be used once.',
            ],
            [
                MemberEmailChallengePurpose::PasswordReset,
                'Reset your password for Harmonia Video',
                'Open this link to set a new password:',
                'This link expires after 30 minutes and can only be used once. If you did not make this request, you can ignore this message.',
            ],
        ];

        try {
            foreach ($messages as [$purpose, $subject, $bodyLine, $expiryLine]) {
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $sender->send(new MemberAccount('member@example.test', 'english-security-test', 'English security test'), $purpose, $token);
            }

            self::assertCount(2, $mailer->messages);
            foreach ($messages as $index => [, $subject, $bodyLine, $expiryLine]) {
                $email = $mailer->messages[$index];
                self::assertInstanceOf(Email::class, $email);
                self::assertSame($subject, $email->getSubject());
                self::assertStringStartsWith("A secure video community\n\n", (string) $email->getTextBody());
                self::assertStringContainsString($bodyLine, (string) $email->getTextBody());
                self::assertStringContainsString($expiryLine, (string) $email->getTextBody());
                self::assertSame('Harmonia Video', $email->getFrom()[0]->getName());
            }
        } finally {
            $manager->update([
                'site_name' => SiteSettings::DEFAULT_SITE_NAME,
                'tagline' => SiteSettings::DEFAULT_TAGLINE,
                'locale' => SiteSettings::DEFAULT_LOCALE,
                'timezone' => SiteSettings::DEFAULT_TIMEZONE,
            ]);
        }
    }

    public function testMessageRefusesAnUnencryptedPublicOriginBeforeSending(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        $mailer = new class implements MailerInterface {
            public bool $sent = false;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = true;
            }
        };
        $delivery = new EmailDeliveryConfiguration('smtp://mail.example.test', 'security@example.test', $settings);
        $sender = new SymfonyMemberSecurityEmailSender(static fn (): MailerInterface => $mailer, $router, 'http://canonical.example', $delivery, $settings, $this->translator());
        $member = new MemberAccount('member@example.test', 'security-test', 'Security test');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        try {
            $sender->send($member, MemberEmailChallengePurpose::PasswordReset, $token);
            self::fail('An unencrypted public link origin must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Die öffentliche HTTPS-Basisadresse ist nicht gültig konfiguriert.', $exception->getMessage());
        }

        self::assertFalse($mailer->sent);
    }

    public function testMemberSecurityMailIsRejectedWhenOnlyTheNullTransportIsConfigured(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        $mailer = new class implements MailerInterface {
            public bool $sent = false;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = true;
            }
        };
        $delivery = new EmailDeliveryConfiguration('null://null', 'security@example.test', $settings);
        $sender = new SymfonyMemberSecurityEmailSender(static fn (): MailerInterface => $mailer, $router, 'https://canonical.example', $delivery, $settings, $this->translator());

        try {
            $sender->send(
                new MemberAccount('member@example.test', 'security-test', 'Security test'),
                MemberEmailChallengePurpose::PasswordReset,
                rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            );
            self::fail('A non-delivery null transport must not report successful member mail delivery.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Der E-Mail-Versand ist nicht sicher konfiguriert.', $exception->getMessage());
        }

        self::assertFalse($mailer->sent);
    }

    private function translator(): TranslatorInterface
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }
}
