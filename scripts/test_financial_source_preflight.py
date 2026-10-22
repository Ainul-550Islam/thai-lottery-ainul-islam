#!/usr/bin/env python3
"""Standard-library unit tests for the offline preflight scanner."""
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from financial_source_preflight import audit, class_path, imports, migration_mentions, source_missing


class FinancialSourcePreflightTest(unittest.TestCase):
    def test_class_path_follows_app_psr4_mapping(self) -> None:
        self.assertEqual(class_path(Path('/repo'), 'App\\Models\\Wallet'), Path('/repo/app/Models/Wallet.php'))

    def test_rejects_external_namespace(self) -> None:
        with self.assertRaises(ValueError):
            class_path(Path('/repo'), 'Illuminate\\Http\\Request')

    def test_simple_and_aliased_imports(self) -> None:
        text = 'use App\\Models\\Wallet;\nuse App\\Models\\User as Member;\nuse Illuminate\\Http\\Request;'
        self.assertEqual(imports(text), {'App\\Models\\Wallet', 'App\\Models\\User'})

    def test_grouped_imports(self) -> None:
        text = 'use App\\Models\\{Wallet, User as Member};'
        self.assertEqual(imports(text), {'App\\Models\\Wallet', 'App\\Models\\User'})

    def test_missing_classes_include_all_referencing_files(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for relative in ('app/Caller.php', 'routes/api.php'):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('<?php\nuse App\\Models\\Absent;\n', encoding='utf-8')
            result = source_missing(root)
            self.assertEqual(len(result), 1)
            self.assertEqual(result[0]['referenced_by'], ['app/Caller.php', 'routes/api.php'])
            self.assertEqual(result[0]['expected_file'], 'app/Models/Absent.php')

    def test_existing_class_is_not_reported_missing(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'app/Models').mkdir(parents=True)
            (root / 'app/Models/Wallet.php').write_text('<?php\n', encoding='utf-8')
            (root / 'app/Caller.php').write_text('<?php\nuse App\\Models\\Wallet;\n', encoding='utf-8')
            self.assertEqual(source_missing(root), [])

    def test_namespace_alias_directory_is_not_a_missing_class(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'app/Filament/Resources/WalletResource/Pages').mkdir(parents=True)
            (root / 'app/Caller.php').write_text(
                '<?php\nuse App\\Filament\\Resources\\WalletResource\\Pages;\nPages\\ListWallets::class;\n', encoding='utf-8',
            )
            self.assertEqual(source_missing(root), [])

    def test_resource_directory_does_not_hide_missing_resource_class(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'app/Filament/Resources/WalletConversionResource/Pages').mkdir(parents=True)
            (root / 'app/Caller.php').write_text(
                '<?php\nuse App\\Filament\\Resources\\WalletConversionResource;\nWalletConversionResource::class;\n', encoding='utf-8',
            )
            result = source_missing(root)
            self.assertEqual(len(result), 1)
            self.assertEqual(result[0]['expected_file'], 'app/Filament/Resources/WalletConversionResource.php')

    def test_migration_table_declaration(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            migrations = root / 'database/migrations'
            migrations.mkdir(parents=True)
            (migrations / 'create.php').write_text("<?php Schema::create('wallet_conversions', fn () => null);", encoding='utf-8')
            self.assertEqual(migration_mentions(root, 'wallet_conversions'), ['database/migrations/create.php'])
            self.assertEqual(migration_mentions(root, 'wallet_conversion_quotes'), [])

    def test_audit_refuses_non_repository(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                audit(Path(directory))

    def test_incomplete_repository_fails_without_reading_env(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'composer.json').write_text('{}', encoding='utf-8')
            (root / '.env').write_text('SECRET=not-to-be-reported', encoding='utf-8')
            result = audit(root)
            self.assertFalse(result['source_preflight_passed'])
            self.assertFalse(result['runtime_verified'])
            self.assertNotIn('not-to-be-reported', str(result))
            self.assertFalse(result['provider_present'])


if __name__ == '__main__':
    unittest.main()
