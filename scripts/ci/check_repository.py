#!/usr/bin/env python3
from __future__ import annotations

import json
import pathlib
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[2]
errors: list[str] = []

LOCAL_SUPPRESSION_PATTERNS = (
    ("PHPStan blanket ignore", re.compile(r"@phpstan-ignore\s+all\b", re.IGNORECASE)),
    ("PHPStan line ignore", re.compile(r"@phpstan-ignore-(?:next-)?line\b", re.IGNORECASE)),
    ("Psalm suppression", re.compile(r"@psalm-suppress\b", re.IGNORECASE)),
)
PHPSTAN_FORBIDDEN_CONFIG_PATTERN = re.compile(
    r"(?im)^\s*(?:ignoreErrors|excludePaths|universalObjectCratesClasses|scanDirectories)\s*:"
)
PHPSTAN_BASELINE_REFERENCE_PATTERN = re.compile(r"(?im)^\s*-\s*[^#\n]*baseline[^#\n]*$")
SECURITY_GUARD_PATTERN_TESTS = (
    ("PHPStan blanket ignore", LOCAL_SUPPRESSION_PATTERNS[0][1], "@phpstan-ignore all", True),
    ("PHPStan next-line ignore", LOCAL_SUPPRESSION_PATTERNS[1][1], "@phpstan-ignore-next-line", True),
    ("PHPStan line ignore", LOCAL_SUPPRESSION_PATTERNS[1][1], "@phpstan-ignore-line", True),
    ("Psalm suppression", LOCAL_SUPPRESSION_PATTERNS[2][1], "@psalm-suppress MissingReturnType", True),
    ("PHPStan ignoreErrors option", PHPSTAN_FORBIDDEN_CONFIG_PATTERN, "parameters:\n    ignoreErrors: []", True),
    ("PHPStan excludePaths option", PHPSTAN_FORBIDDEN_CONFIG_PATTERN, "parameters:\n    excludePaths:\n        analyse: []", True),
    ("PHPStan baseline include", PHPSTAN_BASELINE_REFERENCE_PATTERN, "includes:\n    - phpstan-baseline.neon", True),
    ("ordinary PHPStan level", PHPSTAN_FORBIDDEN_CONFIG_PATTERN, "parameters:\n    level: 10", False),
)
for label, pattern, sample, expected_match in SECURITY_GUARD_PATTERN_TESTS:
    if (pattern.search(sample) is not None) != expected_match:
        errors.append(f"security guard self-test failed: {label}")


def tracked_paths() -> list[pathlib.Path]:
    raw = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    return [
        ROOT / item.decode("utf-8", "surrogateescape")
        for item in raw.split(b"\0")
        if item
    ]


def forbidden_path(path: pathlib.Path) -> bool:
    relative = path.relative_to(ROOT).as_posix()
    parts = relative.lower().split("/")
    name = parts[-1]
    if name != ".env.dist" and (name == ".env" or name.startswith(".env.")):
        return True
    if any(part in {"backups", "vendor", "node_modules"} for part in parts):
        return True
    if relative.lower().startswith(("config/secrets/", "storage/private/", "public/uploads/")):
        return True
    if path.suffix.lower() in {".sql", ".dump", ".sqlite", ".db", ".pem", ".key"}:
        return True
    return False


paths = tracked_paths()
for path in paths:
    relative = path.relative_to(ROOT).as_posix()
    lower_name = path.name.lower()

    if forbidden_path(path):
        errors.append(f"{relative}: lokaler, privater oder ausführbarer Datenpfad ist nicht zulässig")

    if "baseline" in lower_name and lower_name.endswith((".neon", ".xml", ".php", ".json", ".yaml", ".yml")):
        errors.append(f"{relative}: Analyse-Baselines sind nicht zulässig")

    try:
        data = path.read_bytes()
    except (OSError, IsADirectoryError):
        continue

    if re.search(rb"-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----", data):
        errors.append(f"{relative}: privater Schlüssel erkannt")
    if re.search(rb"\bAKIA[0-9A-Z]{16}\b", data):
        errors.append(f"{relative}: möglicher Cloud-Zugangsschlüssel erkannt")
    if re.search(rb"\bgh[pousr]_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{30,}\b", data):
        errors.append(f"{relative}: mögliches GitHub-Zugriffstoken erkannt")
    if re.search(rb"\bxox[baprs]-[A-Za-z0-9-]{20,}\b", data):
        errors.append(f"{relative}: mögliches Messaging-Zugriffstoken erkannt")

    if path.suffix.lower() == ".json":
        try:
            json.loads(data.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError) as error:
            errors.append(f"{relative}: ungültiges JSON: {error}")

    if relative in {"phpunit.dist.xml", "psalm.xml"}:
        try:
            ET.fromstring(data)
        except (ET.ParseError, UnicodeError) as error:
            errors.append(f"{relative}: ungültiges XML: {error}")

    if relative.endswith((".yml", ".yaml")):
        try:
            text = data.decode("utf-8")
        except UnicodeError:
            errors.append(f"{relative}: Workflow ist kein UTF-8-Text")
            continue
        for line_number, line in enumerate(text.splitlines(), start=1):
            match = re.match(r"^\s*uses:\s*([^\s#]+)", line)
            if not match:
                continue
            reference = match.group(1)
            if not reference.startswith("./") and re.search(r"@[0-9a-f]{40}$", reference) is None:
                errors.append(f"{relative}:{line_number}: externe Action muss auf einen vollständigen Commit-SHA gepinnt sein")

