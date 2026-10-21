"""Validate the PHPUnit, Clover and Psalm formats used by the mandatory gate."""
from __future__ import annotations

import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET


def read_xml(path: Path) -> ET.Element:
    try:
        source = path.read_text(encoding="utf-8-sig")
    except OSError:
        raise OSError(f"{path.name}: missing or unreadable report") from None
    except UnicodeError:
        raise ValueError(f"{path.name}: report must be UTF-8") from None
    if "<!DOCTYPE" in source.upper() or "<!ENTITY" in source.upper():
        raise ValueError(f"{path.name}: document types and entities are forbidden")
    try:
        root = ET.fromstring(source)
    except ET.ParseError:
        raise ValueError(f"{path.name}: malformed XML") from None
    if any("}" in element.tag for element in root.iter()):
        raise ValueError(f"{path.name}: unexpected XML namespace")
    return root


def counter(element: ET.Element, name: str, label: str, *, required: bool = True) -> int:
    value = element.get(name)
    if value is None and not required:
        return 0
    if value is None or re.fullmatch(r"[0-9]+", value) is None:
        raise ValueError(f"{label}: missing or invalid {name} counter")
    return int(value)


def validate_junit(root: ET.Element) -> int:
    label = "junit.xml"
    if root.tag not in {"testsuite", "testsuites"}:
        raise ValueError(f"{label}: no test suite")
    if any(element.tag in {"failure", "error", "skipped", "warning", "incomplete", "risky"} for element in root.iter()):
        raise ValueError(f"{label}: failed, skipped or incomplete test results")

    def visit(suite: ET.Element) -> int:
        count = 0
        for child in suite:
            if child.tag == "testsuite":
                count += visit(child)
            elif child.tag == "testcase" and suite.tag == "testsuite":
                if not child.get("name", "").strip():
                    raise ValueError(f"{label}: unnamed test case")
                count += 1
            elif child.tag not in {"properties", "system-out", "system-err"}:
                raise ValueError(f"{label}: unexpected suite content")
        for name in ("failures", "errors", "skipped", "disabled", "warnings"):
            required = suite.tag == "testsuite" and name in {"failures", "errors", "skipped"}
            if counter(suite, name, label, required=required) != 0:
                raise ValueError(f"{label}: nonzero {name} counter")
        if suite.tag == "testsuite" or "tests" in suite.attrib:
            if counter(suite, "tests", label) != count:
                raise ValueError(f"{label}: declared test count differs from executed cases")
        if "assertions" in suite.attrib:
            counter(suite, "assertions", label)
        return count

    count = visit(root)
    if count == 0 or count != sum(1 for _ in root.iter("testcase")):
        raise ValueError(f"{label}: empty suite or misplaced test cases")
    return count


METRIC_PAIRS = (
    ("methods", "coveredmethods"),
    ("conditionals", "coveredconditionals"),
    ("statements", "coveredstatements"),
    ("elements", "coveredelements"),
)
FILE_METRICS = ("loc", "ncloc", "classes") + tuple(name for pair in METRIC_PAIRS for name in pair)


def metrics(element: ET.Element, *, file: bool = False) -> dict[str, int]:
    names = FILE_METRICS if file else tuple(name for pair in METRIC_PAIRS for name in pair)
    values = {name: counter(element, name, "coverage.xml") for name in names}
    for total, covered in METRIC_PAIRS:
        if values[covered] > values[total]:
            raise ValueError("coverage.xml: covered count exceeds its total")
    if file and values["ncloc"] > values["loc"]:
        raise ValueError("coverage.xml: non-comment lines exceed source lines")
    if values["elements"] != sum(values[name] for name in ("methods", "conditionals", "statements")):
        raise ValueError("coverage.xml: inconsistent element total")
    if values["coveredelements"] != sum(values[name] for name in ("coveredmethods", "coveredconditionals", "coveredstatements")):
        raise ValueError("coverage.xml: inconsistent covered element total")
    return values


