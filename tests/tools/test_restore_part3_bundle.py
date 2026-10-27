"""The delivered full-file bundle must survive a checkout that restores deleted files."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "restore-part3-bundle.py"
SPEC = importlib.util.spec_from_file_location("restore_part3_bundle", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
RESTORE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RESTORE)


class RestorePart3BundleTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.project = self.root / "project"
        self.project.mkdir()
        self.archive = self.root / "bundle.tar"
        self.manifest = self.root / "bundle.manifest.json"
        self.name = "app/example.php"
        self.content = b"<?php\ndeclare(strict_types=1);\nreturn 'complete file';\n"
        self.deleted = "app/Services/Lottery/CircuitBreaker.php"

    def build(
        self,
        members: dict[str, bytes] | None = None,
        deleted: list[str] | None = None,
        symlink: str | None = None,
    ) -> None:
        members = {self.name: self.content} if members is None else members
        with tarfile.open(self.archive, "w") as bundle:
            for name, content in members.items():
                info = tarfile.TarInfo(name)
                info.size = len(content)
                if name == symlink:
                    info.type = tarfile.SYMTYPE
                    info.linkname = "../../outside.php"
                    info.size = 0
                    bundle.addfile(info)
                else:
                    bundle.addfile(info, io.BytesIO(content))
        data = {
            "format_version": 1,
            "archive_sha256": hashlib.sha256(self.archive.read_bytes()).hexdigest(),
            "files": {name: hashlib.sha256(content).hexdigest() for name, content in members.items()},
            "deleted": [self.deleted] if deleted is None else deleted,
        }
        self.manifest.write_text(json.dumps(data), encoding="utf-8")

    def apply(self, restore: bool = False) -> dict:
        return RESTORE.apply_bundle(self.archive, self.manifest, self.project, restore=restore)

    def test_restore_writes_the_entire_file_and_applies_explicit_deletions(self) -> None:
        self.build()
        legacy = self.project / self.deleted
        legacy.parent.mkdir(parents=True)
        legacy.write_text("old dead breaker", encoding="utf-8")
        report = self.apply(restore=True)
        self.assertEqual(self.content, (self.project / self.name).read_bytes())
        self.assertFalse(legacy.exists())
        self.assertEqual(1, report["files_checked"])
        self.assertEqual(1, report["deleted_paths_checked"])
        self.assertTrue(self.apply()["verified"])

    def test_verify_only_does_not_repair_a_changed_file(self) -> None:
        self.build()
        self.apply(restore=True)
        target = self.project / self.name
        target.write_bytes(b"reverted content")
        with self.assertRaises(RESTORE.BundleError):
            self.apply()
        self.assertEqual(b"reverted content", target.read_bytes())

    def test_verify_detects_a_recreated_deleted_file(self) -> None:
        self.build()
        self.apply(restore=True)
        legacy = self.project / self.deleted
        legacy.parent.mkdir(parents=True)
        legacy.write_bytes(b"recreated by checkout")
        with self.assertRaises(RESTORE.BundleError):
            self.apply()
        self.assertTrue(legacy.exists())

    def test_tampered_archive_fails_before_writing_any_file(self) -> None:
        self.build()
        with self.archive.open("ab") as file:
            file.write(b"tampered")
        with self.assertRaisesRegex(RESTORE.BundleError, "Archive SHA-256"):
            self.apply(restore=True)
        self.assertEqual([], list(self.project.iterdir()))

    def test_parent_traversal_is_refused_even_with_a_matching_archive_hash(self) -> None:
        self.build({"../outside.php": self.content})
        with self.assertRaisesRegex(RESTORE.BundleError, "Unsafe relative path"):
            self.apply(restore=True)
        self.assertFalse((self.root / "outside.php").exists())

    def test_archive_symlinks_are_never_extracted(self) -> None:
        self.build(symlink=self.name)
        with self.assertRaisesRegex(RESTORE.BundleError, "non-file member"):
            self.apply(restore=True)
        self.assertEqual([], list(self.project.iterdir()))

    def test_a_symlink_in_the_destination_tree_is_refused(self) -> None:
        self.build()
        outside = self.root / "outside"
        outside.mkdir()
        (self.project / "app").symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(RESTORE.BundleError, "symlink destination"):
            self.apply(restore=True)
        self.assertEqual([], list(outside.iterdir()))

    def test_a_missing_member_is_refused_before_any_writes(self) -> None:
        self.build()
        data = json.loads(self.manifest.read_text(encoding="utf-8"))
        data["files"]["app/missing.php"] = hashlib.sha256(b"missing").hexdigest()
        self.manifest.write_text(json.dumps(data), encoding="utf-8")
        with self.assertRaisesRegex(RESTORE.BundleError, "exactly match"):
            self.apply(restore=True)
        self.assertEqual([], list(self.project.iterdir()))

    def test_a_file_used_as_a_parent_is_refused_before_other_members_are_written(self) -> None:
        self.build({"first.php": self.content, self.name: self.content})
        (self.project / "app").write_bytes(b"not a directory")
        with self.assertRaisesRegex(RESTORE.BundleError, "Expected a directory"):
            self.apply(restore=True)
        self.assertFalse((self.project / "first.php").exists())
        self.assertEqual(b"not a directory", (self.project / "app").read_bytes())

    def test_deployment_dotenv_can_never_be_overwritten_by_the_bundle(self) -> None:
        self.build({".env": b"APP_ENV=local\n"})
        with self.assertRaisesRegex(RESTORE.BundleError, "deployment environment"):
            self.apply(restore=True)
        self.assertFalse((self.project / ".env").exists())


if __name__ == "__main__":
    unittest.main()
