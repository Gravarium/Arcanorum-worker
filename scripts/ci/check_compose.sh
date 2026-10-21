#!/usr/bin/env bash
set -Eeuo pipefail

readonly repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly project_name="arcanorum-aw002-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-$$"
readonly postgres_user="walker_local"
readonly postgres_database="walker_test"

cd "$repository_root"

compose=(docker compose --project-name "$project_name")
cleanup_armed=false

cleanup() {
    if [[ "$cleanup_armed" == true ]]; then
        "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi
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
TEST_POSTGRES_ENVIRONMENT = {
    "POSTGRES_DB": "walker_test",
    "POSTGRES_USER": "walker_local",
    "POSTGRES_PASSWORD": "local-test-only-password",
}
HEALTHCHECK_TESTS = {
    "postgres": {
        ("CMD-SHELL", "pg_isready -U $POSTGRES_USER -d $POSTGRES_DB"),
        ("CMD-SHELL", "pg_isready -U $$POSTGRES_USER -d $$POSTGRES_DB"),
    },
    "redis": {("CMD", "redis-cli", "ping")},
}


def validate(config):
    problems = []
    if config.get("volumes"):
        problems.append("declared Compose volumes are forbidden in the test environment")
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
        # Never let a local override attach existing data or change the image
        # source before the EXIT trap is permitted to remove project volumes.
        for field in ("volumes", "volumes_from", "secrets", "configs", "devices", "build", "env_file"):
            if service.get(field):
                problems.append(f"{name}: {field} is forbidden in the test environment")
        expected_environment = TEST_POSTGRES_ENVIRONMENT if name == "postgres" else {}
        if service.get("environment", {}) != expected_environment:
            problems.append(f"{name}: unexpected environment; only test-only values are allowed")
        healthcheck = service.get("healthcheck")
        if (
            not isinstance(healthcheck, dict)
            or healthcheck.get("disable") is True
            or not isinstance(healthcheck.get("test"), list)
            or tuple(healthcheck["test"]) not in HEALTHCHECK_TESTS[name]
            or any(not healthcheck.get(field) for field in ("interval", "timeout", "retries", "start_period"))
        ):
            problems.append(f"{name}: required service readiness healthcheck is missing or changed")
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

unsafe_data = {
    "existing PostgreSQL host data": ("postgres", "volumes", [{"type": "bind", "source": "/tmp/existing-data", "target": "/var/lib/postgresql"}]),
    "production-like PostgreSQL password": ("postgres", "environment", {**TEST_POSTGRES_ENVIRONMENT, "POSTGRES_PASSWORD": "different-value"}),
    "Redis build override": ("redis", "build", {"context": "."}),
    "disabled PostgreSQL healthcheck": ("postgres", "healthcheck", {"disable": True}),
    "missing Redis healthcheck": ("redis", "healthcheck", {}),
    "fake Redis healthcheck": ("redis", "healthcheck", {"test": ["CMD", "true"], "interval": 5, "timeout": 5, "retries": 20, "start_period": 5}),
}
for label, (service, field, value) in unsafe_data.items():
    candidate = copy.deepcopy(config)
    candidate["services"][service][field] = value
    if not validate(candidate):
        print(f"::error::security guard negative test failed: {label}", file=sys.stderr)
        sys.exit(1)

candidate = copy.deepcopy(config)
candidate["volumes"] = {"existing-data": {}}
if not validate(candidate):
    print("::error::security guard negative test failed: declared volume", file=sys.stderr)
    sys.exit(1)

print("Compose guard passed: localhost-only test services and 11 negative tests.")
'

cleanup_armed=true
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

# A service responding to PING or a version query is not enough: perform an
# actual write/read round-trip in a session-local PostgreSQL temporary table.
postgres_roundtrip="$("${compose[@]}" exec -T postgres \
    psql --no-psqlrc --username "$postgres_user" --dbname "$postgres_database" \
    --set ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --command "CREATE TEMP TABLE walker_ci_probe (token text NOT NULL); INSERT INTO walker_ci_probe (token) VALUES ('aw002-postgres-roundtrip'); SELECT token FROM walker_ci_probe;")"
if [[ "$postgres_roundtrip" != "aw002-postgres-roundtrip" ]]; then
    printf 'PostgreSQL write/read round-trip failed: %q\n' "$postgres_roundtrip" >&2
    exit 1
fi

# Write, read and expire a test-only cache entry. Container/volume cleanup is
# still guaranteed by the EXIT trap, even if a verification step fails.
redis_key="walker:aw002:probe:${project_name}"
redis_value="aw002-redis-roundtrip"
redis_write="$("${compose[@]}" exec -T redis redis-cli --raw SET "$redis_key" "$redis_value" EX 3)"
if [[ "$redis_write" != "OK" ]]; then
    printf 'Redis cache SET failed: %q\n' "$redis_write" >&2
    exit 1
fi
redis_read="$("${compose[@]}" exec -T redis redis-cli --raw GET "$redis_key")"
if [[ "$redis_read" != "$redis_value" ]]; then
    printf 'Redis cache GET returned unexpected value: %q\n' "$redis_read" >&2
    exit 1
fi

redis_expired=false
for attempt in 1 2 3 4 5 6 7 8; do
    if [[ "$("${compose[@]}" exec -T redis redis-cli --raw TTL "$redis_key")" == "-2" ]]; then
        redis_expired=true
        break
    fi
    sleep 1
done
if [[ "$redis_expired" != true ]]; then
    printf 'Redis cache entry did not expire within the test window\n' >&2
    exit 1
fi

printf 'Compose check passed: PostgreSQL 18 and Redis 8; live write/read and cache expiry verified.\n'
