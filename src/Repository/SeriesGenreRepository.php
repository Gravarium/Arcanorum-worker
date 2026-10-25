<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriesGenre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SeriesGenre> */
final class SeriesGenreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriesGenre::class);
    }

    public function findByName(string $name): ?SeriesGenre
    {
        $genre = $this->findOneBy(['normalizedName' => mb_strtolower(trim($name), 'UTF-8')]);

        return $genre instanceof SeriesGenre ? $genre : null;
    }
}
