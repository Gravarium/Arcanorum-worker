#!/usr/bin/env python3
"""Reject missing, malformed, empty, failed, or skipped quality reports."""
from __future__ import annotations

import argparse
from pathlib import Path
import sys

from report_validation import read_sarif, read_xml, validate_clover, validate_junit, validate_psalm


def validate_reports(directory: Path) -> int:
    count = validate_junit(read_xml(directory / "junit.xml"))
    validate_clover(read_xml(directory / "coverage.xml"))
    validate_psalm(read_sarif(directory / "psalm.sarif"))
    return count


def main(arguments: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--directory", type=Path, default=Path(__file__).resolve().parents[2] / "var/test-results", help="Directory containing the mandatory JUnit, Clover and Psalm reports.")
    directory = parser.parse_args(arguments).directory
    try:
        count = validate_reports(directory)
    except (OSError, ValueError) as error:
        print(f"::error::Mandatory quality reports rejected: {error}", file=sys.stderr)
        return 1
    print(f"Quality reports accepted: {count} executed tests with consistent totals; nonempty Clover coverage and successful Psalm analysis.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
