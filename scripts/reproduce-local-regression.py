#!/usr/bin/env python3
"""Rebuild/test a verified source bundle in a NEW disposable git-base workspace.

Never restore over the source, reuse a database/.env or invoke application money
commands. Dependency installation can access registries; all app tests use .env.ci.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import sys
import tarfile
import xml.etree.ElementTree as ET


class ReproductionError(RuntimeError):
    pass


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def run(command: list[str], cwd: Path, environment: dict[str, str], log: Path) -> None:
    with log.open("w", encoding="utf-8") as output:
        result = subprocess.run(command, cwd=cwd, env=environment, stdin=subprocess.DEVNULL,
                                stdout=output, stderr=subprocess.STDOUT, check=False)
    if result.returncode != 0:
        raise ReproductionError(f"Step failed with exit {result.returncode}; inspect {log.name}.")


def checked_inputs(source: Path, archive: Path, manifest: Path) -> dict:
    data = json.loads(manifest.read_text(encoding="utf-8"))
    if data.get("format_version") != 1 or not isinstance(data.get("files"), dict):
        raise ReproductionError("Unsupported bundle manifest.")
    if sha256(archive) != data.get("archive_sha256"):
        raise ReproductionError("Bundle archive checksum mismatch.")
    restorer = source / "scripts/restore-part3-bundle.py"
    if not restorer.is_file() or restorer.is_symlink() or sha256(restorer) != data["files"].get("scripts/restore-part3-bundle.py"):
        raise ReproductionError("The standalone restorer must match the verified manifest.")
    base = data.get("base_commit")
    if not isinstance(base, str) or len(base) != 40 or any(c not in "0123456789abcdef" for c in base):
        raise ReproductionError("An exact base commit is required.")
    result = subprocess.run(["git", "cat-file", "-t", base], cwd=source, stdin=subprocess.DEVNULL,
                            capture_output=True, text=True, check=False)
    if result.returncode != 0 or result.stdout.strip() != "commit":
        raise ReproductionError("The declared base commit is not available in the source repository.")
    return data


def new_workspace(path: Path, source: Path) -> Path:
    if path.exists() or path.is_symlink():
        raise ReproductionError("Reproduction destination must not exist; no reuse or cleanup is implicit.")
    for parent in path.parents:
        if parent.is_symlink():
            raise ReproductionError("Destination ancestry may not contain a symlink.")
    resolved = path.resolve()
    original = source.resolve()
    if resolved == original or original in resolved.parents or resolved in original.parents:
        raise ReproductionError("Reproduction workspace must be separate from source and its ancestors.")
    resolved.mkdir(parents=True, mode=0o700)
    return resolved


def extract_git_base(payload: bytes, project: Path) -> None:
    with tarfile.open(fileobj=io.BytesIO(payload), mode="r:") as base:
        members = base.getmembers()
        names: set[str] = set()
        for item in members:
            path = PurePosixPath(item.name)
            if path.is_absolute() or ".." in path.parts or item.name in names:
                raise ReproductionError("Unsafe/duplicate git archive member.")
            names.add(item.name)
            if not (item.isfile() or item.isdir()) or any(part == ".git" for part in path.parts):
                raise ReproductionError("Git base links/special members are not permitted.")
            if path.name == ".env" or item.name.startswith(("vendor/", "node_modules/", "public/build/")):
                raise ReproductionError("Git base must not carry environment/dependency/build state.")
        for item in members:
            destination = project.joinpath(*PurePosixPath(item.name).parts)
            if item.isdir():
                destination.mkdir(parents=True, exist_ok=True)
            else:
                destination.parent.mkdir(parents=True, exist_ok=True)
                reader = base.extractfile(item)
                if reader is None:
                    raise ReproductionError("Git member cannot be read.")
                destination.write_bytes(reader.read())
                destination.chmod(item.mode & 0o777)


def safe_ci_environment(project: Path) -> None:
    destination = project / ".env"
    template = project / ".env.ci"
    if destination.exists() or destination.is_symlink() or not template.is_file() or template.is_symlink():
        raise ReproductionError("Only a fresh workspace without .env may receive the isolated CI template.")
    values: dict[str, str] = {}
    for line in template.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            values[key] = value.strip().strip('"')
    required = {"APP_ENV": "testing", "DB_CONNECTION": "sqlite",
                "DB_DATABASE": "database/thai_lottery_test", "QUEUE_CONNECTION": "sync",
                "PRIZE_PAYOUT_SAFETY_MODE": "DISABLED", "LOTTERY_AUTOMATION_ENABLED": "false",
                "LOTTERY_AUTO_PAYOUT": "false", "LOTTERY_AUTO_SETTLE": "false"}
    if any(values.get(key) != value for key, value in required.items()):
        raise ReproductionError("CI template does not satisfy disposable database/automation boundaries.")
    for key in ["STRIPE_ENABLED", "BKASH_ENABLED", "NAGAD_ENABLED", "CRYPTO_ENABLED", "BANK_TRANSFER_ENABLED", "PROMPTPAY_ENABLED"]:
        if values.get(key) != "false":
            raise ReproductionError("Provider rails must be disabled in the isolated template.")
    if values.get("APP_KEY", "") != "":
        raise ReproductionError("An application key must be generated, not copied into reproduction.")
    shutil.copyfile(template, destination)
    destination.chmod(0o600)
    database = project / "database/thai_lottery_test"
    if database.exists() or database.is_symlink():
        raise ReproductionError("Reproduction must start without an existing test database.")
    database.touch(mode=0o600)


def process_environment(project: Path) -> dict[str, str]:
    # External DB_URL/APP_ENV/production keys must not override the fresh template.
    prefixes = ("APP_", "DB_", "LOTTERY_", "GLO_", "FINANCE_", "PRIZE_", "STRIPE_", "BKASH_", "NAGAD_",
                "CRYPTO_", "BANK_", "PROMPTPAY_", "NATIONAL_", "WEEKLY_", "BINGO_", "PCSO_", "AGENT_", "AUTH_",
                "QUEUE_", "CACHE_", "SESSION_", "MAIL_", "BROADCAST_", "LOG_", "FILESYSTEM_", "PAYMENT_", "RISK_")
    clean = {k: v for k, v in os.environ.items() if not k.startswith(prefixes)}
    clean.update({"APP_ENV": "testing", "APP_DEBUG": "false", "DB_URL": "", "DB_CONNECTION": "sqlite",
                  "DB_DATABASE": "database/thai_lottery_test", "QUEUE_CONNECTION": "sync",
                  "CACHE_STORE": "array", "SESSION_DRIVER": "array", "MAIL_MAILER": "log",
                  "PRIZE_PAYOUT_SAFETY_MODE": "DISABLED", "LOTTERY_AUTOMATION_ENABLED": "false",
                  "LOTTERY_AUTO_PAYOUT": "false", "LOTTERY_AUTO_SETTLE": "false",
                  "CARGO_HOME": str(Path.home() / ".cache/weekly-cargo")})
    return clean


def junit_totals(path: Path) -> dict[str, int]:
    root = ET.parse(path).getroot()
    suite = root.find("testsuite")
    if suite is None:
        raise ReproductionError("PHPUnit did not produce its complete suite report.")
    values = {key: int(suite.attrib[key]) for key in ("tests", "assertions", "errors", "failures", "skipped")}
    if values["tests"] < 1 or any(values[key] for key in ("errors", "failures", "skipped")):
        raise ReproductionError("Reproduction requires actual unfiltered passing tests with no skips.")
    return values


def reproduce(args: argparse.Namespace) -> dict:
    source = Path(args.source).resolve()
    archive = Path(args.archive).resolve()
    manifest = Path(args.manifest).resolve()
    inputs = checked_inputs(source, archive, manifest)
    php = Path(args.php).resolve()
    composer = Path(args.composer).resolve()
    if not php.is_file() or not os.access(php, os.X_OK) or not composer.is_file():
        raise ReproductionError("A real executable PHP and Composer phar are required.")
    workspace = new_workspace(Path(args.workspace), source)
    project = workspace / "project"
    project.mkdir()
    report_dir = new_workspace(Path(args.report_dir), source)
    environment = process_environment(project)
    base = subprocess.run(["git", "archive", "--format=tar", inputs["base_commit"]], cwd=source,
                          stdin=subprocess.DEVNULL, capture_output=True, check=True).stdout
    extract_git_base(base, project)
    restorer = source / "scripts/restore-part3-bundle.py"
    common = ["--archive", str(archive), "--manifest", str(manifest), "--project", str(project)]
    run([sys.executable, str(restorer), "restore", *common], source, environment, report_dir / "01-source-restore.log")
    run([sys.executable, str(restorer), "verify", *common], source, environment, report_dir / "02-source-verify.log")
    (workspace / "reproduction-marker.json").write_text(json.dumps({"base_commit": inputs["base_commit"],
        "archive_sha256": inputs["archive_sha256"], "disposable": True}, indent=2) + "\n", encoding="utf-8")
    safe_ci_environment(project)
    steps = [
        ("03-php-runtime", [str(php), "scripts/check-test-runtime.php"]),
        ("04-composer-install", [str(php), str(composer), "install", "--no-interaction", "--prefer-dist"]),
        ("05-disposable-key", [str(php), "artisan", "key:generate", "--force"]),
        ("06-clear-local-cache", [str(php), "artisan", "optimize:clear"]),
        ("07-platform", [str(php), str(composer), "check-platform-reqs"]),
        ("08-npm-ci", ["npm", "ci"]),
        ("09-frontend", ["npm", "run", "build"]),
        ("10-native", ["bash", "scripts/build-weekly-native-verifier.sh"]),
        ("11-full-phpunit", [str(php), "-d", "memory_limit=-1", "vendor/bin/phpunit", "--log-junit", str(report_dir / "full.junit.xml")]),
    ]
    for name, command in steps:
        print(f"Reproduction step: {name}", flush=True)
        run(command, project, environment, report_dir / (name + ".log"))
    totals = junit_totals(report_dir / "full.junit.xml")
    report = {"status": "local-clean-reproduction-passed", "base_commit": inputs["base_commit"],
              "archive_sha256": inputs["archive_sha256"], "bundle_members_verified": len(inputs["files"]),
              "fresh_git_base": True, "fresh_ci_environment": True, "fresh_test_database": True,
              "unfiltered_phpunit": totals, "hosted_ci_executed": False, "deployed_or_external_transfer": False,
              "commands": [{"step": name, "argv": command} for name, command in steps],
              "log_sha256": {p.name: sha256(p) for p in sorted(report_dir.glob("*.log"))}}
    (report_dir / "report.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("source", "archive", "manifest", "workspace", "report-dir", "php", "composer"):
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(reproduce(args), indent=2))
        return 0
    except (ReproductionError, OSError, ValueError, subprocess.SubprocessError, ET.ParseError) as failure:
        print(f"LOCAL_REPRODUCTION_FAILED: {failure}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
