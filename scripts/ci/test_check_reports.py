import json
from pathlib import Path
import tempfile
import unittest

from check_reports import validate_reports


class ReportValidationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        (self.directory / "junit.xml").write_text('<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0"><testcase name="real-test"/></testsuite></testsuites>')
        (self.directory / "coverage.xml").write_text('<coverage><project/></coverage>')
        (self.directory / "psalm.sarif").write_text(json.dumps({"version": "2.1.0", "runs": [{"tool": {"driver": {"name": "Psalm"}}, "results": []}]}))

    def test_complete_reports_pass(self):
        self.assertEqual(1, validate_reports(self.directory))

    def test_empty_suite_is_rejected(self):
        (self.directory / "junit.xml").write_text('<testsuites><testsuite tests="0"/></testsuites>')
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_skipped_or_failed_tests_are_rejected(self):
        for tag in ("skipped", "failure", "error"):
            with self.subTest(tag=tag):
                (self.directory / "junit.xml").write_text(f'<testsuites><testsuite><testcase><{tag}/></testcase></testsuite></testsuites>')
                with self.assertRaises(ValueError):
                    validate_reports(self.directory)

    def test_nonzero_suite_failures_are_rejected(self):
        (self.directory / "junit.xml").write_text('<testsuites><testsuite failures="1"><testcase/></testsuite></testsuites>')
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_missing_report_is_rejected(self):
        (self.directory / "coverage.xml").unlink()
        with self.assertRaises(OSError):
            validate_reports(self.directory)

    def test_empty_sarif_analysis_is_rejected(self):
        (self.directory / "psalm.sarif").write_text('{"version":"2.1.0","runs":[]}')
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_psalm_findings_are_rejected(self):
        (self.directory / "psalm.sarif").write_text(json.dumps({"version": "2.1.0", "runs": [{"tool": {}, "results": [{"message": {"text": "Finding"}}]}]}))
        with self.assertRaises(ValueError):
            validate_reports(self.directory)


if __name__ == "__main__":
    unittest.main()
