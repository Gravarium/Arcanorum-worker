import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET

from check_reports import validate_reports


def junit_fixture():
    return ET.fromstring('<testsuites><testsuite name="Walker" tests="2" assertions="3" failures="0" errors="0" skipped="0"><testsuite name="Probe" tests="2" assertions="3" failures="0" errors="0" skipped="0"><testcase name="first" assertions="1"/><testcase name="second" assertions="2"/></testsuite></testsuite></testsuites>')


def coverage_fixture():
    return ET.fromstring('<coverage><project name="Clover Coverage"><package name="App"><file name="/synthetic/src/Probe.php"><line num="2" type="stmt" count="1"/><metrics loc="4" ncloc="4" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></file></package><metrics files="1" loc="4" ncloc="4" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></project></coverage>')


def sarif_fixture():
    return {"version": "2.1.0", "runs": [{"tool": {"driver": {"name": "Psalm", "version": "6.20.1@synthetic"}}, "results": []}]}


class ReportValidationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.write_xml("junit.xml", junit_fixture())
        self.write_xml("coverage.xml", coverage_fixture())
        self.write_sarif(sarif_fixture())

    def write_xml(self, name, root):
        ET.ElementTree(root).write(self.directory / name, encoding="utf-8", xml_declaration=True)

    def write_sarif(self, report):
        (self.directory / "psalm.sarif").write_text(json.dumps(report), encoding="utf-8")

    def assert_rejected(self, name, root):
        self.write_xml(name, root)
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_complete_reports_pass(self):
        self.assertEqual(2, validate_reports(self.directory))

    def test_single_suite_root_passes(self):
        root = junit_fixture().find("testsuite")
        self.write_xml("junit.xml", root)
        self.assertEqual(2, validate_reports(self.directory))

    def test_empty_suite_is_rejected(self):
        self.assert_rejected("junit.xml", ET.fromstring('<testsuites><testsuite tests="0" failures="0" errors="0" skipped="0"/></testsuites>'))

    def test_skipped_or_failed_tests_are_rejected(self):
        for tag in ("skipped", "failure", "error", "warning", "incomplete", "risky"):
            with self.subTest(tag=tag):
                root = junit_fixture()
                ET.SubElement(root.find(".//testcase"), tag)
                self.assert_rejected("junit.xml", root)

    def test_nonzero_suite_failures_are_rejected(self):
        for tag in ("testsuites", "testsuite"):
            for field in ("failures", "errors", "skipped", "disabled", "warnings"):
                with self.subTest(tag=tag, field=field):
                    root = junit_fixture()
                    target = root if tag == "testsuites" else root.find("testsuite")
                    target.set(field, "1")
                    self.assert_rejected("junit.xml", root)

    def test_junit_totals_must_match_on_every_level(self):
        for depth in (0, 1, 2):
            for total in ("0", "1", "999", "-1", "1.5", "NaN"):
                with self.subTest(depth=depth, total=total):
                    root = junit_fixture()
                    target = root
                    for _ in range(depth):
                        target = target.find("testsuite")
                    target.set("tests", total)
                    self.assert_rejected("junit.xml", root)

    def test_missing_suite_counters_are_rejected(self):
        for field in ("tests", "failures", "errors", "skipped"):
            with self.subTest(field=field):
                root = junit_fixture()
                del root.find("testsuite").attrib[field]
                self.assert_rejected("junit.xml", root)

    def test_invalid_failure_counters_are_rejected(self):
        for field in ("failures", "errors", "skipped"):
            for value in ("-1", "1.5", "none", ""):
                with self.subTest(field=field, value=value):
                    root = junit_fixture()
                    root.set(field, value)
                    self.assert_rejected("junit.xml", root)

    def test_unnamed_or_misplaced_cases_are_rejected(self):
        for name in (None, "", "   "):
            with self.subTest(name=name):
                root = junit_fixture()
                case = root.find(".//testcase")
                if name is None:
                    del case.attrib["name"]
                else:
                    case.set("name", name)
                self.assert_rejected("junit.xml", root)
        root = junit_fixture()
        ET.SubElement(root, "testcase", name="orphan")
        self.assert_rejected("junit.xml", root)
        root = junit_fixture()
        ET.SubElement(ET.SubElement(root, "properties"), "testcase", name="hidden")
        self.assert_rejected("junit.xml", root)

    def test_unknown_junit_root_is_rejected(self):
        self.assert_rejected("junit.xml", ET.fromstring("<not-a-test-report/>"))

    def test_empty_coverage_project_is_rejected(self):
        self.assert_rejected("coverage.xml", ET.fromstring("<coverage><project/></coverage>"))

    def test_missing_or_duplicate_projects_are_rejected(self):
        for xml in ("<coverage/>", "<coverage><project/><project/></coverage>", "<not-coverage><project/></not-coverage>"):
            with self.subTest(xml=xml):
                self.assert_rejected("coverage.xml", ET.fromstring(xml))

    def test_file_metrics_are_required(self):
        root = coverage_fixture()
        source = root.find(".//file")
        source.remove(source.find("metrics"))
        self.assert_rejected("coverage.xml", root)
        root = coverage_fixture()
        source = root.find(".//file")
        source.append(copy.deepcopy(source.find("metrics")))
        self.assert_rejected("coverage.xml", root)

    def test_missing_duplicate_or_unnamed_source_files_are_rejected(self):
        root = coverage_fixture()
        root.find(".//file").set("name", "")
        self.assert_rejected("coverage.xml", root)
        root = coverage_fixture()
        root.find(".//package").append(copy.deepcopy(root.find(".//file")))
        root.find("project/metrics").set("files", "2")
        self.assert_rejected("coverage.xml", root)

    def test_coverage_file_count_is_checked(self):
        for value in ("0", "2", "-1", "1.0", "NaN"):
            with self.subTest(value=value):
                root = coverage_fixture()
                root.find("project/metrics").set("files", value)
                self.assert_rejected("coverage.xml", root)

    def test_missing_or_inconsistent_project_metrics_are_rejected(self):
        root = coverage_fixture()
        root.find("project").remove(root.find("project/metrics"))
        self.assert_rejected("coverage.xml", root)
        for field in ("loc", "classes", "methods", "coveredmethods", "statements", "coveredstatements", "elements", "coveredelements"):
            with self.subTest(field=field):
                root = coverage_fixture()
                root.find("project/metrics").set(field, "99")
                self.assert_rejected("coverage.xml", root)

    def test_invalid_or_excessive_coverage_metrics_are_rejected(self):
        for field, value in (("loc", "-1"), ("ncloc", "99"), ("methods", "1.5"), ("statements", "NaN"), ("coveredmethods", "2"), ("coveredconditionals", "1"), ("coveredstatements", "2"), ("coveredelements", "3")):
            with self.subTest(field=field, value=value):
                root = coverage_fixture()
                root.find(".//file/metrics").set(field, value)
                self.assert_rejected("coverage.xml", root)

    def test_coverage_requires_no_percentage_threshold(self):
        root = coverage_fixture()
        for metrics in root.iter("metrics"):
            for name in ("coveredmethods", "coveredconditionals", "coveredstatements", "coveredelements"):
                metrics.set(name, "0")
        root.find(".//line").set("count", "0")
        self.write_xml("coverage.xml", root)
        self.assertEqual(2, validate_reports(self.directory))

    def test_source_files_without_executable_statements_are_valid(self):
        root = coverage_fixture()
        for metrics in root.iter("metrics"):
            for name in ("methods", "coveredmethods", "conditionals", "coveredconditionals", "statements", "coveredstatements", "elements", "coveredelements"):
                metrics.set(name, "0")
        source = root.find(".//file")
        source.remove(source.find("line"))
        self.write_xml("coverage.xml", root)
        self.assertEqual(2, validate_reports(self.directory))

    def test_invalid_line_metrics_are_rejected(self):
        for field, value in (("num", "0"), ("num", "-1"), ("count", "-1"), ("count", "1.5")):
            with self.subTest(field=field, value=value):
                root = coverage_fixture()
                root.find(".//line").set(field, value)
                self.assert_rejected("coverage.xml", root)

    def test_missing_report_is_rejected(self):
        for name in ("junit.xml", "coverage.xml", "psalm.sarif"):
            with self.subTest(name=name):
                path = self.directory / name
                content = path.read_bytes()
                path.unlink()
                with self.assertRaises(OSError):
                    validate_reports(self.directory)
                path.write_bytes(content)

    def test_malformed_or_namespaced_xml_is_rejected(self):
        for name in ("junit.xml", "coverage.xml"):
            original = (self.directory / name).read_bytes()
            for content in (b"<broken>", b"\xff", b'<testsuites xmlns="urn:unexpected"/>', b'<!DOCTYPE x [<!ENTITY token "private-regression-marker">]><testsuites/>'):
                with self.subTest(name=name, content=content):
                    (self.directory / name).write_bytes(content)
                    with self.assertRaises(ValueError):
                        validate_reports(self.directory)
            (self.directory / name).write_bytes(original)

    def test_empty_sarif_analysis_is_rejected(self):
        for report in ([], {}, {"version": "1.0", "runs": []}, {"version": "2.1.0", "runs": []}, {"version": "2.1.0", "runs": {}}):
            with self.subTest(report=report):
                self.write_sarif(report)
                with self.assertRaises(ValueError):
                    validate_reports(self.directory)

    def test_psalm_identity_and_version_are_required(self):
        for driver in ({}, {"name": "Other", "version": "1"}, {"name": "Psalm"}, {"name": "Psalm", "version": ""}, {"name": "Psalm", "version": 1}):
            with self.subTest(driver=driver):
                report = sarif_fixture()
                report["runs"][0]["tool"]["driver"] = driver
                self.write_sarif(report)
                with self.assertRaises(ValueError):
                    validate_reports(self.directory)

    def test_psalm_findings_are_rejected(self):
        report = sarif_fixture()
        report["runs"][0]["results"] = [{"level": "error", "message": {"text": "finding"}}]
        self.write_sarif(report)
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_missing_or_malformed_psalm_results_are_rejected(self):
        for value in (None, {}, "", True):
            with self.subTest(value=value):
                report = sarif_fixture()
                report["runs"][0]["results"] = value
                self.write_sarif(report)
                with self.assertRaises(ValueError):
                    validate_reports(self.directory)
        report = sarif_fixture()
        del report["runs"][0]["results"]
        self.write_sarif(report)
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_every_analysis_run_is_checked(self):
        report = sarif_fixture()
        report["runs"].append(copy.deepcopy(report["runs"][0]))
        self.write_sarif(report)
        self.assertEqual(2, validate_reports(self.directory))
        report["runs"][1]["results"] = [{"level": "error"}]
        self.write_sarif(report)
        with self.assertRaises(ValueError):
            validate_reports(self.directory)

    def test_successful_optional_invocations_are_accepted(self):
        report = sarif_fixture()
        report["runs"][0]["invocations"] = [{"executionSuccessful": True, "exitCode": 0, "toolExecutionNotifications": [{"level": "note"}]}]
        self.write_sarif(report)
        self.assertEqual(2, validate_reports(self.directory))

    def test_failed_or_ambiguous_invocations_are_rejected(self):
        for invocations in ([], {}, [None], [{}], [{"executionSuccessful": False}], [{"executionSuccessful": "true"}], [{"executionSuccessful": True, "exitCode": 1}], [{"executionSuccessful": True, "exitCode": False}]):
            with self.subTest(invocations=invocations):
                report = sarif_fixture()
                report["runs"][0]["invocations"] = invocations
                self.write_sarif(report)
                with self.assertRaises(ValueError):
                    validate_reports(self.directory)

    def test_analysis_failure_notifications_are_rejected(self):
        for key in ("toolExecutionNotifications", "toolConfigurationNotifications"):
            for notices in ({}, [None], [{}], [{"level": "error"}], [{"level": "warning"}], [{"level": []}], [{"level": "note", "exception": {"kind": "failed"}}], [{"level": "note", "exception": {}}], [{"level": "note", "exception": None}]):
                with self.subTest(key=key, notices=notices):
                    report = sarif_fixture()
                    report["runs"][0]["invocations"] = [{"executionSuccessful": True, key: notices}]
                    self.write_sarif(report)
                    with self.assertRaises(ValueError):
                        validate_reports(self.directory)

    def test_duplicate_keys_and_nonfinite_json_are_rejected(self):
        for content in ('{"version":"2.1.0","version":"2.1.0","runs":[]}', '{"version":"2.1.0","runs":[],"extra":NaN}', '{"version":"2.1.0","runs":[],"extra":Infinity}', '{"version":', 'private-regression-marker'):
            with self.subTest(content=content):
                (self.directory / "psalm.sarif").write_text(content)
                with self.assertRaises(ValueError):
                    validate_reports(self.directory)

    def test_cli_success_and_failure_are_explicit(self):
        command = [sys.executable, str(Path(__file__).with_name("check_reports.py")), "--directory", str(self.directory)]
        success = subprocess.run(command, capture_output=True, text=True, timeout=10)
        self.assertEqual(0, success.returncode, success.stderr)
        self.assertIn("2 executed tests", success.stdout)
        (self.directory / "psalm.sarif").write_text('private-regression-marker')
        failed = subprocess.run(command, capture_output=True, text=True, timeout=10)
        self.assertEqual(1, failed.returncode)
        self.assertIn("psalm.sarif", failed.stderr)
        self.assertNotIn("Traceback", failed.stderr)
        self.assertNotIn("private-regression-marker", failed.stdout + failed.stderr)
        (self.directory / "junit.xml").unlink()
        missing = subprocess.run(command, capture_output=True, text=True, timeout=10)
        self.assertEqual(1, missing.returncode)
        self.assertIn("junit.xml", missing.stderr)
        self.assertNotIn("Traceback", missing.stderr)


if __name__ == "__main__":
    unittest.main()
