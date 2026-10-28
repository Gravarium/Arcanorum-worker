#!/usr/bin/env python3
import os
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

def has_application_javascript():
    ignored_directories = {".git", "node_modules", "var", "vendor"}
    javascript_suffixes = {".cjs", ".js", ".mjs"}

    for directory, subdirectories, filenames in os.walk(root):
        relative_directory = Path(directory).relative_to(root)
        if relative_directory.parts[:2] == ("public", "assets"):
            subdirectories.clear()
            continue

        subdirectories[:] = [
            name for name in subdirectories if name not in ignored_directories
        ]
        if any(Path(filename).suffix.lower() in javascript_suffixes for filename in filenames):
            return True

    return False

if not has_application_javascript() and not test_files:
    print("JavaScript validation not applicable: no JavaScript source or tests.")
    raise SystemExit(2)

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
