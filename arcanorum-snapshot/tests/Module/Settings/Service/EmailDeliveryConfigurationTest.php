<?php

declare(strict_types=1);

namespace App\Tests\Module\Settings\Service;

use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmailDeliveryConfigurationTest extends KernelTestCase
{
    public function testTransportAndEffectiveSenderMustBothBeConfigured(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $manager);
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);

        try {
            self::assertSame([], $manager->updateEmailSettings(['from_address' => '']));
            $configured = new EmailDeliveryConfiguration('smtp://mail.example.test:587', 'fallback@example.test', $settings);
            self::assertTrue($configured->hasTransport());
            self::assertSame('fallback@example.test', $configured->fromAddress());
            self::assertTrue($configured->isReady());

            $settings->current()->updateEmailSettings(['from_address' => 'site@example.test']);
            $siteAddress = new EmailDeliveryConfiguration('smtp://mail.example.test:587', 'fallback@example.test', $settings);
            self::assertSame('site@example.test', $siteAddress->fromAddress());

            $nullTransport = new EmailDeliveryConfiguration('null://null', 'fallback@example.test', $settings);
            self::assertFalse($nullTransport->hasTransport());
            self::assertFalse($nullTransport->isReady());

            $invalidSender = new EmailDeliveryConfiguration('smtp://mail.example.test:587', 'not-an-address', $settings);
            self::assertSame('site@example.test', $invalidSender->fromAddress());
            self::assertTrue($invalidSender->isReady());

            self::assertSame([], $manager->updateEmailSettings(['from_address' => '']));
            $invalidFallback = new EmailDeliveryConfiguration('smtp://mail.example.test:587', 'not-an-address', $settings);
            self::assertNull($invalidFallback->fromAddress());
            self::assertFalse($invalidFallback->isReady());
        } finally {
            $manager->updateEmailSettings(['from_address' => '']);
        }
    }

    public function testCompositeTransportsAreValidatedRecursivelyAndNullBranchesAreRejected(): void
    {
        self::bootKernel();
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);

        self::assertTrue(new EmailDeliveryConfiguration(
            'failover(smtp://primary.example.test:25 smtps://backup.example.test:465)',
            'sender@example.test',
            $settings,
        )->hasTransport());
        self::assertTrue(new EmailDeliveryConfiguration(
            'roundrobin(smtp://first.example.test:25 smtp://second.example.test:25)',
            'sender@example.test',
            $settings,
        )->hasTransport());

        self::assertFalse(new EmailDeliveryConfiguration(
            'failover(null://null smtp://backup.example.test:25)',
            'sender@example.test',
            $settings,
        )->hasTransport());
        self::assertFalse(new EmailDeliveryConfiguration(
            'failover(smtp://primary.example.test:25 smtp://backup.example.test:25',
            'sender@example.test',
            $settings,
        )->hasTransport());
        self::assertFalse(new EmailDeliveryConfiguration('smtp://mail.example.test:99999', 'sender@example.test', $settings)->hasTransport());
        self::assertFalse(new EmailDeliveryConfiguration('null://null', 'sender@example.test', $settings)->hasTransport());
    }
}
