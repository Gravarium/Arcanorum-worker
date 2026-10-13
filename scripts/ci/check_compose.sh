#!/usr/bin/env bash
set -Eeuo pipefail

readonly repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly project_name="arcanorum-aw002-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-$$"
readonly postgres_user="walker_local"
readonly postgres_database="walker_test"

cd "$repository_root"

compose=(docker compose --project-name "$project_name")

cleanup() {
    "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${compose[@]}" config --quiet
"${compose[@]}" up --detach --wait --wait-timeout 120

postgres_version_num="$("${compose[@]}" exec -T postgres     psql --username "$postgres_user" --dbname "$postgres_database"     --tuples-only --no-align --command 'SHOW server_version_num;')"
postgres_version_num="${postgres_version_num//[[:space:]]/}"

if [[ ! "$postgres_version_num" =~ ^[0-9]+$ ]] || (( postgres_version_num / 10000 != 18 )); then
    printf 'Expected PostgreSQL major version 18, got server_version_num=%q\n' "$postgres_version_num" >&2
    exit 1
fi

redis_response="$("${compose[@]}" exec -T redis redis-cli --raw ping)"
redis_response="${redis_response//$'\r'/}"
redis_response="${redis_response//$'\n'/}"

if [[ "$redis_response" != "PONG" ]]; then
    printf 'Expected Redis PONG, got %q\n' "$redis_response" >&2
    exit 1
fi

printf 'Compose check passed: PostgreSQL 18 and Redis PONG.\n'
