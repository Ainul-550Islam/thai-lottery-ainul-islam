"""Offline guards: no credentials, Laravel bootstrap, database or subprocess probes."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('inventory', Path(__file__).with_name('release_source_inventory.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def recipe():
    return 'pages = ' + repr({page: ('Declared title ' + str(page), 'not-an-attestation') for page in range(251, 351)})


class ReleaseSourceInventoryTest(unittest.TestCase):
    def prepare(self, root):
        for name in module.SOURCES:
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(recipe() if name.endswith('pages_251_350_audit_matrix.py') else 'source fixture\n')

    def test_recipe_is_parsed_without_executing_module_code(self):
        text = recipe() + "\nraise RuntimeError('must never execute')\n"
        self.assertEqual(list(range(251, 351)), sorted(module.declared_recipes(text)))

    def test_missing_recipe_slot_is_refused(self):
        with self.assertRaises(ValueError):
            module.declared_recipes('pages = {251: ("one",)}')

    def test_duplicate_literal_slot_is_refused(self):
        with self.assertRaises(ValueError):
            module.declared_recipes(recipe() + '\nruntime_row(251, "duplicate")')

    def test_executable_dictionary_value_is_not_evaluated(self):
        with self.assertRaises(ValueError):
            module.declared_recipes('pages = dict()')

    def test_hashes_are_deterministic_and_attestation_flags_remain_false(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.prepare(root)
            one = module.build_inventory(root)
            self.assertEqual(one, module.build_inventory(root))
            for flag in ['runtime_verified', 'production_verified', 'live_payment_verified', 'official_source_verified', 'page_mapping_verified']:
                self.assertFalse(one[flag])
            self.assertEqual(list(range(77, 351)), [row['page'] for row in one['pages']])
            self.assertEqual('NOT_CONFIGURED', one['pages'][0]['mapping_status'])
            self.assertEqual('DECLARED_RECIPE_ONLY', one['pages'][-1]['mapping_status'])
            self.assertTrue(all(not row['route_mapping_verified'] and not row['financial_execution_verified'] for row in one['pages']))

    def test_changed_source_changes_hash_and_missing_source_is_explicit(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.prepare(root)
            before = module.build_inventory(root)
            first = root / module.SOURCES[0]
            first.write_text('changed source\n')
            after = module.build_inventory(root)
            self.assertNotEqual(before['sources'][0]['sha256'], after['sources'][0]['sha256'])
            first.unlink()
            missing = module.build_inventory(root)['sources'][0]
            self.assertFalse(missing['exists'])
            self.assertIsNone(missing['sha256'])

    def test_env_and_subprocesses_are_not_read_or_executed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.prepare(root)
            (root / '.env').write_text('PRIVATE_SENTINEL=never-read-me')
            original = Path.read_text
            def guarded(path, *args, **kwargs):
                if path.name == '.env':
                    raise AssertionError('Forbidden credential read')
                return original(path, *args, **kwargs)
            with patch.object(Path, 'read_text', guarded), patch('subprocess.run', side_effect=AssertionError('No runtime command')):
                inventory = module.build_inventory(root)
            self.assertNotIn('never-read-me', json.dumps(inventory))

    def test_traversal_and_symlink_sources_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(ValueError):
                module.safe_path(root, '../outside')
            (root / 'link').symlink_to(root / 'inside')
            with self.assertRaises(ValueError):
                module.safe_path(root, 'link')

    def test_generated_documents_use_only_allowed_outputs_and_do_not_claim_runtime(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.prepare(root)
            outputs = module.documents(module.build_inventory(root))
            self.assertEqual(set(module.OUTPUTS), set(outputs))
            self.assertIn('STATIC_ONLY', outputs['audit.md'])
            self.assertIn('not entirely read-only', outputs['audit.md'])
            self.assertIn('rust_runtime_verified=false', outputs['RUST-RUNTIME-REPORT.md'])
            self.assertIn('live_payment_verified=false', outputs['FINANCIAL-INTEGRITY-REPORT.md'])


if __name__ == '__main__':
    unittest.main()
