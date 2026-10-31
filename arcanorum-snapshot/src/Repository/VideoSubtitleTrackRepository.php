<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoSubtitleTrack;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoSubtitleTrack>
 */
final class VideoSubtitleTrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoSubtitleTrack::class);
    }

    /** @return list<VideoSubtitleTrack> */
    public function findForVideo(Video $video): array
    {
        /** @var list<VideoSubtitleTrack> $tracks */
        $tracks = $this->createQueryBuilder('track')
            ->andWhere('track.video = :video')
            ->setParameter('video', $video)
            ->orderBy('track.languageCode', 'ASC')
            ->addOrderBy('track.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $tracks;
    }

    public function findForVideoAndId(Video $video, string $id): ?VideoSubtitleTrack
    {
        $track = $this->findOneBy(['video' => $video, 'id' => $id]);

        return $track instanceof VideoSubtitleTrack ? $track : null;
    }

    public function findForVideoAndLanguage(Video $video, string $languageCode): ?VideoSubtitleTrack
    {
        $track = $this->findOneBy(['video' => $video, 'languageCode' => $languageCode]);

        return $track instanceof VideoSubtitleTrack ? $track : null;
    }
}
