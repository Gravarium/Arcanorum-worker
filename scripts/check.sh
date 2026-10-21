#!/usr/bin/env bash
set -Eeuo pipefail

readonly repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repository_root"

: "${APP_SECRET:?Set APP_SECRET to a local test-only value.}"
: "${DATABASE_URL:?Set DATABASE_URL to the local PostgreSQL 18 test database.}"
: "${REDIS_URL:?Set REDIS_URL to the local Redis 8 test service.}"
export APP_ENV=test APP_DEBUG=0

php -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) { fwrite(STDERR, "PHP 8.5 is required.\n"); exit(1); }'
git diff --check
python3 scripts/ci/check_repository.py
ruby scripts/ci/assert_mandatory_quality_gate.rb
bash scripts/ci/check_compose.sh --self-test
python3 -m unittest discover -s scripts/ci -p 'test_*.py'
composer validate --strict --no-interaction
composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader
composer audit --locked --no-interaction

mapfile -d '' php_files < <(git ls-files -z -- '*.php')
if [[ ${#php_files[@]} -eq 0 ]]; then
    printf 'No tracked PHP files were found.\n' >&2
    exit 1
fi
for file in "${php_files[@]}"; do
    php -l "$file"
done

bash scripts/ci/check_application.sh
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --error-format=github --memory-limit=1G
vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --diff --using-cache=no
vendor/bin/rector process --config=rector.php --dry-run --no-progress-bar --output-format=github
mkdir -p var/test-results
vendor/bin/psalm --config=psalm.xml --taint-analysis --report=var/test-results/psalm.sarif
vendor/bin/phpunit --configuration=phpunit.dist.xml --debug --fail-on-risky --fail-on-warning --log-junit=var/test-results/junit.xml --coverage-text --coverage-clover=var/test-results/coverage.xml
python3 scripts/ci/check_reports.py
