#!/usr/bin/env bash
set -Eeuo pipefail

readonly repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

if [[ "${1:-}" == --self-test && $# -eq 1 ]]; then
    python3 - "${BASH_SOURCE[0]}" <<'PY'
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

script = str(Path(sys.argv[1]).resolve())
mock = r'''#!/usr/bin/env python3
import json, os, signal, sys
from pathlib import Path
args = sys.argv[1:]
case = os.environ["AW002_CASE"]
project = args[args.index("--project-name") + 1]
path = Path(args[args.index("--file") + 1])
operation = next(x for x in args[1:] if x in {"config", "up", "port", "exec", "down"})
call = {"operation": operation, "file": str(path), "project": project}
if path.name == "compose.json":
    call.update(mode=path.stat().st_mode & 0o777, directory_mode=path.parent.stat().st_mode & 0o777, model=json.loads(path.read_text()))
with open(os.environ["AW002_LOG"], "a") as out:
    out.write(json.dumps(call) + "\n")
assert args[0] == "compose" and args[args.index("--env-file") + 1] == "/dev/null"
assert "--project-directory" in args
if operation == "config":
    if path.name == "compose.yaml":
        model = {"name": project, "networks": {"default": {"name": project + "_default"}}, "services": {}}
        for name, image, port in [("postgres", "postgres:18", 5432), ("redis", "redis:8-alpine", 6379)]:
            model["services"][name] = {"image": image, "networks": {"default": None}, "ports": [{"mode": "ingress", "host_ip": "127.0.0.1", "target": port, "published": str(port), "protocol": "tcp"}], "healthcheck": {"test": ["CMD-SHELL", "pg_isready -U $POSTGRES_USER -d $POSTGRES_DB"] if name == "postgres" else ["CMD", "redis-cli", "ping"], "interval": "5s", "timeout": "5s", "retries": 20, "start_period": "10s" if name == "postgres" else "5s"}}
        model["services"]["postgres"]["environment"] = {"POSTGRES_DB": "walker_test", "POSTGRES_USER": "walker_local", "POSTGRES_PASSWORD": "local-test-only-password"}
        for service in model["services"].values():
            service.update(command=None, entrypoint=None)
        if case.startswith("override:"):
            key, value = json.loads(case.split(":", 1)[1]); model["services"]["redis"][key] = value
        if case == "network": model["networks"]["default"]["external"] = True
        if case == "volume": model["volumes"] = {"existing": {}}
        if case == "password": model["services"]["postgres"]["environment"]["POSTGRES_PASSWORD"] = "private-regression-marker"
        if case == "public": model["services"]["redis"]["ports"][0]["host_ip"] = "0.0.0.0"
        if case == "malformed": print("bad-json private-regression-marker"); sys.exit(0)
        if case == "resolve": sys.exit(19)
        if case == "non-object": model = []
    else:
        def unescape(value):
            if isinstance(value, str): return value.replace("$$", "$")
            if isinstance(value, dict): return {k: unescape(v) for k, v in value.items()}
            if isinstance(value, list): return [unescape(v) for v in value]
            return value
        model = unescape(json.loads(path.read_text()))
        if case == "roundtrip": model["services"]["redis"]["ports"][0]["host_ip"] = "0.0.0.0"
    print(json.dumps(model))
elif operation == "up":
    if case in ("up", "up-and-cleanup"): sys.exit(17)
    if case in ("TERM", "INT", "HUP"): os.kill(os.getppid(), getattr(signal, "SIG" + case))
elif operation == "port": print(("0.0.0.0:" if case == "published" else "127.0.0.1:") + args[-1])
elif operation == "exec":
    if "psql" in args:
        print(("170001" if case == "postgres" else "180004") if "SHOW server_version_num;" in args else ("bad" if case == "database" else "aw002-postgres-roundtrip"))
    elif "ping" in args: print("NO" if case == "ping" else "PONG")
    elif "INFO" in args: print("redis_version:7.4.0" if case == "redis" else "redis_version:8.2.1")
    elif "SET" in args: print("NO" if case == "write" else "OK")
    elif "GET" in args: print("bad" if case == "read" else "aw002-redis-roundtrip")
    elif "TTL" in args: print("2" if case == "expiry" else "-2")
    else: sys.exit(91)
elif operation == "down" and case in ("cleanup", "up-and-cleanup"): sys.exit(23)
'''
overrides = {"cap_add": ["SYS_ADMIN"], "pid": "host", "ipc": "host", "privileged": True, "network_mode": "host", "devices": ["/dev/kvm:/dev/kvm"], "volumes": [{"type": "bind", "source": "/tmp/existing-data", "target": "/data"}], "volumes_from": ["existing"], "build": {"context": "."}, "env_file": ["private.env"], "secrets": ["existing"], "configs": ["existing"], "entrypoint": ["sh", "-c", "exit 0"], "command": ["sh", "-c", "exit 0"], "container_name": "existing", "profiles": ["hidden"], "security_opt": ["apparmor:unconfined"], "use_api_socket": True, "healthcheck": {"disable": True}, "image": "redis:7-alpine"}
invalid = ["override:" + json.dumps([k, v]) for k, v in overrides.items()] + ["network", "volume", "password", "public", "malformed", "resolve", "non-object", "roundtrip"]
invalid += ["override:" + json.dumps([key, value]) for key in ("command", "entrypoint") for value in ([], "")]
runtime = ["up", "published", "postgres", "database", "ping", "redis", "write", "read", "expiry", "cleanup", "up-and-cleanup", "TERM", "INT", "HUP"]
cases = ["valid", "relative-temp"] + invalid + runtime
with tempfile.TemporaryDirectory(prefix="aw002-regression-") as directory:
    root = Path(directory)
    for name, content in [("docker", mock), ("sleep", "#!/bin/sh\nexit 0\n")]:
        (root / name).write_text(content); (root / name).chmod(0o700)
    projects = set()
    temporary_work = root / "work"
    temporary_work.mkdir()
    for index, case in enumerate(cases):
        log = root / f"calls-{index}.jsonl"
        environment = dict(os.environ, PATH=str(root) + os.pathsep + os.environ["PATH"], TMPDIR=str(temporary_work), AW002_CASE=case, AW002_LOG=str(log), COMPOSE_FILE="untrusted.yaml", COMPOSE_PROJECT_NAME="existing-project", POSTGRES_USER="private-regression-marker", POSTGRES_DB="private-regression-marker")
        if case == "relative-temp":
            environment["TMPDIR"] = "work"
        result = subprocess.run(["bash", script], cwd=root, env=environment, capture_output=True, text=True, timeout=15)
        calls = [json.loads(line) for line in log.read_text().splitlines()]
        assert (result.returncode == 0) == (case in ("valid", "relative-temp")), (case, result.returncode, result.stdout, result.stderr)
        assert "private-regression-marker" not in result.stdout + result.stderr, case
        assert not list(temporary_work.iterdir()), (case, "temporary directory leaked")
        starts = [c for c in calls if c["operation"] == "up"]
        cleanups = [c for c in calls if c["operation"] == "down"]
        assert len(starts) == (0 if case in invalid else 1) and len(cleanups) == len(starts), (case, "lifecycle")
        source_calls = [c for c in calls if Path(c["file"]).name == "compose.yaml"]
        assert len(source_calls) == 1 and source_calls[0]["operation"] == "config", case
        project = calls[0]["project"]
        assert project not in projects and project != "existing-project" and all(c["project"] == project for c in calls), case
        projects.add(project)
        snapshots = [c for c in calls if Path(c["file"]).name == "compose.json"]
        if snapshots:
            path = Path(snapshots[0]["file"])
            assert all(c["file"] == str(path) and c["mode"] == 0o400 and c["directory_mode"] == 0o700 for c in snapshots), case
            assert not path.exists() and not path.parent.exists(), (case, "temporary files")
            health = snapshots[0]["model"]["services"]["postgres"]["healthcheck"]["test"][1]
            assert "$$POSTGRES_USER" in health and "$$POSTGRES_DB" in health, case
        if case in ("TERM", "INT", "HUP", "up", "up-and-cleanup"):
            assert result.returncode == {"TERM": 143, "INT": 130, "HUP": 129, "up": 17, "up-and-cleanup": 17}[case], case
        if case == "cleanup": assert "Compose check passed" not in result.stdout
    result = subprocess.run(["bash", script, "--unknown"], capture_output=True, timeout=5)
    assert result.returncode == 2
    result = subprocess.run(["bash", script], env=dict(environment, TMPDIR=str(root / "does-not-exist")), capture_output=True, timeout=5)
    assert result.returncode != 0
print(f"Compose lifecycle regression tests passed: {len(cases)} paths plus invalid argument and unavailable temporary directory.")
PY
    exit 0
fi
if [[ $# -ne 0 ]]; then
    printf 'Usage: %s [--self-test]\n' "${BASH_SOURCE[0]}" >&2
    exit 2
fi

work_directory="$(mktemp -d "${TMPDIR:-/tmp}/arcanorum-aw002.XXXXXXXXXX")"
if [[ "$work_directory" != /* ]]; then
    work_directory="$(pwd -P)/$work_directory"
fi
readonly work_directory
project_token="${work_directory##*.}"
readonly project_name="arcanorum-aw002-${project_token,,}-$$"
readonly snapshot="$work_directory/compose.json"
readonly postgres_user="walker_local"
readonly postgres_database="walker_test"

cd "$repository_root"

compose=(docker compose --project-directory "$repository_root" --env-file /dev/null --project-name "$project_name" --file "$snapshot")
cleanup_armed=false
checks_passed=false

cleanup() {
    local result=$?
    trap - EXIT
    trap '' INT TERM HUP
    if [[ "$cleanup_armed" == true ]]; then
        if ! "${compose[@]}" down --timeout 10 --volumes --remove-orphans >"$work_directory/cleanup.log" 2>&1; then
            printf '::error::Isolated Compose cleanup failed.\n' >&2
            if [[ "$result" -eq 0 ]]; then result=1; fi
        fi
    fi
    python3 - "$work_directory" <<'PY'
from pathlib import Path
import sys
directory = Path(sys.argv[1])
for name in ("resolved.json", "compose.json", "roundtrip.json", "cleanup.log"):
    (directory / name).unlink(missing_ok=True)
directory.rmdir()
PY
    if [[ "$result" -eq 0 && "$checks_passed" == true ]]; then
        printf 'Compose check passed: PostgreSQL 18 and Redis 8; immutable configuration, live write/read, cache expiry and cleanup verified.\n'
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

# Resolve the checked-in file exactly once; never re-read mutable source files
# after validation. Explicit options disable implicit override/env selection.
docker compose --project-directory "$repository_root" --env-file /dev/null --project-name "$project_name" --file "$repository_root/compose.yaml" config --format json >"$work_directory/resolved.json"

# Validate Docker Compose's resolved configuration, not just checked-in YAML.
# Overrides or insecure host mappings must be rejected before starting services.
python3 - "$work_directory/resolved.json" "$snapshot" "$project_name" <<'PY'
import copy
import json
from pathlib import Path
import sys

source, destination, project = sys.argv[1:]

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
    if not isinstance(config, dict) or set(config) != {"name", "services", "networks"}:
        return ["only the isolated project, services and default network are allowed"]
    problems = []
    if config.get("name") != project:
        problems.append("unexpected project name")
    if config.get("networks") != {"default": {"name": project + "_default"}}:
        problems.append("only the isolated default network is allowed")
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
        if set(service) - {"image", "environment", "healthcheck", "ports", "networks", "command", "entrypoint"}:
            problems.append(f"{name}: unsupported runtime override")
        # Compose represents an absent image command as JSON null. Empty
        # strings/lists explicitly clear it and must still be rejected.
        if service.get("command") is not None or service.get("entrypoint") is not None:
            problems.append(f"{name}: image commands must not be overridden")
        if service.get("networks") not in ({"default": None}, {"default": {}}):
            problems.append(f"{name}: unexpected service network")
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
            or set(healthcheck) != {"test", "interval", "timeout", "retries", "start_period"}
            or healthcheck.get("disable") is True
            or not isinstance(healthcheck.get("test"), list)
            or tuple(healthcheck["test"]) not in HEALTHCHECK_TESTS[name]
            or healthcheck.get("interval") != "5s"
            or healthcheck.get("timeout") != "5s"
            or type(healthcheck.get("retries")) is not int
            or healthcheck.get("retries") != 20
            or healthcheck.get("start_period") != ("10s" if name == "postgres" else "5s")
        ):
            problems.append(f"{name}: required service readiness healthcheck is missing or changed")
        ports = service.get("ports", [])
        if not isinstance(ports, list) or len(ports) != 1:
            problems.append(f"{name}: exactly one loopback port binding is required")
            continue
        port = ports[0]
        if not isinstance(port, dict) or (
            set(port) - {"mode", "host_ip", "target", "published", "protocol"}
            or port.get("host_ip") != "127.0.0.1"
            or type(port.get("target")) is not int
            or port.get("target") != target_port
            or str(port.get("published")) != str(target_port)
            or port.get("protocol", "tcp") != "tcp"
            or port.get("mode", "ingress") != "ingress"
        ):
            problems.append(f"{name}: unsafe or unexpected published port")
    return problems


try:
    config = json.loads(Path(source).read_text())
    errors = validate(config)
except (OSError, ValueError, TypeError, KeyError):
    print("::error::Compose configuration could not be verified.", file=sys.stderr)
    sys.exit(1)
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

runtime_overrides = {
    "cap_add": ["SYS_ADMIN"], "pid": "host", "ipc": "host", "privileged": True,
    "network_mode": "host", "entrypoint": ["sh", "-c", "exit 0"],
    "command": ["sh", "-c", "exit 0"], "container_name": "existing-container",
    "profiles": ["hidden"], "security_opt": ["apparmor:unconfined"],
    "use_api_socket": True,
}
for field, value in runtime_overrides.items():
    candidate = copy.deepcopy(config)
    candidate["services"]["redis"][field] = value
    if not validate(candidate):
        raise SystemExit("::error::Compose guard accepted a forbidden runtime override")
for change in ("external", "name"):
    candidate = copy.deepcopy(config)
    candidate["networks"]["default"][change] = True if change == "external" else "existing-network"
    if not validate(candidate):
        raise SystemExit("::error::Compose guard accepted a foreign network")

def escape_interpolation(value):
    if isinstance(value, str):
        return value.replace("$", "$$")
    if isinstance(value, dict):
        return {key: escape_interpolation(item) for key, item in value.items()}
    if isinstance(value, list):
        return [escape_interpolation(item) for item in value]
    return value

# Compose parses interpolation again when reading JSON/YAML. Preserve container
# healthcheck variables instead of substituting credentials from the host.
Path(destination).write_text(json.dumps(escape_interpolation(config), sort_keys=True))
print(f"Compose guard passed: isolated test configuration and {11 + len(runtime_overrides) + 2} negative tests.")
PY

chmod 400 "$snapshot"
"${compose[@]}" config --format json >"$work_directory/roundtrip.json"
python3 - "$work_directory/resolved.json" "$work_directory/roundtrip.json" <<'PY'
import json
from pathlib import Path
import sys
try:
    if json.loads(Path(sys.argv[1]).read_text()) != json.loads(Path(sys.argv[2]).read_text()):
        raise ValueError("changed model")
except (OSError, ValueError):
    print("::error::Compose snapshot changed during reloading; refusing container operations.", file=sys.stderr)
    sys.exit(1)
PY

cleanup_armed=true
"${compose[@]}" up --detach --wait --wait-timeout 120

# Verify the published addresses after Docker has started the containers.
for entry in "postgres 5432" "redis 6379"; do
    read -r service target_port <<< "$entry"
    published_address="$("${compose[@]}" port "$service" "$target_port")"
    if [[ ! "$published_address" =~ ^127\.0\.0\.1:[0-9]+$ ]]; then
        printf '::error::Unsafe published port for %s.\n' "$service" >&2
        exit 1
    fi
done

postgres_version_num="$("${compose[@]}" exec -T postgres psql --no-psqlrc --username "$postgres_user" --dbname "$postgres_database" --tuples-only --no-align --command 'SHOW server_version_num;')"
postgres_version_num="${postgres_version_num//[[:space:]]/}"

if [[ ! "$postgres_version_num" =~ ^18[0-9]{4}$ ]]; then
    printf '::error::Expected PostgreSQL major version 18.\n' >&2
    exit 1
fi

redis_response="$("${compose[@]}" exec -T redis redis-cli --raw ping)"
redis_response="${redis_response//$'\r'/}"
redis_response="${redis_response//$'\n'/}"

if [[ "$redis_response" != "PONG" ]]; then
    printf '::error::Expected Redis PONG.\n' >&2
    exit 1
fi

redis_version="$( "${compose[@]}" exec -T redis redis-cli --raw INFO server | sed -n 's/^redis_version://p' | tr -d '\r' )"
if [[ ! "$redis_version" =~ ^8\. ]]; then
    printf '::error::Expected Redis major version 8.\n' >&2
    exit 1
fi

# A service responding to PING or a version query is not enough: perform an
# actual write/read round-trip in a session-local PostgreSQL temporary table.
postgres_roundtrip="$("${compose[@]}" exec -T postgres \
    psql --no-psqlrc --username "$postgres_user" --dbname "$postgres_database" \
    --set ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --command "CREATE TEMP TABLE walker_ci_probe (token text NOT NULL); INSERT INTO walker_ci_probe (token) VALUES ('aw002-postgres-roundtrip'); SELECT token FROM walker_ci_probe;")"
if [[ "$postgres_roundtrip" != "aw002-postgres-roundtrip" ]]; then
    printf '::error::PostgreSQL write/read round-trip failed.\n' >&2
    exit 1
fi

# Write, read and expire a test-only cache entry. Container/volume cleanup is
# still guaranteed by the EXIT trap, even if a verification step fails.
redis_key="walker:aw002:probe:${project_name}"
redis_value="aw002-redis-roundtrip"
redis_write="$("${compose[@]}" exec -T redis redis-cli --raw SET "$redis_key" "$redis_value" EX 3)"
if [[ "$redis_write" != "OK" ]]; then
    printf '::error::Redis cache SET failed.\n' >&2
    exit 1
fi
redis_read="$("${compose[@]}" exec -T redis redis-cli --raw GET "$redis_key")"
if [[ "$redis_read" != "$redis_value" ]]; then
    printf '::error::Redis cache GET returned an unexpected value.\n' >&2
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

checks_passed=true
