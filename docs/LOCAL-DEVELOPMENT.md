# Lokaler technischer Start (AW-001)

Benötigt PHP 8.5, Composer 2, PostgreSQL 18 und Redis 8. Lokale Zugangsdaten gehören ausschließlich in unversionierte Umgebungsvariablen, nie in Git.

1. `composer validate --strict`
2. `composer install --no-interaction --prefer-dist`
3. `composer audit --locked`
4. `APP_ENV=dev php bin/console lint:container --env=dev`
5. `APP_ENV=prod APP_DEBUG=0 php bin/console lint:container --env=prod`
6. `php bin/console lint:yaml config` und `php bin/console lint:twig templates`
7. `APP_ENV=test php bin/console doctrine:schema:validate --skip-sync`
8. `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction` (falls Migrationen existieren)
9. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress`
10. `vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --diff --using-cache=no`
11. `vendor/bin/rector process --config=rector.php --dry-run --no-progress-bar`
12. `vendor/bin/psalm --config=psalm.xml --taint-analysis`
13. `vendor/bin/phpunit --configuration=phpunit.dist.xml --fail-on-risky --fail-on-warning`

Der Container erwartet APP_SECRET; Integrationstests benötigen DATABASE_URL und REDIS_URL. Zum Start werden keine Produktfunktionen bereitgestellt.

**Stand:** Ein vollständiges `composer.lock` muss von einem echten PHP-8.5-/Composer-Lauf erzeugt, committed und auf dem exakten PR-HEAD geprüft werden. Die separate `resolve-lock`-CI bietet die Lockdatei als Artefakt an. Solange sie nicht committed und der Pflichtjob nicht grün ist, ist AW-001 nicht abgenommen.
