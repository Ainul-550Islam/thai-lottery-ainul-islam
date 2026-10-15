#!/usr/bin/env python3
"""Safety/parse controls for the clean local reproduction runner, no app commands."""
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest import mock

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/reproduce-local-regression.py'
spec = importlib.util.spec_from_file_location('local_reproduction', SCRIPT)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ReproductionSafetyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='local-repro-test-')
        self.root = Path(self.temp.name)
        self.source = self.root / 'source'
        self.source.mkdir()

    def tearDown(self):
        self.temp.cleanup()

    def archive(self, entries):
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode='w') as archive:
            for name, kind, content in entries:
                item = tarfile.TarInfo(name)
                item.mode = 0o644
                if kind == 'link':
                    item.type = tarfile.SYMTYPE
                    item.linkname = content
                    archive.addfile(item)
                else:
                    data = content.encode()
                    item.size = len(data)
                    archive.addfile(item, io.BytesIO(data))
        return buffer.getvalue()

    def environment(self):
        return ('APP_ENV=testing\nAPP_KEY=\nDB_CONNECTION=sqlite\n'
                'DB_DATABASE=database/thai_lottery_test\nQUEUE_CONNECTION=sync\n'
                'PRIZE_PAYOUT_SAFETY_MODE=DISABLED\nLOTTERY_AUTOMATION_ENABLED=false\n'
                'LOTTERY_AUTO_PAYOUT=false\nLOTTERY_AUTO_SETTLE=false\n'
                'STRIPE_ENABLED=false\nBKASH_ENABLED=false\nNAGAD_ENABLED=false\n'
                'CRYPTO_ENABLED=false\nBANK_TRANSFER_ENABLED=false\nPROMPTPAY_ENABLED=false\n')

    def project(self):
        project = self.root / 'project'
        project.mkdir()
        (project / 'database').mkdir()
        (project / '.env.ci').write_text(self.environment())
        return project

    def test_new_workspace_never_reuses_an_existing_directory(self):
        destination = self.root / 'already-exists'
        destination.mkdir()
        (destination / 'keep').write_text('preserve')
        with self.assertRaises(runner.ReproductionError):
            runner.new_workspace(destination, self.source)
        self.assertEqual('preserve', (destination / 'keep').read_text())

    def test_workspace_must_not_be_inside_source_or_be_its_ancestor(self):
        with self.assertRaises(runner.ReproductionError):
            runner.new_workspace(self.source / 'child', self.source)
        with self.assertRaises(runner.ReproductionError):
            runner.new_workspace(self.source, self.source)

    def test_symlink_ancestry_is_refused(self):
        outside = self.root / 'outside'
        outside.mkdir()
        link = self.root / 'link'
        link.symlink_to(outside, target_is_directory=True)
        with self.assertRaises(runner.ReproductionError):
            runner.new_workspace(link / 'child', self.source)
        self.assertFalse((outside / 'child').exists())

    def test_valid_base_archive_writes_complete_files(self):
        project = self.root / 'new-base'
        project.mkdir()
        payload = self.archive([('app/full.php', 'file', '<?php\n// complete\n')])
        runner.extract_git_base(payload, project)
        self.assertEqual('<?php\n// complete\n', (project / 'app/full.php').read_text())

    def test_traversal_is_refused_before_any_valid_member_is_written(self):
        project = self.root / 'bad-base'
        project.mkdir()
        payload = self.archive([('app/good.php', 'file', 'good'), ('../escape', 'file', 'bad')])
        with self.assertRaises(runner.ReproductionError):
            runner.extract_git_base(payload, project)
        self.assertEqual([], list(project.iterdir()))
        self.assertFalse((self.root / 'escape').exists())

    def test_symlink_and_duplicate_base_members_are_refused(self):
        for entries in [[('app/link', 'link', '/outside')], [('a', 'file', 'first'), ('a', 'file', 'second')]]:
            with self.subTest(entries=entries):
                with self.assertRaises(runner.ReproductionError):
                    runner.extract_git_base(self.archive(entries), self.root)

    def test_git_base_cannot_carry_environment_or_generated_dependency_state(self):
        for path in ['.env', 'nested/.env', '.git/config', 'vendor/autoload.php', 'node_modules/a.js', 'public/build/manifest.json']:
            with self.subTest(path=path):
                with self.assertRaises(runner.ReproductionError):
                    runner.extract_git_base(self.archive([(path, 'file', 'PRIVATE')]), self.root)

    def test_existing_dotenv_is_never_overwritten(self):
        project = self.project()
        (project / '.env').write_text('PRIVATE-deployment-value')
        with self.assertRaises(runner.ReproductionError):
            runner.safe_ci_environment(project)
        self.assertEqual('PRIVATE-deployment-value', (project / '.env').read_text())

    def test_isolated_ci_env_and_database_are_fresh(self):
        project = self.project()
        runner.safe_ci_environment(project)
        self.assertEqual(self.environment(), (project / '.env').read_text())
        self.assertEqual(b'', (project / 'database/thai_lottery_test').read_bytes())
        self.assertEqual(0o600, (project / '.env').stat().st_mode & 0o777)

    def test_production_or_enabled_provider_templates_are_not_used(self):
        for old, new in [('APP_ENV=testing', 'APP_ENV=production'),
                         ('DB_CONNECTION=sqlite', 'DB_CONNECTION=mysql'),
                         ('LOTTERY_AUTO_SETTLE=false', 'LOTTERY_AUTO_SETTLE=true'),
                         ('BKASH_ENABLED=false', 'BKASH_ENABLED=true'),
                         ('APP_KEY=', 'APP_KEY=PRIVATE')]:
            with self.subTest(new=new):
                project = self.root / ('env-case-' + str(len(list(self.root.iterdir()))))
                project.mkdir()
                (project / 'database').mkdir()
                (project / '.env.ci').write_text(self.environment().replace(old, new))
                with self.assertRaises(runner.ReproductionError):
                    runner.safe_ci_environment(project)
                self.assertFalse((project / '.env').exists())

    def test_existing_database_cannot_be_reused(self):
        project = self.project()
        (project / 'database/thai_lottery_test').write_text('old-state')
        with self.assertRaises(runner.ReproductionError):
            runner.safe_ci_environment(project)
        self.assertEqual('old-state', (project / 'database/thai_lottery_test').read_text())

    def test_parent_production_values_are_removed_from_child_environment(self):
        with mock.patch.dict(os.environ, {'APP_ENV': 'production', 'DB_URL': 'PRIVATE', 'BKASH_SECRET': 'PRIVATE',
                                        'QUEUE_CONNECTION': 'redis', 'RISK_MAX_STAKE_PER_NUMBER': 'PRIVATE',
                                        'PATH': '/known/runtime'}, clear=True):
            clean = runner.process_environment(self.root)
        self.assertEqual('testing', clean['APP_ENV'])
        self.assertEqual('', clean['DB_URL'])
        self.assertEqual('sqlite', clean['DB_CONNECTION'])
        self.assertEqual('sync', clean['QUEUE_CONNECTION'])
        self.assertEqual('DISABLED', clean['PRIZE_PAYOUT_SAFETY_MODE'])
        self.assertNotIn('BKASH_SECRET', clean)
        self.assertNotIn('RISK_MAX_STAKE_PER_NUMBER', clean)
        self.assertEqual('/known/runtime', clean['PATH'])

    def test_junit_green_requires_tests_and_no_errors_failures_or_skips(self):
        path = self.root / 'junit.xml'
        path.write_text('<testsuites><testsuite tests="3" assertions="9" errors="0" failures="0" skipped="0" /></testsuites>')
        self.assertEqual({'tests': 3, 'assertions': 9, 'errors': 0, 'failures': 0, 'skipped': 0}, runner.junit_totals(path))
        for changed in ['tests="0"', 'errors="1"', 'failures="1"', 'skipped="1"']:
            field = changed.split('=')[0]
            base = '<testsuites><testsuite tests="3" assertions="9" errors="0" failures="0" skipped="0" /></testsuites>'
            import re
            path.write_text(re.sub(field + r'="[0-9]+"', changed, base))
            with self.assertRaises(runner.ReproductionError):
                runner.junit_totals(path)

    def test_archive_checksum_or_restorer_mismatch_is_refused(self):
        archive = self.root / 'bundle.tar'
        archive.write_bytes(b'corrupt')
        manifest = self.root / 'manifest.json'
        manifest.write_text(json.dumps({'format_version': 1, 'files': {}, 'archive_sha256': 'wrong'}))
        with self.assertRaises(runner.ReproductionError):
            runner.checked_inputs(self.source, archive, manifest)
        manifest.write_text(json.dumps({'format_version': 1, 'files': {}, 'archive_sha256': runner.sha256(archive)}))
        with self.assertRaises(runner.ReproductionError):
            runner.checked_inputs(self.source, archive, manifest)


if __name__ == '__main__':
    unittest.main()
