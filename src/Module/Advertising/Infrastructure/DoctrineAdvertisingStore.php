<?php

declare(strict_types=1);

namespace App\Module\Advertising\Infrastructure;

use App\Module\Advertising\Application\AdvertisingStore;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAdvertisingStore implements AdvertisingStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function findPlacement(string $id): ?Placement
    {
        $row = $this->connection->fetchAssociative('SELECT id, label, formats, archived FROM cms_advertising_placement WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->placementFromRow($row);
    }

    public function listPlacements(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, label, formats, archived FROM cms_advertising_placement ORDER BY id');

        return array_map($this->placementFromRow(...), $rows);
    }

    public function savePlacement(Placement $placement): void
    {
        $formats = json_encode(array_map(static fn (AdFormat $format): string => $format->value, $placement->formats), JSON_THROW_ON_ERROR);
        $this->connection->executeStatement(
            'INSERT INTO cms_advertising_placement (id, label, formats, archived) VALUES (:id, :label, CAST(:formats AS jsonb), :archived) '
            .'ON CONFLICT (id) DO UPDATE SET label = EXCLUDED.label, formats = EXCLUDED.formats, archived = EXCLUDED.archived',
            ['id' => $placement->id, 'label' => $placement->label, 'formats' => $formats, 'archived' => $placement->archived ? 'true' : 'false'],
        );
    }

    public function findAdvertisement(string $id): ?Advertisement
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cms_advertising_ad WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->advertisementFromRow($row);
    }

    public function listAdvertisements(?string $placementId = null): array
    {
        $rows = null === $placementId
            ? $this->connection->fetchAllAssociative('SELECT * FROM cms_advertising_ad ORDER BY created_at DESC, id')
            : $this->connection->fetchAllAssociative('SELECT * FROM cms_advertising_ad WHERE placement_id = :placement_id ORDER BY created_at DESC, id', ['placement_id' => $placementId]);

        return array_map($this->advertisementFromRow(...), $rows);
    }

    public function listAdvertisementsByStatus(string $placementId, AdStatus $status): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cms_advertising_ad WHERE placement_id = :placement_id AND status = :status ORDER BY id',
            ['placement_id' => $placementId, 'status' => $status->value],
        );

        return array_map($this->advertisementFromRow(...), $rows);
    }

    public function saveAdvertisement(Advertisement $advertisement): void
    {
        $creative = json_encode($advertisement->creative, JSON_THROW_ON_ERROR);
        $this->connection->executeStatement(
            'INSERT INTO cms_advertising_ad (id, label, placement_id, format, creative, destination_url, status, created_at, updated_at) '
            .'VALUES (:id, :label, :placement_id, :format, CAST(:creative AS jsonb), :destination_url, :status, :created_at, :updated_at) '
            .'ON CONFLICT (id) DO UPDATE SET label = EXCLUDED.label, placement_id = EXCLUDED.placement_id, format = EXCLUDED.format, '
            .'creative = EXCLUDED.creative, destination_url = EXCLUDED.destination_url, status = EXCLUDED.status, updated_at = EXCLUDED.updated_at',
            [
                'id' => $advertisement->id,
                'label' => $advertisement->label,
                'placement_id' => $advertisement->placementId,
                'format' => $advertisement->format->value,
                'creative' => $creative,
                'destination_url' => $advertisement->destinationUrl,
                'status' => $advertisement->status->value,
                'created_at' => $advertisement->createdAt->format('Y-m-d H:i:sP'),
                'updated_at' => $advertisement->updatedAt->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function placementFromRow(array $row): Placement
    {
        $formats = $this->decodeJson($row['formats'] ?? null);
        if (!array_is_list($formats)) {
            throw new \RuntimeException('Stored advertising placement format data is invalid.');
        }

        $typedFormats = [];
        foreach ($formats as $format) {
            if (!is_string($format) || null === ($typed = AdFormat::tryFrom($format))) {
                throw new \RuntimeException('Stored advertising placement format data is invalid.');
            }
            $typedFormats[] = $typed;
        }

        return new Placement(
            self::stringColumn($row, 'id'),
            self::stringColumn($row, 'label'),
            $typedFormats,
            in_array($row['archived'] ?? false, [true, 1, '1', 't', 'true'], true),
        );
    }

    /** @param array<string, mixed> $row */
    private function advertisementFromRow(array $row): Advertisement
    {
        $creative = $this->decodeJson($row['creative'] ?? null);
        $typedCreative = [];
        foreach ($creative as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \RuntimeException('Stored advertising creative data is invalid.');
            }
            $typedCreative[$key] = $value;
        }

        return new Advertisement(
            self::stringColumn($row, 'id'),
            self::stringColumn($row, 'label'),
            self::stringColumn($row, 'placement_id'),
            AdFormat::from(self::stringColumn($row, 'format')),
            $typedCreative,
            self::stringColumn($row, 'destination_url'),
            AdStatus::from(self::stringColumn($row, 'status')),
            new \DateTimeImmutable(self::stringColumn($row, 'created_at')),
            new \DateTimeImmutable(self::stringColumn($row, 'updated_at')),
        );
    }

    /** @param array<string, mixed> $row */
    private static function stringColumn(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException('Stored advertising row is invalid.');
        }

        return $value;
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \RuntimeException('Stored advertising JSON is invalid.');
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Stored advertising JSON is invalid.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Stored advertising JSON is invalid.');
        }

        return $decoded;
    }
}
