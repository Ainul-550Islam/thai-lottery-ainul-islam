#!/usr/bin/env python3
"""Unit tests of the census, using explicitly synthetic JUnit, not real pass evidence."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('lane', Path(__file__).with_name('run_php_test_lane.py'))
lane = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lane)


class CensusTest(unittest.TestCase):
    def write(self, path, name, status=''):
        case = f'<testcase class="Tests\\Example" name="{name}" assertions="1">{status}</testcase>'
        path.write_text('<testsuites><testsuite>'+case+'</testsuite></testsuites>')

    def test_exact_named_and_numbered_data_sets_are_checked(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / 'j.xml'
            self.write(report, 'test_demo with data set &quot;real rail&quot;')
            self.assertEqual(1, lane.check_junit(report, ['Tests\\Example::test_demo#real rail'])['cases'])
            with self.assertRaises(ValueError):
                lane.check_junit(report, ['Tests\\Example::test_demo#other rail'])
            self.write(report, 'test_demo with data set #2')
            self.assertEqual(1, lane.check_junit(report, ['Tests\\Example::test_demo#2'])['cases'])

    def test_skips_fail_the_census_even_with_a_matching_identifier(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / 'j.xml'
            self.write(report, 'test_demo', '<skipped/>')
            with self.assertRaises(ValueError):
                lane.check_junit(report, ['Tests\\Example::test_demo'])

    def test_duplicate_and_unexpected_results_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / 'j.xml'
            report.write_text('<testsuites><testsuite>' +
                              '<testcase class="Tests\\Example" name="test_demo"/>' * 2 +
                              '</testsuite></testsuites>')
            with self.assertRaises(ValueError):
                lane.check_junit(report, ['Tests\\Example::test_demo'])

    def test_reconciliation_requires_both_jobs_and_every_case(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); a=root/'sqlite'; b=root/'mysql'; a.mkdir();b.mkdir()
            plan = {'commit':'synthetic', 'total_discovered':2,
                    'lanes':{k:[] for k in lane.LANES}, 'files':{k:{} for k in lane.LANES}}
            plan['lanes']['sqlite']=['Tests\\Example::test_a']
            plan['files']['sqlite']={'a.php':['Tests\\Example::test_a']}
            plan['lanes']['server']=['Tests\\Example::test_b']
            plan['files']['server']={'b.php':['Tests\\Example::test_b']}
            for path in [a,b]: (path/'plan.json').write_text(json.dumps(plan))
            self.write(a/'a.xml','test_a'); self.write(b/'b.xml','test_b')
            (a/'summary.json').write_text(json.dumps([{'exit':0,'lane':'sqlite','files':['a.php'],'junit':'a.xml'}]))
            (b/'summary.json').write_text(json.dumps([{'exit':0,'lane':'server','files':['b.php'],'junit':'b.xml'}]))
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(0,lane.reconcile(root,a,b))
            (b/'summary.json').write_text('[]')
            with self.assertRaises(ValueError): lane.reconcile(root,a,b)
            self.write(b/'b.xml','test_b','<failure/>')
            (b/'summary.json').write_text(json.dumps([{'exit':0,'lane':'server','files':['b.php'],'junit':'b.xml'}]))
            with self.assertRaises(ValueError): lane.reconcile(root,a,b)


if __name__ == '__main__':
    unittest.main(verbosity=2)
