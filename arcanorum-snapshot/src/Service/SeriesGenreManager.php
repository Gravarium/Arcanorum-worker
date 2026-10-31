<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PublishingStatus;
use App\Entity\SeriesGenre;
use App\Repository\SeriesGenreRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SeriesGenreManager
{
    public function __construct(
        private SeriesGenreRepository $genres,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /** @return list<array{id: string, name: string, count: int, is_featured: bool, featured_sort_order: int}> */
    public function listForAdmin(int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT genre.id, genre.name, genre.is_featured, genre.featured_sort_order, COUNT(assignment.series_id) AS series_count FROM series_genres genre '
            .'LEFT JOIN content_series_genres assignment ON assignment.genre_id = genre.id '
            .'GROUP BY genre.id, genre.name, genre.is_featured, genre.featured_sort_order '
            .'ORDER BY genre.name ASC, genre.id ASC LIMIT :limit OFFSET :offset',
            ['limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $result = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $count = $row['series_count'] ?? null;
            $id = $row['id'] ?? null;
            $featuredValue = $row['is_featured'] ?? null;
            $sortOrder = $row['featured_sort_order'] ?? null;
            $isFeatured = match ($featuredValue) {
                true, 1, '1', 't', 'true' => true,
                false, 0, '0', 'f', 'false' => false,
                default => null,
            };
            if (!is_string($id) || 1 !== preg_match('/^[a-f0-9]{32}$/D', $id)
                || !is_string($name)
                || !(is_int($count) || is_string($count) && ctype_digit($count))
                || null === $isFeatured
                || !(is_int($sortOrder) || is_string($sortOrder) && ctype_digit($sortOrder))
                || (int) $sortOrder < 0
                || (int) $sortOrder > 10_000
            ) {
                throw new \RuntimeException('Die Seriengenres konnten nicht gelesen werden.');
            }
            $result[] = [
                'id' => $id,
                'name' => $name,
                'count' => (int) $count,
                'is_featured' => $isFeatured,
                'featured_sort_order' => (int) $sortOrder,
            ];
        }

        return $result;
    }

    public function countForAdmin(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM series_genres');
        if (is_int($count)) {
            return $count;
        }

        return is_string($count) && ctype_digit($count) ? (int) $count : 0;
    }

    /** @return list<string> */
    public function listForPublic(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT genre.name FROM series_genres genre '
            .'JOIN content_series_genres assignment ON assignment.genre_id = genre.id '
            .'JOIN content_series series ON series.id = assignment.series_id '
            .'WHERE series.status = :status AND series.published_at IS NOT NULL ORDER BY genre.name ASC',
            ['status' => PublishingStatus::Published->value],
        );
        $names = [];
        foreach ($rows as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    public function featuredForPublic(): array
    {
        $rows = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT genre.name
            FROM series_genres genre
            WHERE genre.is_featured IS TRUE
              AND EXISTS (
                  SELECT 1
                  FROM content_series_genres assignment
                  INNER JOIN content_series series ON series.id = assignment.series_id
                  WHERE assignment.genre_id = genre.id
                    AND series.status = :status
                    AND series.published_at IS NOT NULL
              )
            ORDER BY genre.featured_sort_order ASC, genre.name ASC, genre.id ASC
            LIMIT :limit
            SQL, [
            'status' => PublishingStatus::Published->value,
            'limit' => 10,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        $names = [];
        foreach ($rows as $name) {
            if (!is_string($name)) {
                throw new \RuntimeException('Die hervorgehobenen Seriengenres konnten nicht gelesen werden.');
            }
            $names[] = $name;
        }

        return $names;
    }

    public function setFeatured(string $id, bool $featured, int $sortOrder): bool
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Die Genrekennung ist ungültig.');
        }

        $genre = $this->genres->find($id);
        if (!$genre instanceof SeriesGenre) {
            return false;
        }
        $genre->setFeatured($featured, $sortOrder);
        $this->entityManager->flush();

        return true;
    }

    public function create(string $name): void
    {
        $validName = self::validName($name);
        if (null !== $this->genres->findByName($validName)) {
            throw new \InvalidArgumentException('Das Genre ist bereits vorhanden.');
        }
        $this->entityManager->persist(new SeriesGenre($validName));
        $this->entityManager->flush();
    }

    /** @param list<string> $names
     *
     * @return list<SeriesGenre>
     */
    public function resolve(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $validName = self::validName($name);
            $genre = $this->genres->findByName($validName);
            if (null === $genre) {
                $genre = new SeriesGenre($validName);
                $this->entityManager->persist($genre);
            }
            $resolved[] = $genre;
        }

        return $resolved;
    }

    public function rename(string $old, string $new): int
    {
        $oldName = self::validName($old);
        $newName = self::validName($new);
        $genre = $this->genres->findByName($oldName);
        if (null === $genre || $genre->getName() !== $old) {
            throw new \InvalidArgumentException('Das Genre wurde nicht gefunden.');
        }
        $existing = $this->genres->findByName($newName);
        if (null !== $existing && $existing->getId() !== $genre->getId()) {
            throw new \InvalidArgumentException('Das neue Genre ist bereits vorhanden.');
        }
        $count = $this->assignmentCount($genre);
        $genre->rename($newName);
        $this->entityManager->flush();

        return $count;
    }

    public function delete(string $old): int
    {
        $oldName = self::validName($old);
        $genre = $this->genres->findByName($oldName);
        if (null === $genre || $genre->getName() !== $old) {
            throw new \InvalidArgumentException('Das Genre wurde nicht gefunden.');
        }
        $count = $this->assignmentCount($genre);
        $this->entityManager->remove($genre);
        $this->entityManager->flush();

        return $count;
    }

    private function assignmentCount(SeriesGenre $genre): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM content_series_genres WHERE genre_id = :genre',
            ['genre' => $genre->getId()],
        );
        if (is_int($count)) {
            return $count;
        }

        return is_string($count) && ctype_digit($count) ? (int) $count : 0;
    }

    private static function validName(string $value): string
    {
        $names = SeriesGenreParser::parseNames($value);
        if (1 !== count($names)) {
            throw new \InvalidArgumentException('Bitte genau einen gültigen Genrenamen angeben.');
        }

        return $names[0];
    }
}