def validate_clover(root: ET.Element) -> int:
    projects = root.findall("project")
    if root.tag != "coverage" or len(projects) != 1:
        raise ValueError("coverage.xml: expected exactly one Clover project")
    project = projects[0]
    files = list(project.iter("file"))
    if not files:
        raise ValueError("coverage.xml: project contains no source files")
    totals = {name: 0 for name in FILE_METRICS}
    seen = set()
    for source in files:
        name = source.get("name", "")
        if not name.strip() or name in seen:
            raise ValueError("coverage.xml: missing or duplicate source file")
        seen.add(name)
        file_metrics = source.findall("metrics")
        if len(file_metrics) != 1:
            raise ValueError("coverage.xml: missing or duplicate file metrics")
        values = metrics(file_metrics[0], file=True)
        for key, value in values.items():
            totals[key] += value
        for class_metrics in source.findall("class/metrics"):
            metrics(class_metrics)
        for line in source.findall("line"):
            if counter(line, "num", "coverage.xml") == 0:
                raise ValueError("coverage.xml: source line numbers must be positive")
            counter(line, "count", "coverage.xml")
    project_metrics = project.findall("metrics")
    if len(project_metrics) != 1:
        raise ValueError("coverage.xml: missing or duplicate project metrics")
    summary = project_metrics[0]
    if counter(summary, "files", "coverage.xml") != len(files):
        raise ValueError("coverage.xml: file count differs from source entries")
    if metrics(summary, file=True) != totals:
        raise ValueError("coverage.xml: project totals differ from file metrics")
    return len(files)


def unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("psalm.sarif: duplicate JSON object key")
        result[key] = value
    return result


def reject_constant(_value: str) -> None:
    raise ValueError("psalm.sarif: non-finite JSON value")


def read_sarif(path: Path) -> object:
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"), object_pairs_hook=unique_object, parse_constant=reject_constant)
    except OSError:
        raise OSError("psalm.sarif: missing or unreadable report") from None
    except (json.JSONDecodeError, UnicodeError):
        raise ValueError("psalm.sarif: malformed JSON") from None


def validate_psalm(report: object) -> int:
    if not isinstance(report, dict) or report.get("version") != "2.1.0":
        raise ValueError("psalm.sarif: expected SARIF 2.1.0")
    runs = report.get("runs")
    if not isinstance(runs, list) or not runs:
        raise ValueError("psalm.sarif: no analysis run")
    for run in runs:
        if not isinstance(run, dict) or not isinstance(run.get("tool"), dict):
            raise ValueError("psalm.sarif: invalid analysis run")
        driver = run["tool"].get("driver")
        if not isinstance(driver, dict) or driver.get("name") != "Psalm":
            raise ValueError("psalm.sarif: expected the Psalm analyzer")
        version = driver.get("version")
        if not isinstance(version, str) or not version.strip():
            raise ValueError("psalm.sarif: missing analyzer version")
        results = run.get("results")
        if not isinstance(results, list) or results:
            raise ValueError("psalm.sarif: missing results or analysis findings")
        if "invocations" not in run:
            continue  # Psalm 6 reports do not emit invocation metadata.
        invocations = run["invocations"]
        if not isinstance(invocations, list) or not invocations:
            raise ValueError("psalm.sarif: invalid invocation metadata")
        for invocation in invocations:
            if not isinstance(invocation, dict) or invocation.get("executionSuccessful") is not True:
                raise ValueError("psalm.sarif: failed or unconfirmed analysis invocation")
            if "exitCode" in invocation and (type(invocation["exitCode"]) is not int or invocation["exitCode"] != 0):
                raise ValueError("psalm.sarif: unsuccessful analyzer exit code")
            for name in ("toolExecutionNotifications", "toolConfigurationNotifications"):
                notifications = invocation.get(name, [])
                if not isinstance(notifications, list):
                    raise ValueError("psalm.sarif: malformed analyzer notifications")
                for notification in notifications:
                    if not isinstance(notification, dict):
                        raise ValueError("psalm.sarif: malformed analyzer notification")
                    level = notification.get("level")
                    if not isinstance(level, str) or level not in {"none", "note"} or "exception" in notification:
                        raise ValueError("psalm.sarif: analyzer or configuration failure notification")
    return len(runs)
