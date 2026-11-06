<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NewsArticle;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\NewsArticleRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class NewsViewCounter
{
    public function __construct(
        private NewsArticleRepository $articles,
        #[Autowire(service: 'limiter.news_view')]
        private RateLimiterFactoryInterface $limiter,
        private RateLimitKeyFactory $rateLimitKeys,
        private LoggerInterface $logger,
    ) {
    }

    public function record(NewsArticle $article, Request $request): void
    {
        $session = $request->getSession();
        $day = gmdate('Y-m-d');
        $history = $session->get('public_news_viewed');
        $ids = is_array($history) && ($history['day'] ?? null) === $day && is_array($history['ids'] ?? null)
            ? $history['ids']
            : [];
        $id = $article->getId();

        if (isset($ids[$id]) || count($ids) >= 100) {
            return;
        }

        if (null === $request->getClientIp()) {
            return;
        }

        $key = $this->rateLimitKeys->forIdentityAndIp($id, $request, 'news-view');
        try {
            $accepted = $this->limiter->create($key)->consume()->isAccepted();
        } catch (\Exception $exception) {
            $this->logger->warning('Ein öffentlicher News-Aufruf konnte nicht gezählt werden.', [
                'failure_type' => $exception::class,
            ]);

            return;
        }

        if ($accepted) {
            try {
                $this->articles->recordPublicView($id);
            } catch (\Exception $exception) {
                $this->logger->warning('Ein öffentlicher News-Aufruf konnte nicht gespeichert werden.', [
                    'failure_type' => $exception::class,
                ]);

                return;
            }
        }

        $ids[$id] = true;
        $session->set('public_news_viewed', ['day' => $day, 'ids' => $ids]);
    }
}
