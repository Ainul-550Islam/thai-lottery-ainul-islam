"""Unit tests for the CI's server-test JUnit coverage and no-skips gate."""

import importlib.util
import tempfile
import unittest
from pathlib import Path
from xml.etree import ElementTree


MODULE = Path(__file__).with_name('verify-server-test-census.py')
spec = importlib.util.spec_from_file_location('server_census', MODULE)
census = importlib.util.module_from_spec(spec)
spec.loader.exec_module(census)


class ServerTestCensusTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.tests = self.root / 'tests'
        self.tests.mkdir()
        self.a = self.tests / 'FirstServerTest.php'
        self.b = self.tests / 'Nested' / 'SecondServerTest.php'
        self.b.parent.mkdir()
        self.a.touch()
        self.b.touch()
        self.race = self.tests / 'Nested' / 'ThirdServerRaceTest.php'
        self.race.touch()
        self.report = self.root / 'junit.xml'

    def make_report(self, files, outcome=None):
        root = ElementTree.Element('testsuites')
        for file in files:
            suite = ElementTree.SubElement(root, 'testsuite', file=str(file))
            case = ElementTree.SubElement(suite, 'testcase', file=str(file), name='test_real')
            if outcome:
                ElementTree.SubElement(case, outcome)
        ElementTree.ElementTree(root).write(self.report, encoding='utf-8', xml_declaration=True)

    def test_accepts_all_files_with_executed_cases(self):
        self.make_report([self.a, self.b, self.race])
        self.assertEqual((3, 3), census.verify(self.tests, self.report))

    def test_rejects_a_missing_file(self):
        self.make_report([self.a])
        with self.assertRaisesRegex(ValueError, 'SecondServerTest'):
            census.verify(self.tests, self.report)

    def test_rejects_a_missing_race_file(self):
        self.make_report([self.a, self.b])
        with self.assertRaisesRegex(ValueError, 'ThirdServerRaceTest'):
            census.verify(self.tests, self.report)

    def test_rejects_skips_failures_and_errors(self):
        for outcome in ('skipped', 'failure', 'error'):
            with self.subTest(outcome=outcome):
                self.make_report([self.a, self.b, self.race], outcome)
                with self.assertRaisesRegex(ValueError, outcome):
                    census.verify(self.tests, self.report)

    def test_rejects_an_empty_report(self):
        self.make_report([])
        with self.assertRaisesRegex(ValueError, 'no test cases'):
            census.verify(self.tests, self.report)

    def test_rejects_an_empty_discovery(self):
        self.a.unlink()
        self.b.unlink()
        self.race.unlink()
        self.make_report([])
        with self.assertRaisesRegex(ValueError, 'No \\*ServerTest'):
            census.verify(self.tests, self.report)


if __name__ == '__main__':
    unittest.main()
