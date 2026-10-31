<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SiteSettings;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SiteLogoControllerTest extends WebTestCase
{
    public function testServesOnlyTheCurrentSanitizedLogoWithSafeHeaders(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $contents = $this->png();
        $objectId = str_repeat('a', 64);
        $sha256 = hash('sha256', $contents);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('read')->with($objectId, strlen($contents))->willReturn($contents);
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $settings = $this->settings($client);
        $settings->attachSiteLogo($objectId, 'image/png', strlen($contents), $sha256);
        $this->flush($client);

        try {
            $client->request('GET', '/site-logo/'.$sha256);

            self::assertResponseIsSuccessful();
            self::assertSame($contents, $client->getResponse()->getContent());
            self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('inline', $client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('public', $cacheControl);
            self::assertStringContainsString('max-age=300', $cacheControl);
            self::assertSame('same-origin', $client->getResponse()->headers->get('Cross-Origin-Resource-Policy'));

            $client->request('GET', '/site-logo/'.str_repeat('c', 64));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $settings->removeSiteLogo();
            $this->flush($client);
        }
    }

    public function testInvalidPrivateLogoBytesFailClosed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set('logger', new NullLogger());
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $objectId = str_repeat('d', 64);
        $corrupt = 'not an image';
        $sha256 = hash('sha256', $corrupt);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('read')->with($objectId, strlen($corrupt))->willReturn($corrupt);
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $settings = $this->settings($client);
        $settings->attachSiteLogo($objectId, 'image/png', strlen($corrupt), $sha256);
        $this->flush($client);

        try {
            $client->request('GET', '/site-logo/'.$sha256);

            self::assertResponseStatusCodeSame(503);
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertStringNotContainsString($corrupt, (string) $client->getResponse()->getContent());
        } finally {
            $settings->removeSiteLogo();
            $this->flush($client);
        }
    }

    private function settings(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): SiteSettings
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $settings = $entityManager->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);
        if (!$settings instanceof SiteSettings) {
            $settings = new SiteSettings();
            $entityManager->persist($settings);
        } else {
            $settings->removeSiteLogo();
            $entityManager->flush();
        }

        return $settings;
    }

    private function flush(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(64, 32);
        self::assertInstanceOf(\GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
        if (false === $transparent) {
            throw new \RuntimeException('Could not allocate the public logo fixture color.');
        }
        imagefill($image, 0, 0, $transparent);

        ob_start();
        try {
            imagepng($image);
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $contents);

        return $contents;
    }
}
