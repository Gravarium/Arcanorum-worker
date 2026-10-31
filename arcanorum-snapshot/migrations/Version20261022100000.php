<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalisiert Seriengenres zu einem eigenständigen Katalog mit gesicherter Zuordnung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE series_genres (id VARCHAR(32) NOT NULL, name VARCHAR(40) NOT NULL, normalized_name VARCHAR(160) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT uniq_series_genres_normalized_name UNIQUE (normalized_name), CONSTRAINT chk_series_genres_name CHECK (char_length(name) BETWEEN 1 AND 40))');
        $this->addSql('CREATE TABLE content_series_genres (series_id VARCHAR(32) NOT NULL, genre_id VARCHAR(32) NOT NULL, PRIMARY KEY(series_id, genre_id), CONSTRAINT fk_content_series_genres_series FOREIGN KEY (series_id) REFERENCES content_series (id) ON DELETE CASCADE, CONSTRAINT fk_content_series_genres_genre FOREIGN KEY (genre_id) REFERENCES series_genres (id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX IDX_9042203A4296D31F ON content_series_genres (genre_id)');
        $genres = [];
        foreach ($this->connection->iterateAssociative('SELECT id, genres FROM content_series ORDER BY id') as $row) {
            if (!is_string($row['id'] ?? null) || !is_string($row['genres'] ?? null)) {
                throw new \RuntimeException('Die bisherigen Seriengenres sind unlesbar.');
            }
            $names = json_decode($row['genres'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($names) || !array_is_list($names)) {
                throw new \RuntimeException('Die bisherigen Seriengenres haben kein gültiges Listenformat.');
            }
            $assigned = [];
            foreach ($names as $name) {
                if (!is_string($name) || '' === trim($name) || mb_strlen($name, 'UTF-8') > 40) {
                    throw new \RuntimeException('Ein bisheriger Seriengenrename ist ungültig.');
                }
                $normalized = mb_strtolower($name, 'UTF-8');
                if (!isset($genres[$normalized])) {
                    $genres[$normalized] = bin2hex(random_bytes(16));
                    $this->addSql(
                        'INSERT INTO series_genres (id, name, normalized_name, created_at, updated_at) VALUES (:id, :name, :normalized, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                        ['id' => $genres[$normalized], 'name' => $name, 'normalized' => $normalized],
                    );
                }
                if (!isset($assigned[$normalized])) {
                    $assigned[$normalized] = true;
                    $this->addSql(
                        'INSERT INTO content_series_genres (series_id, genre_id) VALUES (:series, :genre)',
                        ['series' => $row['id'], 'genre' => $genres[$normalized]],
                    );
                }
            }
        }
        $this->addSql('ALTER TABLE content_series DROP CONSTRAINT chk_content_series_genres_array');
        $this->addSql('ALTER TABLE content_series DROP genres');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('content_series_genres', 'series_genres');
        $standalone = $this->connection->fetchOne('SELECT genre.id FROM series_genres genre LEFT JOIN content_series_genres assignment ON assignment.genre_id = genre.id WHERE assignment.genre_id IS NULL LIMIT 1');
        if (false !== $standalone) {
            throw new \RuntimeException('Der Rückbau würde eigenständige Seriengenres verlieren. Diese zuerst sichern oder zuordnen.');
        }
        $this->addSql("ALTER TABLE content_series ADD genres JSON DEFAULT '[]' NOT NULL");
        $this->addSql("UPDATE content_series series SET genres = (SELECT COALESCE(json_agg(genre.name ORDER BY genre.name), '[]'::json) FROM content_series_genres assignment JOIN series_genres genre ON genre.id = assignment.genre_id WHERE assignment.series_id = series.id)");
        $this->addSql("ALTER TABLE content_series ADD CONSTRAINT chk_content_series_genres_array CHECK (json_typeof(genres) = 'array')");
        $this->addSql('DROP TABLE content_series_genres');
        $this->addSql('DROP TABLE series_genres');
    }
}
