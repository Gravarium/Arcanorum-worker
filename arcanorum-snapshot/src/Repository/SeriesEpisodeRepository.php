<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriesEpisode>
 */
final class SeriesEpisodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriesEpisode::class);
    }

    /** @return list<SeriesEpisode> */
    public function findForAdmin(string $search = '', ?ContentSeries $seriesFilter = null): array
    {
        $queryBuilder = $this->createQueryBuilder('episode')
            ->innerJoin('episode.series', 'series')
            ->addSelect('series')
            ->innerJoin('episode.video', 'video')
            ->addSelect('video');

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(series.title LIKE :search OR video.title LIKE :search OR episode.titleOverride LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $seriesFilter) {
            $queryBuilder->andWhere('episode.series = :seriesFilter')->setParameter('seriesFilter', $seriesFilter);
        }

        /** @var list<SeriesEpisode> $episodes */
        $episodes = $queryBuilder
            ->orderBy('series.title', \SortDirection::Ascending)
            ->addOrderBy('episode.seasonNumber', \SortDirection::Ascending)
            ->addOrderBy('episode.episodeNumber', \SortDirection::Ascending)
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $episodes;
    }

    /** @return list<SeriesEpisode> */
    public function findPublishedForSitemap(int $limit = 500, int $offset = 0): array
    {
        /** @var list<SeriesEpisode> $episodes */
        $episodes = $this->createQueryBuilder('episode')
            ->innerJoin('episode.series', 'series')
            ->addSelect('series')
            ->innerJoin('episode.video', 'video')
            ->addSelect('video')
            ->andWhere('episode.status = :episodeStatus')
            ->andWhere('series.status = :seriesStatus')
            ->andWhere('series.publishedAt IS NOT NULL')
            ->andWhere('video.status = :videoStatus')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('episodeStatus', PublishingStatus::Published)
            ->setParameter('seriesStatus', PublishingStatus::Published)
            ->setParameter('videoStatus', PublishingStatus::Published)
            ->orderBy('series.slug', \SortDirection::Ascending)
            ->addOrderBy('episode.seasonNumber', \SortDirection::Ascending)
            ->addOrderBy('episode.episodeNumber', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();

        return $episodes;
    }

    /** @return list<SeriesEpisode> */
    public function findForSeries(ContentSeries $series): array
    {
        /** @var list<SeriesEpisode> $episodes */
        $episodes = $this->createQueryBuilder('episode')
            ->andWhere('episode.series = :series')
            ->setParameter('series', $series)
            ->orderBy('episode.seasonNumber', \SortDirection::Ascending)
            ->addOrderBy('episode.episodeNumber', \SortDirection::Ascending)
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $episodes;
    }

    /** @return list<SeriesEpisode> */
    public function findPublicForSeries(ContentSeries $series, int $limit = 100, int $offset = 0): array
    {
        /** @var list<SeriesEpisode> $episodes */
        $episodes = $this->createQueryBuilder('episode')
            ->innerJoin('episode.video', 'video')
            ->addSelect('video')
            ->andWhere('episode.series = :series')
            ->andWhere('episode.status = :episodeStatus')
            ->andWhere('video.status = :videoStatus')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('series', $series)
            ->setParameter('episodeStatus', PublishingStatus::Published)
            ->setParameter('videoStatus', PublishingStatus::Published)
            ->orderBy('episode.seasonNumber', \SortDirection::Ascending)
            ->addOrderBy('episode.episodeNumber', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $episodes;
    }

    public function countPublicForSeries(ContentSeries $series): int
    {
        return (int) $this->createQueryBuilder('episode')
            ->select('COUNT(episode.id)')
            ->innerJoin('episode.video', 'video')
            ->andWhere('episode.series = :series')
            ->andWhere('episode.status = :episodeStatus')
            ->andWhere('video.status = :videoStatus')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->setParameter('series', $series)
            ->setParameter('episodeStatus', PublishingStatus::Published)
            ->setParameter('videoStatus', PublishingStatus::Published)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAtPosition(ContentSeries $series, int $seasonNumber, int $episodeNumber): ?SeriesEpisode
    {
        $episode = $this->findOneBy([
            'series' => $series,
            'seasonNumber' => $seasonNumber,
            'episodeNumber' => $episodeNumber,
        ]);

        return $episode instanceof SeriesEpisode ? $episode : null;
    }

    public function findForVideo(Video $video): ?SeriesEpisode
    {
        $episode = $this->findOneBy(['video' => $video]);

        return $episode instanceof SeriesEpisode ? $episode : null;
    }

    public function countForSeries(ContentSeries $series): int
    {
        return (int) $this->createQueryBuilder('episode')
            ->select('COUNT(episode.id)')
            ->andWhere('episode.series = :series')
            ->setParameter('series', $series)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
