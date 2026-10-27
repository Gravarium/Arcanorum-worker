#!/usr/bin/env python3
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

def fail():
    print("PHPUnit report validation failed.", file=sys.stderr)
    raise SystemExit(1)

if len(sys.argv) != 2:
    fail()

report_path = Path(sys.argv[1])
if not report_path.is_file() or report_path.stat().st_size == 0:
    fail()

try:
    report = ET.parse(report_path).getroot()
except (ET.ParseError, OSError):
    fail()

suites = [report] if report.tag == "testsuite" else report.findall(".//testsuite")
fields = (
    "tests", "failures", "errors", "warnings", "deprecations",
    "notices", "risky", "skipped", "incomplete",
)
if not suites:
    fail()

try:
    totals = {
        field: sum(int(suite.get(field, "0")) for suite in suites)
        for field in fields
    }
except (TypeError, ValueError):
    fail()

if totals["tests"] < 1:
    fail()
if any(totals[field] != 0 for field in fields if field != "tests"):
    fail()

print("PHPUnit report validation passed.")

