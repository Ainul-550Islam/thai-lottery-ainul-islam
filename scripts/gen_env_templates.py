#!/usr/bin/env python3
"""Derive .env.ci and .env.production.example from the committed .env.example.

composer.json's post-root-package-install hook has always told a new operator to
"provision .env from your secret store using .env.production.example as the key
list" — but that file did not exist. Neither did the `.env.ci` that
ProductionConfigSafetyTest requires CI to have committed. This generator creates
both.

Both files are required by tests/Feature/Production/ProductionConfigSafetyTest:

  * `.env.production.example` must state every fixture lane as literally
    `KEY=false` and must ship NO credential with a value.
  * `.env.ci` must exist and must never enable a fixture lane.

Deriving rather than hand-writing keeps the two templates honest about
coverage: every key `.env.example` documents is present in both, in the same
order, under the same comments, so adding a key upstream cannot silently leave
the production template unable to express it.

Usage:  python3 scripts/gen_env_templates.py <repo-root>
        composer run env:templates
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# A key whose name ends in one of these suffixes is treated as a credential.
# ProductionConfigSafetyTest::the_production_template_ships_no_credential_values
# uses exactly this shape, so the generator uses it too rather than maintaining
# a second, drifting list.
CREDENTIAL = re.compile(r"^([A-Z0-9_]*(?:TOKEN|SECRET|KEY|PASSWORD|SALT))=(.*)$")
ASSIGNMENT = re.compile(r"^([A-Z0-9_]+)=(.*)$")

HEADER_PROD = """###############################################################################
#
#   PRODUCTION ENVIRONMENT TEMPLATE  —  .env.production.example
#
#   Copy this to `.env` on the deployment target and fill in every REQUIRED
#   marker below. It is derived from `.env.example` (same keys, same order,
#   same comments) with three differences, all deliberate:
#
#     1. Every value that ProductionSafetyServiceProvider::violations() checks
#        is set to the SAFE production value, not the development default.
#        Booting with this file as-is must produce zero violations.
#     2. Every credential is present but VALUELESS. A template that ships a
#        secret is a secret in the git history forever.
#     3. Every fake-result lane is explicitly `false`, so the four lane configs
#        and the GLO ladder are all read from a stated decision rather than
#        from a default.
#
#   `APP_KEY` is intentionally empty: run `php artisan key:generate --force`
#   once on the target, or inject it from the secret store.
#
#   This file is NOT the deployment's environment. It is the contract the
#   deployment must satisfy. ProductionConfigSafetyTest fails the build if the
#   shipped copy stops satisfying it.
#
###############################################################################
"""

HEADER_CI = """###############################################################################
#
#   CONTINUOUS INTEGRATION ENVIRONMENT  —  .env.ci
#
#   This file is COMMITTED on purpose, and CI copies it to `.env` instead of
#   copying `.env.example`. Two reasons, both of which were real defects:
#
#     * `.env.example` is the DEVELOPMENT template. It defaults APP_ENV to
#       `local`, APP_DEBUG to `true`, the cache/session/queue to per-node file
#       and sync drivers, and the GLO lane to `fixture`. A pipeline that boots
#       from it is testing a configuration nobody deploys, and a green run says
#       nothing about the one that ships.
#     * Deriving CI from a template means every change to that template is
#       silently a change to the pipeline. `.env.ci` makes the pipeline's
#       environment a reviewed, versioned artefact of its own.
#
#   It is deterministic by construction: no external service is required for the
#   steps that boot the framework (`config:cache`, `migrate:fresh --seed`,
#   `route:list`). The jobs that DO run MySQL and Redis service containers set
#   DB_*, CACHE_STORE and QUEUE_CONNECTION as job-level environment variables,
#   and those win over this file because Laravel loads `.env` immutably.
#
#   NO FIXTURE RESULT LANE IS ENABLED HERE. phpunit.xml turns the four lane
#   fixtures on for the test suites only, at the PHPUnit level, and the lane
#   configs force them off whenever APP_ENV is `production`. This file is the
#   third leg of that argument: a pipeline that passed only because a fake
#   result lane was available would prove nothing.
#
###############################################################################
"""

# Production-safe values. Anything not listed here keeps its `.env.example`
# value, which is the point: the template stays complete.
PROD_OVERRIDES: dict[str, str] = {
    "APP_ENV": "production",
    "APP_DEBUG": "false",
    "APP_URL": "https://thailotto.example",
    # Stack traces are served to the public on debug; warnings are the lowest
    # level that still surfaces a deprecation or a failed third-party call.
    "LOG_LEVEL": "warning",
    # Operator retention, not a developer's disk quota.
    "LOG_DAILY_DAYS": "90",
    # A file cache, a file session store and a sync queue are each a per-node
    # control substrate. ProductionSafetyServiceProvider refuses all three.
    "CACHE_STORE": "redis",
    "SESSION_DRIVER": "redis",
    "SESSION_ENCRYPT": "true",
    "SESSION_SECURE_COOKIE": "true",
    # `lax`, not `strict`: a returning top-level redirect from a payment
    # gateway must still carry the session cookie, or the deposit confirmation
    # page lands on a logged-out user who has just paid.
    "SESSION_SAME_SITE": "lax",
    "QUEUE_CONNECTION": "redis",
    "REDIS_HOST": "127.0.0.1",
    # `log` in production silently discards password resets and OTP mail. The
    # relay is REQUIRED; the mailer must not quietly pretend to have sent.
    "MAIL_MAILER": "smtp",
    "MAIL_HOST": "",
    "MAIL_PORT": "587",
    "MAIL_USERNAME": "",
    "MAIL_FROM_ADDRESS": "no-reply@thailotto.example",
    "BROADCAST_CONNECTION": "redis",
    "REVERB_HOST": "",
    "REVERB_PORT": "443",
    "REVERB_SCHEME": "https",
    "SANCTUM_STATEFUL_DOMAINS": "thailotto.example",
    # Empty here means API bearer tokens never expire, which the gate refuses.
    "SANCTUM_TOKEN_EXPIRATION": "1440",
    # The GLO lane moves the money. `fixture` replays synthetic test vectors
    # that RealPrizeSettlementService would pay real prizes against.
    "GLO_OFFICIAL_SOURCE_MODE": "official",
    "GLO_SOURCE_PRIORITY": "official",
    "GLO_FALL_THROUGH_TO_FIXTURE": "false",
    # No unattended money movement until a live payout is commissioned.
    "PRIZE_PAYOUT_SAFETY_MODE": "DISABLED",
    "LOTTERY_AUTO_PAYOUT": "false",
    # Sandbox credentials against a production merchant account is the single
    # most common payment misconfiguration; say `false` and require the
    # operator to have obtained production keys.
    "BKASH_SANDBOX": "false",
    "NAGAD_SANDBOX": "false",
    "AUTH_CAPTCHA_ENABLED": "true",
}

# Keys the production template annotates with a REQUIRED marker comment inserted
# immediately above the assignment, so the copy step cannot miss them.
REQUIRED_KEYS: tuple[str, ...] = (
    "APP_URL",
    "DB_HOST",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
    "REDIS_HOST",
    "MAIL_HOST",
    "SANCTUM_STATEFUL_DOMAINS",
)

# Values that CI needs to differ from both other templates.
CI_OVERRIDES: dict[str, str] = {
    # `testing`, matching the job-level APP_ENV in .github/workflows/ci.yml and
    # phpunit.xml. This is what makes ProductionSafetyServiceProvider inert for
    # the framework-boot steps while the safety contract is still asserted
    # directly by ProductionConfigSafetyTest.
    "APP_ENV": "testing",
    "APP_DEBUG": "false",
    "APP_URL": "http://127.0.0.1:8000",
    "LOG_LEVEL": "debug",
    # A file-backed SQLite store so `migrate:fresh --seed` and `config:cache`
    # run with no service container. The name is deliberately NOT
    # `thai_lottery_test`: every test suite carries a fail-safe guard that
    # refuses to touch any database but that one, and CI's own migrate must
    # never be able to reach the suite's store.
    "DB_CONNECTION": "sqlite",
    "DB_DATABASE": "database/thailotto_ci.sqlite",
    "DB_FOREIGN_KEYS": "true",
    "DB_BUSY_TIMEOUT": "15000",
    "CACHE_STORE": "array",
    "SESSION_DRIVER": "array",
    "QUEUE_CONNECTION": "sync",
    "BROADCAST_CONNECTION": "log",
    "FILESYSTEM_DISK": "local",
    "MAIL_MAILER": "log",
    "SANCTUM_STATEFUL_DOMAINS": "127.0.0.1:8000,localhost:8000",
    "SANCTUM_TOKEN_EXPIRATION": "1440",
    # The lane configs force fixtures off in production, but CI is not
    # production. State it, so a green pipeline means the real ladder ran.
    "GLO_OFFICIAL_SOURCE_MODE": "official",
    "GLO_SOURCE_PRIORITY": "official",
    "GLO_FALL_THROUGH_TO_FIXTURE": "false",
    "PRIZE_PAYOUT_SAFETY_MODE": "DISABLED",
    "LOTTERY_AUTO_PAYOUT": "false",
    # Both payment gateways stay disabled in CI, but sandbox mode is stated so
    # that a job which deliberately enables one cannot reach a live merchant.
    "BKASH_SANDBOX": "true",
    "NAGAD_SANDBOX": "true",
    # risk.exposure.max_stake_per_number has no default by design: a silently
    # invented real-money ceiling would look configured while expressing
    # nobody's risk appetite. CI must state one exactly as an operator must.
    "RISK_MAX_STAKE_PER_NUMBER": "100000.00",
}

# Keys appended to CI only (not present in .env.example), with their comments.
CI_EXTRA: tuple[tuple[str, str], ...] = ()


def render(source: list[str], overrides: dict[str, str], header: str, *, blank_credentials: bool,
           required: tuple[str, ...] = ()) -> str:
    """Rewrite `source` lines, applying overrides and (optionally) blanking secrets."""
    out: list[str] = [header.rstrip("\n"), ""]
    seen: set[str] = set()
    marked: set[str] = set()

    for line in source:
        stripped = line.rstrip("\n")

        credential = CREDENTIAL.match(stripped)
        assignment = ASSIGNMENT.match(stripped)

        if credential is not None and blank_credentials:
            key, _value = credential.group(1), credential.group(2)
            seen.add(key)
            if key in overrides:
                # An override still wins: SANCTUM_TOKEN_EXPIRATION is not a
                # credential, and PERMISSION_CACHE_KEY is a cache-key NAME that
                # happens to end in `_KEY`. config/permission.php falls back to
                # its own default when the value is empty, so shipping it
                # valueless costs nothing.
                out.append(f"{key}={overrides[key]}")
            elif key == "PERMISSION_CACHE_KEY":
                out.append("# Valueless on purpose: this is a cache-key NAME, not a")
                out.append("# credential, but it ends in `_KEY` and the credential gate")
                out.append("# is name-shaped. config/permission.php falls back to its own")
                out.append("# default when this is empty.")
                out.append(f"{key}=")
            else:
                out.append(f"{key}=")
            if key in required and key not in marked:
                marked.add(key)
                out.insert(-1, "# REQUIRED: set on the deployment target.")
            continue

        if assignment is not None:
            key, _value = assignment.group(1), assignment.group(2)
            seen.add(key)
            if key in overrides:
                out.append(f"{key}={overrides[key]}")
            else:
                out.append(stripped)
            if key in required and key not in marked:
                marked.add(key)
                out.insert(-1, "# REQUIRED: set on the deployment target.")
            continue

        out.append(stripped)

    for key, value in CI_EXTRA:
        if key not in seen:
            out.append(f"{key}={value}")
            seen.add(key)

    # Fail loudly rather than ship a template that quietly lost a key.
    for key in overrides:
        if key not in seen and key not in {k for k, _ in CI_EXTRA}:
            raise SystemExit(f"override {key} has no matching line in .env.example")

    return "\n".join(out).rstrip("\n") + "\n"


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__, file=sys.stderr)
        return 2

    root = Path(sys.argv[1]).resolve()
    example = (root / ".env.example").read_text(encoding="utf-8").splitlines()

    prod = render(
        example,
        PROD_OVERRIDES,
        HEADER_PROD,
        blank_credentials=True,
        required=REQUIRED_KEYS,
    )
    (root / ".env.production.example").write_text(prod, encoding="utf-8")

    ci = render(
        example,
        {**PROD_OVERRIDES, **CI_OVERRIDES},
        HEADER_CI,
        blank_credentials=True,
    )
    (root / ".env.ci").write_text(ci, encoding="utf-8")

    # Self-check the two contracts ProductionConfigSafetyTest enforces.
    fixture_keys = (
        "NATIONAL_LOTTERY_FIXTURE_ENABLED",
        "WEEKLY_LOTTERY_FIXTURE_ENABLED",
        "BINGO_LOTTERY_FIXTURE_ENABLED",
        "PCSO_LOTTERY_FIXTURE_ENABLED",
    )

    failures: list[str] = []

    for key in fixture_keys:
        if f"{key}=true" in prod or f"{key}=true" in ci:
            failures.append(f"{key}=true leaked into a template")
        if not re.search(rf"^{re.escape(key)}=false$", prod, re.M):
            failures.append(f"{key}=false missing from .env.production.example")

    for line_no, line in enumerate(prod.splitlines(), start=1):
        m = CREDENTIAL.match(line)
        if m and m.group(2) != "":
            failures.append(f".env.production.example:{line_no} {m.group(1)} carries a value")

    for name, text in ((".env.production.example", prod), (".env.ci", ci)):
        example_keys = {m.group(1) for m in (ASSIGNMENT.match(l) for l in example) if m}
        mine = {m.group(1) for m in (ASSIGNMENT.match(l) for l in text.splitlines()) if m}
        missing = example_keys - mine
        if missing:
            failures.append(f"{name} lost keys: {sorted(missing)}")

    if failures:
        for f in failures:
            print("FAIL " + f)
        return 1

    print(f"OK .env.production.example {len(prod.splitlines())} lines")
    print(f"OK .env.ci {len(ci.splitlines())} lines")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
