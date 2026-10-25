<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\ContentSeriesRepository;
use App\Repository\SeriesEpisodeRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SeriesGenreManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicSeriesController extends AbstractController
{
    private const int SERIES_PAGE_SIZE = 24;
    private const int EPISODE_PAGE_SIZE = 50;

    #[Route('/series', name: 'series_index', methods: ['GET'])]
    public function index(Request $request, ContentSeriesRepository $series, SeriesGenreManager $genres): Response
    {
        $rawGenre = $request->query->all()['genre'] ?? '';
        if (!is_string($rawGenre)
            || strlen($rawGenre) > 160
            || 1 !== preg_match('//u', $rawGenre)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $rawGenre)
        ) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }
        $genre = trim($rawGenre);
        if (mb_strlen($genre, 'UTF-8') > 40) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }
        $page = self::pageNumber($request);
        $items = '' === $genre
            ? $series->findPublished(limit: self::SERIES_PAGE_SIZE + 1, offset: ($page - 1) * self::SERIES_PAGE_SIZE)
            : $series->findPublishedByGenre($genre, self::SERIES_PAGE_SIZE + 1, ($page - 1) * self::SERIES_PAGE_SIZE);

        return $this->render('series/index.html.twig', [
            'series' => array_slice($items, 0, self::SERIES_PAGE_SIZE),
            'page' => $page,
            'has_next' => count($items) > self::SERIES_PAGE_SIZE,
            'genre' => $genre,
            'genres' => $genres->listForPublic(),
        ]);
    }

    #[Route(
        '/series/{slug}',
        name: 'series_show',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function show(
        string $slug,
        Request $request,
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
    ): Response {
        $item = $series->findPublishedBySlug($slug);
        if (!$item instanceof ContentSeries) {
            return $this->notFound();
        }

        $page = self::pageNumber($request);
        $episodeCount = $episodes->countPublicForSeries($item);
        $items = $episodes->findPublicForSeries(
            $item,
            self::EPISODE_PAGE_SIZE + 1,
            ($page - 1) * self::EPISODE_PAGE_SIZE,
        );

        return $this->render('series/show.html.twig', [
            'series' => $item,
            'episodes' => array_slice($items, 0, self::EPISODE_PAGE_SIZE),
            'page' => $page,
            'has_next' => count($items) > self::EPISODE_PAGE_SIZE && $episodeCount > $page * self::EPISODE_PAGE_SIZE,
        ]);
    }

    #[Route(
        '/series/{slug}/season/{seasonNumber}/episode/{episodeNumber}',
        name: 'series_episode_show',
        requirements: [
            'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
            'seasonNumber' => '[1-9][0-9]{0,2}',
            'episodeNumber' => '[1-9][0-9]{0,2}',
        ],
        methods: ['GET'],
    )]
    public function episode(
        string $slug,
        string $seasonNumber,
        string $episodeNumber,
        Request $request,
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
    ): Response {
        $item = $series->findPublishedBySlug($slug);
        if (!$item instanceof ContentSeries || !ctype_digit($seasonNumber) || !ctype_digit($episodeNumber)) {
            return $this->notFound();
        }

        $episode = $episodes->findAtPosition($item, (int) $seasonNumber, (int) $episodeNumber);
        if (null === $episode
            || PublishingStatus::Published !== $episode->getStatus()
            || PublishingStatus::Published !== $episode->getVideo()->getStatus()
            || null === $episode->getVideo()->getPublishedAt()
        ) {
            return $this->notFound();
        }

        $video = $episode->getVideo();
        $streamSource = VideoSourceType::Stream === $video->getSourceType()
            ? $streamResolver->resolve($video->getSourceUrl())
            : null;
        if (null !== $streamSource) {
            $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, $streamSource->origin);
            if ($streamSource->isHls()) {
                $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
            }
        }

        $response = $this->render('series/episode.html.twig', [
            'series' => $item,
            'episode' => $episode,
            'video' => $video,
            'stream_source' => $streamSource,
            'embed_url' => VideoSourceType::Embed === $video->getSourceType() ? $embedResolver->resolve($video->getSourceUrl()) : null,
        ]);
        if (null !== $streamSource) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    private function notFound(): Response
    {
        return $this->render('public/not_found.html.twig', [
            'content_type' => 'Serie oder Folge',
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }

    private static function pageNumber(Request $request): int
    {
        $value = $request->query->all()['page'] ?? '1';
        if (!is_string($value)) {
            return 1;
        }
        $page = ctype_digit($value) ? (int) $value : 1;

        return max(1, min(5000, $page));
    }
}
