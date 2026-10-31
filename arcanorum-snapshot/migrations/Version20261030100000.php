<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt hierarchische Kategorien, stabile Slugs und Suchmaschinen-Metadaten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_categories ADD slug VARCHAR(120) DEFAULT NULL');
        $this->addSql("ALTER TABLE content_categories ADD description TEXT NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE content_categories ADD meta_title VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_categories ADD meta_description VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_categories ADD meta_keywords VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_categories ADD sort_order SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE content_categories ADD parent_id VARCHAR(32) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            WITH prepared AS (
                SELECT id,
                       content_type,
                       COALESCE(
                           NULLIF(trim(BOTH '-' FROM lower(regexp_replace(name, '[^a-zA-Z0-9]+', '-', 'g'))), ''),
                           'category'
                       ) AS base_slug
                FROM content_categories
            ), ranked AS (
                SELECT id,
                       base_slug,
                       count(*) OVER (PARTITION BY content_type, base_slug) AS duplicate_count
                FROM prepared
            )
            UPDATE content_categories AS category
            SET slug = CASE
                WHEN ranked.duplicate_count = 1 THEN ranked.base_slug
                ELSE left(ranked.base_slug, 80) || '-' || category.id
            END
            FROM ranked
            WHERE ranked.id = category.id
            SQL);
        $this->addSql('ALTER TABLE content_categories ALTER COLUMN slug SET NOT NULL');
        $this->addSql("ALTER TABLE content_categories ADD CONSTRAINT chk_content_categories_slug CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        $this->addSql('ALTER TABLE content_categories ADD CONSTRAINT chk_content_categories_description_length CHECK (char_length(description) <= 2000)');
        $this->addSql('ALTER TABLE content_categories ADD CONSTRAINT chk_content_categories_sort_order CHECK (sort_order BETWEEN 0 AND 10000)');
        $this->addSql('ALTER TABLE content_categories ADD CONSTRAINT fk_content_categories_parent FOREIGN KEY (parent_id) REFERENCES content_categories (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION enforce_content_category_parent_type() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                ancestor_depth integer := 0;
                descendant_depth integer := 0;
                category_cycle boolean := false;
            BEGIN
                PERFORM pg_advisory_xact_lock(1667590501, 1);

                IF NEW.parent_id = NEW.id THEN
                    RAISE EXCEPTION 'category cannot be its own parent' USING ERRCODE = '23514';
                END IF;

                IF NEW.parent_id IS NOT NULL AND EXISTS (
                    SELECT 1
                    FROM content_categories AS parent
                    WHERE parent.id = NEW.parent_id
                      AND parent.content_type <> NEW.content_type
                ) THEN
                    RAISE EXCEPTION 'category parent must use the same content type' USING ERRCODE = '23514';
                END IF;

                IF NEW.parent_id IS NOT NULL THEN
                    WITH RECURSIVE ancestors(id, parent_id, depth, path, cycle) AS (
                        SELECT parent.id, parent.parent_id, 1, ARRAY[parent.id]::varchar[], false
                        FROM content_categories AS parent
                        WHERE parent.id = NEW.parent_id
                        UNION ALL
                        SELECT parent.id,
                               parent.parent_id,
                               ancestors.depth + 1,
                               ancestors.path || parent.id,
                               parent.id = ANY(ancestors.path)
                        FROM content_categories AS parent
                        INNER JOIN ancestors ON parent.id = ancestors.parent_id
                        WHERE NOT ancestors.cycle
                          AND ancestors.depth <= 8
                    )
                    SELECT COALESCE(bool_or(id = NEW.id OR cycle), false), COALESCE(MAX(depth), 0)
                    INTO category_cycle, ancestor_depth
                    FROM ancestors;

                    IF category_cycle THEN
                        RAISE EXCEPTION 'category hierarchy cannot contain a cycle' USING ERRCODE = '23514';
                    END IF;
                END IF;

                WITH RECURSIVE descendants(id, depth, path, cycle) AS (
                    SELECT child.id, 1, ARRAY[child.id]::varchar[], false
                    FROM content_categories AS child
                    WHERE child.parent_id = NEW.id
                      AND child.id <> NEW.id
                    UNION ALL
                    SELECT child.id,
                           descendants.depth + 1,
                           descendants.path || child.id,
                           child.id = ANY(descendants.path)
                    FROM content_categories AS child
                    INNER JOIN descendants ON child.parent_id = descendants.id
                    WHERE NOT descendants.cycle
                      AND descendants.depth <= 8
                )
                SELECT COALESCE(MAX(depth), 0), COALESCE(bool_or(cycle), false)
                INTO descendant_depth, category_cycle
                FROM descendants;

                IF category_cycle OR ancestor_depth + 1 + descendant_depth > 8 THEN
                    RAISE EXCEPTION 'category hierarchy exceeds eight levels or contains a cycle' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM content_categories AS child
                    WHERE child.parent_id = NEW.id
                      AND child.content_type <> NEW.content_type
                ) THEN
                    RAISE EXCEPTION 'category parent type cannot change while children use another content type' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_content_categories_parent_type BEFORE INSERT OR UPDATE OF parent_id, content_type ON content_categories FOR EACH ROW EXECUTE FUNCTION enforce_content_category_parent_type()');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_categories_type_slug ON content_categories (content_type, slug)');
        $this->addSql('CREATE INDEX idx_content_categories_parent_order ON content_categories (parent_id, sort_order, name)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('content_categories');
        $customized = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM content_categories
            WHERE parent_id IS NOT NULL
               OR description <> ''
               OR meta_title IS NOT NULL
               OR meta_description IS NOT NULL
               OR meta_keywords IS NOT NULL
               OR sort_order <> 0
               OR slug <> COALESCE(
                    NULLIF(trim(BOTH '-' FROM lower(regexp_replace(name, '[^a-zA-Z0-9]+', '-', 'g'))), ''),
                    'category'
               )
            SQL);
        if (!in_array($customized, [0, '0'], true)) {
            throw new \RuntimeException('Kategorien-Metadaten und Hierarchien dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('DROP INDEX idx_content_categories_parent_order');
        $this->addSql('DROP INDEX uniq_content_categories_type_slug');
        $this->addSql('DROP TRIGGER trg_content_categories_parent_type ON content_categories');
        $this->addSql('DROP FUNCTION enforce_content_category_parent_type()');
        $this->addSql('ALTER TABLE content_categories DROP CONSTRAINT fk_content_categories_parent');
        $this->addSql('ALTER TABLE content_categories DROP CONSTRAINT chk_content_categories_sort_order');
        $this->addSql('ALTER TABLE content_categories DROP CONSTRAINT chk_content_categories_description_length');
        $this->addSql('ALTER TABLE content_categories DROP CONSTRAINT chk_content_categories_slug');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN parent_id');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN sort_order');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN meta_keywords');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN meta_description');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN meta_title');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN description');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN slug');
    }
}
