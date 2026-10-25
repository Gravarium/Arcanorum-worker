<?php

declare(strict_types=1);

namespace App\Module\Search\Controller;

use App\Module\Settings\Service\ModuleAvailability;
use App\Repository\ContentSeriesRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use App\Service\SearchTermRecorder;
use App\Service\SqlLikePattern;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicSearchController extends AbstractController
{
    private const int PAGE_SIZE = 12;

    #[Route('/search', name: 'public_search', methods: ['GET'])]
    public function index(
        Request $request,
        NewsArticleRepository $articles,
        VideoRepository $videos,
        ContentSeriesRepository $series,
        SearchTermRecorder $searchRecorder,
        ModuleAvailability $modules,
    ): Response {
        $queryParameters = $request->query->all();
        $rawQuery = $queryParameters['q'] ?? '';
        if (!is_string($rawQuery)
            || strlen($rawQuery) > 400
            || 1 !== preg_match('//u', $rawQuery)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $rawQuery)
        ) {
            return new Response('', Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'private, no-store']);
        }
        $query = trim($rawQuery);
        if (mb_strlen($query, 'UTF-8') > 100) {
            return new Response('', Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'private, no-store']);
        }
        $page = self::pageNumber($request);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $newsItems = [];
        $videoItems = [];
        $seriesItems = [];
        $hasNext = false;

        if ('' !== $query) {
            $escapedQuery = SqlLikePattern::escape($query);
            $newsResults = $modules->isEnabled('news')
                ? $articles->findPublished(search: $escapedQuery, limit: self::PAGE_SIZE + 1, offset: $offset)
                : [];
            $videoResults = $videos->findPublished(search: $escapedQuery, limit: self::PAGE_SIZE + 1, offset: $offset);
            $seriesResults = $modules->isEnabled('series')
                ? $series->findPublished(limit: self::PAGE_SIZE + 1, offset: $offset, search: $escapedQuery)
                : [];
            $hasNext = count($newsResults) > self::PAGE_SIZE || count($videoResults) > self::PAGE_SIZE || count($seriesResults) > self::PAGE_SIZE;
            $newsItems = array_slice($newsResults, 0, self::PAGE_SIZE);
            $videoItems = array_slice($videoResults, 0, self::PAGE_SIZE);
            $seriesItems = array_slice($seriesResults, 0, self::PAGE_SIZE);
            if (1 === $page) {
                $searchRecorder->record($query, $request);
            }
        }

        return $this->render('search/index.html.twig', [
            'query' => $query,
            'news' => $newsItems,
            'videos' => $videoItems,
            'series' => $seriesItems,
            'page' => $page,
            'has_next' => $hasNext,
        ]);
    }

    private static function pageNumber(Request $request): int
    {
        $value = $request->query->getString('page', '1');
        $page = ctype_digit($value) ? (int) $value : 1;

        return max(1, min(5000, $page));
    }
}
