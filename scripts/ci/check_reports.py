#!/usr/bin/env python3
"""Reject missing, malformed, empty, failed, or skipped quality reports."""
from __future__ import annotations

import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


def validate_reports(directory: Path) -> int:
    junit = ET.parse(directory / "junit.xml").getroot()
    if junit.tag not in {"testsuite", "testsuites"}:
        raise ValueError("JUnit report has no test suite")
    testcases = list(junit.iter("testcase"))
    if not testcases:
        raise ValueError("JUnit report contains no executed tests")
    for tag in ("failure", "error", "skipped"):
        if any(True for _ in junit.iter(tag)):
            raise ValueError(f"JUnit report contains {tag} results")
    for suite in junit.iter("testsuite"):
        for attribute in ("failures", "errors", "skipped"):
            if int(suite.get(attribute, "0")) != 0:
                raise ValueError(f"JUnit suite has nonzero {attribute}")

    coverage = ET.parse(directory / "coverage.xml").getroot()
    if coverage.tag != "coverage" or coverage.find("project") is None:
        raise ValueError("Coverage report is not a Clover project")

    sarif = json.loads((directory / "psalm.sarif").read_text(encoding="utf-8"))
    if not isinstance(sarif, dict) or sarif.get("version") != "2.1.0":
        raise ValueError("Psalm report is not SARIF 2.1.0")
    runs = sarif.get("runs")
    if not isinstance(runs, list) or not runs:
        raise ValueError("Psalm report contains no analysis run")
    for run in runs:
        if not isinstance(run, dict) or not isinstance(run.get("tool"), dict):
            raise ValueError("Psalm report contains an invalid run")
        results = run.get("results", [])
        if not isinstance(results, list) or results:
            raise ValueError("Psalm report contains analysis findings")

    return len(testcases)


def main() -> int:
    directory = Path(__file__).resolve().parents[2] / "var/test-results"
    try:
        count = validate_reports(directory)
    except (OSError, ValueError, ET.ParseError) as error:
        print(f"::error::Mandatory quality reports rejected: {error}", file=sys.stderr)
        return 1
    print(f"Quality reports accepted: {count} executed tests, no failures or skipped tests; Clover and Psalm SARIF present.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
