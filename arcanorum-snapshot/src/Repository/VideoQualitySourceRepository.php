<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoQualitySource>
 */
final class VideoQualitySourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoQualitySource::class);
    }

    /** @return list<VideoQualitySource> */
    public function findForVideo(Video $video): array
    {
        /** @var list<VideoQualitySource> $sources */
        $sources = $this->createQueryBuilder('source')
            ->andWhere('source.video = :video')
            ->setParameter('video', $video)
            ->getQuery()
            ->getResult();

        usort(
            $sources,
            static fn (VideoQualitySource $left, VideoQualitySource $right): int => $left->getResolution()->height() <=> $right->getResolution()->height(),
        );

        return $sources;
    }

    public function findForVideoAndId(Video $video, string $id): ?VideoQualitySource
    {
        $source = $this->findOneBy(['video' => $video, 'id' => $id]);

        return $source instanceof VideoQualitySource ? $source : null;
    }

    public function findForVideoAndResolution(Video $video, VideoQuality $resolution): ?VideoQualitySource
    {
        $source = $this->findOneBy(['video' => $video, 'resolution' => $resolution]);

        return $source instanceof VideoQualitySource ? $source : null;
    }

    public function findForVideoAndSourceUrl(Video $video, string $sourceUrl): ?VideoQualitySource
    {
        $source = $this->findOneBy(['video' => $video, 'sourceUrl' => $sourceUrl]);

        return $source instanceof VideoQualitySource ? $source : null;
    }
}
