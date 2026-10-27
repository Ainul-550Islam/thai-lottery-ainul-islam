#!/usr/bin/env python3
"""Verify or restore complete Part 3 files and explicitly recorded deletions.

This does not install dependencies, change .env, migrate a database or start jobs.
All archive bytes, member names and destinations are validated before any write.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import tarfile
import tempfile
from typing import Any


class BundleError(RuntimeError):
    """The bundle or the destination does not satisfy its manifest."""


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def relative_name(value: Any) -> str:
    if not isinstance(value, str) or not value or "\\" in value:
        raise BundleError(f"Invalid relative path: {value!r}")
    path = PurePosixPath(value)
    if path.is_absolute() or ".." in path.parts or path.as_posix() != value:
        raise BundleError(f"Unsafe relative path: {value!r}")
    if value in {".", ".env"}:
        raise BundleError(f"Refusing a deployment environment or root path: {value!r}")
    return value


def destination(project: Path, name: str) -> Path:
    path = project.joinpath(*PurePosixPath(relative_name(name)).parts)
    current = project
    for component in path.relative_to(project).parts:
        current = current / component
        if current.is_symlink():
            raise BundleError(f"Refusing a symlink destination: {name}")
        if current != path and current.exists() and not current.is_dir():
            raise BundleError(f"Expected a directory in destination path: {name}")
    if not path.resolve().is_relative_to(project):
        raise BundleError(f"Destination escapes the project: {name}")
    if path.exists() and not path.is_file():
        raise BundleError(f"Expected a regular file destination: {name}")
    return path


def read_bundle(archive: Path, manifest_path: Path) -> tuple[dict[str, bytes], list[str]]:
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest.get("format_version") != 1:
        raise BundleError("Unsupported bundle manifest version.")
    if digest(archive.read_bytes()) != manifest.get("archive_sha256"):
        raise BundleError("Archive SHA-256 does not match the manifest.")
    expected = manifest.get("files")
    deleted = manifest.get("deleted")
    if not isinstance(expected, dict) or not expected or not isinstance(deleted, list):
        raise BundleError("Manifest must contain files and a deletion list.")
    for name, checksum in expected.items():
        relative_name(name)
        if not isinstance(checksum, str) or len(checksum) != 64:
            raise BundleError(f"Invalid SHA-256 for {name}")
    for name in deleted:
        relative_name(name)
        if name in expected:
            raise BundleError(f"A file cannot be restored and deleted: {name}")
    payloads: dict[str, bytes] = {}
    with tarfile.open(archive, "r:*") as bundle:
        for member in bundle.getmembers():
            name = relative_name(member.name)
            if not member.isfile() or name in payloads or name not in expected:
                raise BundleError(f"Unexpected, duplicate or non-file member: {name}")
            stream = bundle.extractfile(member)
            if stream is None:
                raise BundleError(f"Cannot read archive member: {name}")
            with stream:
                content = stream.read()
            if digest(content) != expected[name]:
                raise BundleError(f"Member SHA-256 mismatch: {name}")
            payloads[name] = content
    if set(payloads) != set(expected):
        raise BundleError("Archive members do not exactly match the manifest.")
    return payloads, deleted


def apply_bundle(
    archive: Path,
    manifest_path: Path,
    project: Path,
    *,
    restore: bool = False,
) -> dict[str, Any]:
    project = project.resolve()
    if not project.is_dir():
        raise BundleError("The destination must be an existing project directory.")
    payloads, deleted = read_bundle(archive, manifest_path)
    destinations = {name: destination(project, name) for name in payloads}
    deletions = {name: destination(project, name) for name in deleted}

    if restore:
        for name, content in payloads.items():
            path = destinations[name]
            path.parent.mkdir(parents=True, exist_ok=True)
            temporary: str | None = None
            try:
                with tempfile.NamedTemporaryFile(dir=path.parent, prefix=".part3-", delete=False) as file:
                    temporary = file.name
                    file.write(content)
                    file.flush()
                    os.fsync(file.fileno())
                os.chmod(temporary, 0o644)
                os.replace(temporary, path)
            finally:
                if temporary is not None:
                    Path(temporary).unlink(missing_ok=True)
        for path in deletions.values():
            path.unlink(missing_ok=True)

    mismatches = [
        name
        for name, content in payloads.items()
        if not destinations[name].is_file() or destinations[name].read_bytes() != content
    ]
    recreated = [name for name, path in deletions.items() if path.exists()]
    if mismatches or recreated:
        raise BundleError(json.dumps({"changed_or_missing": mismatches, "must_be_absent": recreated}))
    return {
        "verified": True,
        "restored": restore,
        "files_checked": len(payloads),
        "deleted_paths_checked": len(deleted),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["verify", "restore"])
    parser.add_argument("--archive", required=True, type=Path)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--project", required=True, type=Path)
    arguments = parser.parse_args()
    try:
        report = apply_bundle(
            arguments.archive,
            arguments.manifest,
            arguments.project,
            restore=arguments.action == "restore",
        )
    except (BundleError, OSError, ValueError, tarfile.TarError) as error:
        print(json.dumps({"verified": False, "error": str(error)}, ensure_ascii=False))
        return 1
    print(json.dumps(report, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
