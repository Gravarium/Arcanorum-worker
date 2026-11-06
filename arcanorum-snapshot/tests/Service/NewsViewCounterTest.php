<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Platform\Security\RateLimitKeyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class NewsViewCounterTest extends WebTestCase
{
    public function testPublicViewsAreCountedOncePerSessionAndRestrictedBodiesAreNotCounted(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $public = new NewsArticle('Public '.$suffix, 'public-view-'.$suffix, null, null, 'Public body.', PublishingStatus::Published, false, true);
        $restricted = new NewsArticle('Restricted '.$suffix, 'restricted-view-'.$suffix, null, null, 'Restricted body.', PublishingStatus::Published, false, true, membersOnly: true);
        $draft = new NewsArticle('Draft '.$suffix, 'draft-view-'.$suffix, null, null, 'Draft body.', PublishingStatus::Draft, false, true);

        try {
            foreach ([$public, $restricted, $draft] as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();

            $client->request('GET', '/news/'.$public->getSlug());
            self::assertResponseIsSuccessful();
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            $client->request('GET', '/news/'.$public->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSame('1', $entityManager->getConnection()->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $public->getId()]));

            $client->request('GET', '/news/'.$restricted->getSlug());
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Restricted body.', (string) $client->getResponse()->getContent());
            self::assertSame('0', $entityManager->getConnection()->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $restricted->getId()]));

            $client->request('GET', '/news/'.$draft->getSlug());
            self::assertResponseNotFound();
            self::assertSame('0', $entityManager->getConnection()->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $draft->getId()]));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM news_articles WHERE id IN (:ids)', [
                'ids' => [$public->getId(), $restricted->getId(), $draft->getId()],
            ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        }
    }

    public function testRateLimiterFailureDoesNotBreakArticleAndDoesNotCountView(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $article = new NewsArticle('Limiter failure', 'limiter-failure-'.bin2hex(random_bytes(6)), null, null, 'Public body.', PublishingStatus::Published, false, true);
        $entityManager->persist($article);
        $entityManager->flush();
        $brokenLimiter = self::createStub(RateLimiterFactoryInterface::class);
        $brokenLimiter->method('create')->willThrowException(new \RuntimeException('Simulated limiter outage.'));
        $client->getContainer()->set('limiter.news_view', $brokenLimiter);

        try {
            $client->request('GET', '/news/'.$article->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSame('0', $entityManager->getConnection()->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $article->getId()]));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $article->getId()]);
        }
    }

    public function testRateLimitKeyHidesTheAddressAndArticleIdentity(): void
    {
        $request = Request::create('/news/example');
        $request->server->set('REMOTE_ADDR', '192.0.2.44');
        $articleId = 'news-article-private-id';
        $key = (new RateLimitKeyFactory('test-news-rate-limit-secret'))->forIdentityAndIp($articleId, $request, 'news-view');

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $key);
        self::assertStringNotContainsString('192.0.2.44', $key);
        self::assertStringNotContainsString($articleId, $key);
    }
}
