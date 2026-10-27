"""Exercise the read-only PHP CLI guard in isolated temp trees, never Laravel/DB."""
import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class CiRuntimeGuardCliTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='lottery-ci-guard-')
        self.root = Path(self.temp.name)
        (self.root / 'scripts').mkdir()
        (self.root / 'database').mkdir()
        (self.root / 'bootstrap' / 'cache').mkdir(parents=True)
        # Reuse only Composer autoload/classes; no application boot or models.
        (self.root / 'vendor').symlink_to(ROOT / 'vendor', target_is_directory=True)
        shutil.copyfile(ROOT / 'scripts' / 'ci_runtime_guard.php', self.root / 'scripts' / 'ci_runtime_guard.php')
        self.values = {}
        for line in (ROOT / '.env.ci').read_text().splitlines():
            if line and not line.startswith('#') and '=' in line:
                name, value = line.split('=', 1)
                self.values[name] = value.strip('"')
        self.values['APP_KEY'] = 'base64:' + base64.b64encode(b'x' * 32).decode()
        self.write_env()
        self.sentinel = self.root / 'database' / 'thai_lottery_test'
        self.sentinel.write_text('guard must not read/migrate this file')

    def tearDown(self):
        self.assertEqual('guard must not read/migrate this file', self.sentinel.read_text())
        self.temp.cleanup()

    def write_env(self):
        (self.root / '.env').write_text(''.join(name + '="' + value + '"\n' for name, value in self.values.items()))

    def run_guard(self, args=(), overrides=None):
        env = os.environ.copy()
        # Prevent the sandbox's own unrelated env from influencing fixtures.
        for key in set(self.values) | {'DB_USERNAME', 'DB_HOST', 'DB_PORT'}:
            env.pop(key, None)
        env['CI'] = 'true'
        env.update(overrides or {})
        return subprocess.run(['php', str(self.root / 'scripts' / 'ci_runtime_guard.php'), *args],
                              env=env, text=True, capture_output=True, timeout=15)

    def cache(self, database='database/thai_lottery_test'):
        data = {
            'app': {'env': 'testing', 'debug': False, 'key': self.values['APP_KEY']},
            'database': {'default': 'sqlite', 'connections': {'sqlite': {'database': database}}},
            'glo': {'official_source': {'mode': 'official'}, 'sources': {'priority': ['official'], 'fall_through_to_fixture': False}},
            'finance': {'prize_payout': {'safety_mode': 'DISABLED'}},
            'lottery': {'payouts': {'auto_process': False}},
            'payment': {'gateways': {name: {'enabled': False} for name in ['stripe', 'bkash', 'nagad', 'crypto', 'promptpay', 'bank_transfer']}},
        }
        for lane in ['national', 'weekly', 'bingo', 'pcso']:
            data[lane + '_lottery'] = {'sources': {'fixture': {'enabled': False}}}
        text = json.dumps(data).replace('\\', '\\\\').replace("'", "\\'")
        (self.root / 'bootstrap' / 'cache' / 'config.php').write_text("<?php return json_decode('" + text + "', true);\n")

    def test_valid_uncached_and_cached_configuration_is_read_only(self):
        self.assertEqual(0, self.run_guard().returncode)
        self.cache()
        self.assertEqual(0, self.run_guard().returncode)

    def test_before_key_mode_is_bounded_and_rejects_existing_cache(self):
        self.values['APP_KEY'] = ''
        self.write_env()
        self.assertNotEqual(0, self.run_guard().returncode)
        self.assertEqual(0, self.run_guard(['--before-key']).returncode)
        self.cache()
        self.assertNotEqual(0, self.run_guard(['--before-key']).returncode)

    def test_process_database_override_cannot_retarget_the_template(self):
        result = self.run_guard(overrides={'DB_DATABASE': '/customer/never-touch-me'})
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn('never-touch-me', result.stderr)

    def test_cached_production_target_is_refused_before_framework_boot(self):
        self.cache(database='/customer/never-touch-me')
        result = self.run_guard()
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn('never-touch-me', result.stderr)

    def test_ci_marker_and_unknown_arguments_are_refused(self):
        self.assertNotEqual(0, self.run_guard(overrides={'CI': 'false'}).returncode)
        self.assertNotEqual(0, self.run_guard(['--ignore-safety']).returncode)

    def test_template_and_cli_do_not_disclose_the_ephemeral_key(self):
        self.values['STRIPE_ENABLED'] = 'true'
        self.write_env()
        result = self.run_guard()
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn(self.values['APP_KEY'], result.stderr + result.stdout)


if __name__ == '__main__':
    unittest.main()
