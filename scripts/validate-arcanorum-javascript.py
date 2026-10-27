#!/usr/bin/env python3
from pathlib import Path
import re
import subprocess
import sys

def fail():
    print("JavaScript validation failed.", file=sys.stderr)
    raise SystemExit(1)

root = Path.cwd()
source_files = (
    "assets/video/video-quality-selector.js",
    "assets/admin/rich-text-editor.js",
    "assets/comments/emoji-picker.js",
    "assets/video/video-preroll.js",
    "assets/advertising/placement-formats.js",
    "assets/video/provider-embed-consent.js",
    "public/search/live-search-suggestions.js",
)
test_files = sorted((root / "tests/JavaScript").glob("*.test.mjs"))
if not test_files or any(not (root / path).is_file() for path in source_files):
    fail()

try:
    version = subprocess.run(
        ["node", "--version"], capture_output=True, text=True, check=False
    )
    if version.returncode != 0 or re.fullmatch(r"v24\.\d+\.\d+", version.stdout.strip()) is None:
        fail()

    for path in (*source_files, *(str(path.relative_to(root)) for path in test_files)):
        result = subprocess.run(
            ["node", "--check", path], cwd=root, capture_output=True, text=True, check=False
        )
        if result.returncode != 0:
            fail()

    result = subprocess.run(
        ["node", "--test", "--test-reporter=tap",
         *(str(path.relative_to(root)) for path in test_files)],
        cwd=root, capture_output=True, text=True, check=False
    )
except OSError:
    fail()

if result.returncode != 0:
    fail()

summaries = {}
for line in result.stdout.splitlines():
    match = re.fullmatch(r"# (tests|pass|fail|cancelled|skipped|todo) ([0-9]+)", line.strip())
    if match:
        summaries[match.group(1)] = int(match.group(2))

expected = {"tests", "pass", "fail", "cancelled", "skipped", "todo"}
if summaries.keys() != expected:
    fail()
if summaries["tests"] < 1 or summaries["pass"] != summaries["tests"]:
    fail()
if any(summaries[name] != 0 for name in ("fail", "cancelled", "skipped", "todo")):
    fail()

print("JavaScript validation passed.")
