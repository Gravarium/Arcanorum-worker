<?php

declare(strict_types=1);

namespace App\Module\Search\Controller;

use App\Module\News\Service\NewsArticleAccessPolicy;
use App\Module\Settings\Service\ModuleAvailability;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\ContentSeriesRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use App\Service\SqlLikePattern;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LiveSearchSuggestionsController extends AbstractController
{
    private const int MIN_QUERY_LENGTH = 2;
    private const int MAX_QUERY_LENGTH = 100;
    private const int RESULTS_PER_TYPE = 5;

    #[Route('/search/suggestions', name: 'public_search_suggestions', methods: ['GET'])]
    public function __invoke(
        Request $request,
        VideoRepository $videos,
        NewsArticleRepository $articles,
        ContentSeriesRepository $series,
        NewsArticleAccessPolicy $articleAccess,
        ModuleAvailability $modules,
        UrlGeneratorInterface $urls,
        RateLimitKeyFactory $rateLimitKeys,
        #[Autowire(service: 'limiter.live_search_suggestions')] RateLimiterFactory $limiter,
        LoggerInterface $logger,
    ): JsonResponse {
        $queryParameters = $request->query->all();
        $rawQuery = $queryParameters['q'] ?? '';
        if (!is_string($rawQuery)
            || strlen($rawQuery) > 400
            || 1 !== preg_match('//u', $rawQuery)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $rawQuery)
        ) {
            return $this->jsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $query = trim($rawQuery);
        if (mb_strlen($query, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            return $this->jsonResponse([], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($query, 'UTF-8') < self::MIN_QUERY_LENGTH) {
            return $this->jsonResponse([]);
        }

        try {
            $limit = $limiter->create($rateLimitKeys->forIp($request, 'live-search-suggestions'))->consume();
        } catch (\Exception) {
            $logger->warning('Live search suggestions were blocked because the abuse quota could not be checked.');

            return $this->jsonResponse([], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '60']);
        }

        if (!$limit->isAccepted()) {
            $retrySeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->jsonResponse([], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retrySeconds]);
        }

        $escapedQuery = SqlLikePattern::escape($query);
        $suggestions = [];
        foreach ($videos->findPublished(search: $escapedQuery, limit: self::RESULTS_PER_TYPE) as $video) {
            $suggestions[] = [
                'type' => 'video',
                'title' => self::safeTitle($video->getTitle()),
                'url' => $urls->generate('video_show', ['id' => $video->getId()], UrlGeneratorInterface::ABSOLUTE_PATH),
            ];
        }

        if ($modules->isEnabled('news')) {
            foreach ($articles->findPublished(
                search: $escapedQuery,
                limit: self::RESULTS_PER_TYPE,
                includeMemberOnlyBodyMatches: $articleAccess->canReadMembersOnly($this->getUser()),
            ) as $article) {
                $suggestions[] = [
                    'type' => 'news',
                    'title' => self::safeTitle($article->getTitle()),
                    'url' => $urls->generate('news_show', ['slug' => $article->getSlug()], UrlGeneratorInterface::ABSOLUTE_PATH),
                ];
            }
        }

        if ($modules->isEnabled('series')) {
            foreach ($series->findPublished(limit: self::RESULTS_PER_TYPE, search: $escapedQuery) as $item) {
                $suggestions[] = [
                    'type' => 'series',
                    'title' => self::safeTitle($item->getTitle()),
                    'url' => $urls->generate('series_show', ['slug' => $item->getSlug()], UrlGeneratorInterface::ABSOLUTE_PATH),
                ];
            }
        }

        return $this->jsonResponse($suggestions);
    }

    /** @param list<array{type: string, title: string, url: string}> $suggestions
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $suggestions, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['suggestions' => $suggestions],
            $status,
            $headers + [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private static function safeTitle(string $title): string
    {
        $title = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $title) ?? '';

        return mb_substr(trim($title), 0, 160, 'UTF-8');
    }
}
