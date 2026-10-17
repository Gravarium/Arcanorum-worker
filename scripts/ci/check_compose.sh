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

# Validate Docker Compose's resolved configuration, not just checked-in YAML.
# Overrides or insecure host mappings must be rejected before starting services.
"${compose[@]}" config --format json | python3 -c '
import copy
import json
import sys

EXPECTED = {"postgres": ("postgres:18", 5432), "redis": ("redis:8-alpine", 6379)}


def validate(config):
    problems = []
    services = config.get("services", {})
    if not isinstance(services, dict) or set(services) != set(EXPECTED):
        return ["unexpected Compose services"]
    for name, (image, target_port) in EXPECTED.items():
        service = services[name]
        if not isinstance(service, dict):
            problems.append(f"{name}: invalid service configuration")
            continue
        if service.get("image") != image:
            problems.append(f"{name}: unexpected image; expected {image}")
        if service.get("privileged") is True or service.get("network_mode") == "host":
            problems.append(f"{name}: privileged or host networking is forbidden")
        ports = service.get("ports", [])
        if not isinstance(ports, list) or len(ports) != 1:
            problems.append(f"{name}: exactly one loopback port binding is required")
            continue
        port = ports[0]
        if not isinstance(port, dict) or (
            port.get("host_ip") != "127.0.0.1"
            or port.get("target") != target_port
            or str(port.get("published")) != str(target_port)
            or port.get("protocol", "tcp") != "tcp"
        ):
            problems.append(f"{name}: unsafe or unexpected published port")
    return problems


config = json.load(sys.stdin)
errors = validate(config)
if errors:
    for error in errors:
        print(f"::error::{error}", file=sys.stderr)
    sys.exit(1)

# Fail the real check if the guard itself accepts common unsafe changes.
mutations = {
    "publicly exposed PostgreSQL": ("postgres", "host_ip", "0.0.0.0"),
    "publicly exposed Redis": ("redis", "host_ip", "0.0.0.0"),
    "missing Redis loopback binding": ("redis", "host_ip", None),
    "wrong PostgreSQL port": ("postgres", "target", 5433),
}
for label, (service, field, value) in mutations.items():
    candidate = copy.deepcopy(config)
    candidate["services"][service]["ports"][0][field] = value
    if not validate(candidate):
        print(f"::error::security guard negative test failed: {label}", file=sys.stderr)
        sys.exit(1)

print("Compose port guard passed: localhost-only bindings and 4 negative tests.")
'

"${compose[@]}" up --detach --wait --wait-timeout 120

# Verify the published addresses after Docker has started the containers.
for entry in "postgres 5432" "redis 6379"; do
    read -r service target_port <<< "$entry"
    published_address="$("${compose[@]}" port "$service" "$target_port")"
    if [[ ! "$published_address" =~ ^127\.0\.0\.1:[0-9]+$ ]]; then
        printf 'Unsafe published port for %s: %q\n' "$service" "$published_address" >&2
        exit 1
    fi
done

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

redis_version="$( "${compose[@]}" exec -T redis redis-cli --raw INFO server | sed -n 's/^redis_version://p' | tr -d '\r' )"
if [[ ! "$redis_version" =~ ^8\. ]]; then
    printf 'Expected Redis major version 8, got redis_version=%q\n' "$redis_version" >&2
    exit 1
fi

printf 'Compose check passed: PostgreSQL 18 and Redis 8 PONG.\n'