# Fail closed on blanket suppression of static-analysis findings. This check
# complements (and does not replace) PHPStan, Psalm and their own config validation.
for path in paths:
    relative = path.relative_to(ROOT).as_posix()
    if path.suffix.lower() not in {".php", ".neon", ".xml"}:
        continue
    if path.suffix.lower() == ".php" and not (
        relative.startswith(("src/", "tests/")) or relative in {".php-cs-fixer.dist.php", "rector.php"}
    ):
        continue
    try:
        content = path.read_text(encoding="utf-8")
    except (UnicodeError, OSError):
        continue
    for label, pattern in LOCAL_SUPPRESSION_PATTERNS:
        for match in pattern.finditer(content):
            line = content.count("\n", 0, match.start()) + 1
            errors.append(f"{relative}:{line}: lokale Analyse-Unterdrückung ist nicht zulässig ({label})")

phpstan_path = ROOT / "phpstan.neon.dist"
if not phpstan_path.is_file():
    errors.append("phpstan.neon.dist: Pflichtkonfiguration fehlt")
else:
    phpstan = phpstan_path.read_text(encoding="utf-8")
    if re.search(r"(?m)^\s*level:\s*10\s*$", phpstan) is None:
        errors.append("phpstan.neon.dist: PHPStan muss fest auf Stufe 10 stehen")
    if "phpstan-strict-rules/rules.neon" not in phpstan:
        errors.append("phpstan.neon.dist: phpstan-strict-rules muss aktiviert sein")
    if PHPSTAN_FORBIDDEN_CONFIG_PATTERN.search(phpstan):
        errors.append("phpstan.neon.dist: pauschale Ignorier- oder Ausschlussregeln sind verboten")
    if PHPSTAN_BASELINE_REFERENCE_PATTERN.search(phpstan):
        errors.append("phpstan.neon.dist: Baseline-Einbindung ist verboten")

psalm_path = ROOT / "psalm.xml"
if not psalm_path.is_file():
    errors.append("psalm.xml: Pflichtkonfiguration fehlt")
else:
    psalm = ET.parse(psalm_path).getroot()
    if psalm.get("errorLevel") != "1" or psalm.get("runTaintAnalysis") != "true":
        errors.append("psalm.xml: Fehlerstufe 1 und Taintanalyse sind Pflicht")
    for element in psalm.iter():
        tag = element.tag.rsplit("}", 1)[-1]
        if tag in {"issueHandlers", "fileExtensions"}:
            errors.append(f"psalm.xml: {tag} darf Sicherheitsanalysen nicht unterdrücken")
        if tag == "ignoreFiles":
            for child in element:
                name = child.get("name", "")
                if name != "vendor":
                    errors.append(f"psalm.xml: unerlaubter Ausschluss {name!r}")

phpunit_path = ROOT / "phpunit.dist.xml"
if phpunit_path.is_file():
    phpunit = ET.parse(phpunit_path).getroot()
    for key in ("failOnRisky", "failOnWarning", "failOnDeprecation", "failOnEmptyTestSuite", "failOnIncomplete", "failOnSkipped", "beStrictAboutOutputDuringTests"):
        if phpunit.get(key) != "true":
            errors.append(f"phpunit.dist.xml: {key}=true ist Pflicht")

for required in (".php-cs-fixer.dist.php", "rector.php", "phpunit.dist.xml", "psalm.xml"):
    if not (ROOT / required).is_file():
        errors.append(f"{required}: Pflichtkonfiguration fehlt")

composer = ROOT / "composer.json"
app_php = [
    path for path in paths
    if path.suffix.lower() == ".php"
    and path.name not in {".php-cs-fixer.dist.php", "rector.php"}
]
if composer.is_file():
    for required in ("composer.lock", "bin/console", "src", "tests"):
        if not (ROOT / required).exists():
            errors.append(f"{required}: fehlt, obwohl composer.json ein PHP-Anwendungsprojekt aktiviert")
    if not any(path.name.endswith("Test.php") and "tests" in path.parts for path in paths):
        errors.append("tests: mindestens eine echte PHPUnit-Testsuite muss vorhanden sein")
    lock_path = ROOT / "composer.lock"
    if lock_path.is_file():
        lock_data = json.loads(lock_path.read_text(encoding="utf-8"))
        for package in lock_data.get("packages", []) + lock_data.get("packages-dev", []):
            name = package.get("name", "")
            if name.startswith("symfony/") and not (
                name.startswith("symfony/polyfill-") or name.endswith("-contracts")
            ):
                if re.fullmatch(r"v?7\.4\.[0-9]+", package.get("version", "")) is None:
                    errors.append(f"composer.lock: {name} muss zur festgelegten Symfony-7.4-LTS-Reihe gehören")
elif app_php:
    errors.append("composer.json: PHP-Anwendungscode ist vorhanden, aber composer.json fehlt")

if errors:
    for error in errors:
        print(f"::error::{error}")
    sys.exit(1)

print(f"Repository-Prüfung bestanden: {len(paths)} versionierte Pfade geprüft.")
