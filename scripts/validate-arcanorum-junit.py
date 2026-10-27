#!/usr/bin/env python3
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

def fail(reason):
    print(f"PHPUnit report validation failed [{reason}].", file=sys.stderr)
    raise SystemExit(1)

if len(sys.argv) != 2:
    fail("expected_one_report_path")

report_path = Path(sys.argv[1])
if not report_path.is_file() or report_path.stat().st_size == 0:
    fail("missing_or_empty_report")

try:
    report = ET.parse(report_path).getroot()
except (ET.ParseError, OSError):
    fail("invalid_xml")

suites = [report] if report.tag == "testsuite" else report.findall(".//testsuite")
fields = (
    "tests", "failures", "errors", "warnings", "deprecations",
    "notices", "risky", "skipped", "incomplete",
)
if not suites:
    fail("no_testsuite_elements")

try:
    totals = {
        field: sum(int(suite.get(field, "0")) for suite in suites)
        for field in fields
    }
except (TypeError, ValueError):
    fail("invalid_numeric_counter")

if totals["tests"] < 1:
    fail("zero_tests")
issues = [field for field in fields if field != "tests" and totals[field] != 0]
if issues:
    fail("nonzero_counters:" + ",".join(issues))

print("PHPUnit report validation passed.")
