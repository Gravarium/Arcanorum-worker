#!/usr/bin/env bash
set -Eeuo pipefail

readonly repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repository_root"

php bin/console lint:container --env=dev
APP_ENV=prod APP_DEBUG=0 php bin/console lint:container --env=prod
php bin/console lint:yaml config
php bin/console lint:twig templates
APP_ENV=test php bin/console doctrine:schema:validate --skip-sync
APP_ENV=test php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
APP_ENV=test php bin/console doctrine:migrations:status --no-interaction
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console app:infrastructure:check --env=test --no-interaction --format=json
python3 scripts/ci/check_http.py

available_commands="$(php bin/console list --raw)"
if grep -q '^asset-map:compile' <<< "$available_commands"; then
    APP_ENV=prod APP_DEBUG=0 php bin/console asset-map:compile
fi
