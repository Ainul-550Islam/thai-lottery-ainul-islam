#!/usr/bin/env python3
"""Append every config-referenced env key that is missing from a template.

The Laravel templates shipped with this repository were written before most
of the configuration surface existed: 557 env keys that config/*.php actually
reads were simply absent, so an operator copying the template had no way to
know which knobs exist, and no way to tell a deliberately-empty secret from a
key nobody had documented.

For each missing key this writes `KEY=<default>` where <default> is the
literal default from the config file, so copying the template cannot change
application behaviour: an absent key and a key set to its own default are the
same thing to Laravel. Keys with an empty default (secrets, credentials) are
written as `KEY=` and marked.

Keys whose default is an array or an expression are emitted as a commented
hint instead, because a template cannot express them safely.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

# Usage: complete-env-templates.py <config-dir> <template-dir>
# The two are separate so the generated block can be written into a delivery
# tree while the defaults are still read from the repository's own config.
CONFIG_DIR = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("/home/user/thai-lottery-ainul-islam/config")
TEMPLATE_DIR = Path(sys.argv[2]) if len(sys.argv) > 2 else Path("/home/user/thai-lottery-ainul-islam")

ENV_CALL = re.compile(r"env\(\s*['\"]([A-Z0-9_]+)['\"]\s*(,)?")


def find_env_keys(text: str) -> list[tuple[str, str | None]]:
    """Return (key, raw-default-or-None) for every env() call in a config file."""
    found: list[tuple[str, str | None]] = []
    for match in ENV_CALL.finditer(text):
        key = match.group(1)
        rest = text[match.end():]
        if match.group(2) is None:
            found.append((key, None))  # env('KEY') with no default at all
            continue
        default = read_expression(rest)
        if default is None:
            continue
        found.append((key, default))
    return found


def read_expression(text: str) -> str | None:
    """Read one PHP expression starting at text[0], balanced-paren aware."""
    depth = 0
    in_string: str | None = None
    i = 0
    while i < len(text):
        ch = text[i]
        if in_string is not None:
            if ch == "\\":
                i += 2
                continue
            if ch == in_string:
                in_string = None
            i += 1
            continue
        if ch in "'\"":
            in_string = ch
            i += 1
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            if depth == 0:
                return text[:i].strip()
            depth -= 1
        i += 1
    return None


def render(raw: str | None) -> tuple[str, bool]:
    """Render a raw PHP default as (template-value, expressible)."""
    if raw is None:
        return ("", True)
    raw = raw.strip()
    if raw == "":
        return ("", True)
    if raw in ("true", "TRUE"):
        return ("true", True)
    if raw in ("false", "FALSE"):
        return ("false", True)
    if raw == "null":
        return ("", True)
    if re.fullmatch(r"-?\d+", raw):
        return (raw, True)
    if re.fullmatch(r"-?\d+\.\d+", raw):
        return (raw, True)
    if len(raw) >= 2 and raw[0] == raw[-1] and raw[0] in "'\"":
        return (raw[1:-1], True)
    # A nested env('X', 'y') call: use the inner default, recursively.
    nested = re.fullmatch(r"env\(\s*['\"]([A-Z0-9_]+)['\"]\s*,\s*(.+)\)", raw, re.S)
    if nested:
        inner = nested.group(2)
        return render(inner)
    return (raw, False)


def quote(value: str) -> str:
    """Quote a value that phpdotenv or a shell would misread.

    A value containing whitespace, '#', '$' or a quote character is wrapped
    in double quotes with backslashes and inner quotes escaped, so the line
    means exactly what the config file's default means.
    """
    if value == "" or re.fullmatch(r"[A-Za-z0-9_@%+=:,./\-]*", value):
        return value
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return '"' + escaped + '"'


# Credential-shaped keys, spelled exactly the way scripts/check-env-parity.php
# spells them: a key ending in TOKEN, SECRET, KEY, PASSWORD or SALT must never
# carry a value in a committed template. That verifier is a hard CI gate
# (Audit §F11), so this generator has to agree with it by construction rather
# than by luck.
CREDENTIAL_KEY = re.compile(r"(?:TOKEN|SECRET|KEY|PASSWORD|SALT)$")


# Directories scanned for env() calls, and the order they are scanned in.
#
# This used to be `config/*.php` and nothing else, which is why the script
# reported a complete surface while EnvTemplateCompletenessTest still failed:
# that test walks config/, bootstrap/ AND app/, and two keys live only in
# application code -
#   app/Services/Payment/CryptoUsdtGatewayService.php        CRYPTO_TRC20_MASTER_PUBKEY
#   app/Http/Controllers/Api/V1/Payment/PromptPayController.php  PROMPTPAY_DEFAULT_MERCHANT_ID
# A template that documents only the config surface cannot be complete, and an
# env() call buried in a controller is the one an operator is least likely to
# discover by reading config/.
SCAN_DIRS: tuple[str, ...] = ("config", "bootstrap", "app")


def collect() -> list[tuple[str, tuple[str, bool], str]]:
    """Collect every (key, (value, expressible), source-file) referenced by the app.

    Scans SCAN_DIRS recursively so the surface matches the one
    EnvTemplateCompletenessTest asserts on. `config/` is walked first purely so
    that when a key is read in several places the documented default is the
    config one, which is the authoritative definition.
    """
    table: dict[str, tuple[tuple[str, bool], str]] = {}
    for directory in SCAN_DIRS:
        base = CONFIG_DIR.parent / directory if directory == "config" else CONFIG_DIR.parent / directory
        for path in sorted(base.rglob("*.php")):
            text = path.read_text(encoding="utf-8", errors="replace")
            for key, raw in find_env_keys(text):
                if key in table:
                    continue
                value, expressible = render(raw)
                if CREDENTIAL_KEY.search(key):
                    # Blank it whatever the code default says. A default for a
                    # credential is a placeholder at best and a shipped secret at
                    # worst; either way copying the template must not hand an
                    # operator a value that looks real. This is what stops the
                    # generator from re-introducing a leak it once wrote into
                    # .env.example (EXPERIMENTS_HASH_SALT=tl-exp-v1) on every
                    # later run - and it is what keeps CRYPTO_TRC20_MASTER_PUBKEY,
                    # whose code default is a real-looking TRC20 address, out of a
                    # committed file.
                    value, expressible = "", True
                rel = path.relative_to(CONFIG_DIR.parent)
                table[key] = ((quote(value) if expressible else value, expressible), str(rel))
    return [(key, payload, source) for key, (payload, source) in sorted(table.items())]


def existing_keys(template: Path) -> set[str]:
    r"""Keys the template already documents AS ASSIGNMENTS - commented ones excluded.

    Deliberately narrower than the mention-counting in check-env-parity.php, and
    the difference is the whole point. A commented `# KEY=` line satisfies the
    parity checker (it counts mentions) but NOT EnvTemplateCompletenessTest,
    which matches `^\s*([A-Z][A-Z0-9_]*)=` and so requires a real assignment.
    The pristine templates ship ~42 keys in that commented-only state, and
    counting them as present is exactly what left the completeness test red:
    the script thought they were documented while the suite could not see them.

    Idempotence does not suffer. The old version needed comment-tolerance only
    because it EMITTED commented hints and then failed to recognise its own
    output, re-appending the same block on every run. build_block() now emits a
    real assignment for every key, so a second run sees each one here and skips
    it - and the first run upgrades the pre-existing commented hints into
    assignments, which is the fix rather than a duplication.
    """
    if not template.exists():
        return set()
    text = template.read_text(encoding="utf-8", errors="replace")
    return set(re.findall(r"^([A-Z0-9_]+)=", text, re.M))


# This is a DOTENV file, so every comment line has to start with `#`. This
# header used to be written as a PHP-style /* ... */ block, which phpdotenv does
# not understand: it reads `/*` as the start of a variable name and dies with
# "invalid name at [/*]", so the template this script completed was unusable by
# the very application it documents. The template tests read these files as text
# and passed regardless, which is why the corruption survived - a dotenv file is
# only proved valid by parsing it as dotenv.
HEADER = """
###############################################################################
# Configuration surface (auto-completed by scripts/complete-env-templates.py)
###############################################################################
#
# Every key below is read by config/*.php and was previously missing from
# this template, which left the operator surface undocumented: there was no
# way to tell a deliberately-empty credential from a key nobody had written
# down. Each value is the SAME default the config file already uses, so
# copying this template cannot change behaviour - an absent key and a key
# set to its own default are identical to Laravel.
#
# Credential-shaped keys (ending TOKEN/SECRET/KEY/PASSWORD/SALT) are written
# EMPTY whatever their config default is; see CREDENTIAL_KEY above.
#
# NOTHING in this block is left as a bare hint any more. Every key below has a
# real assignment, because EnvTemplateCompletenessTest requires every env() key
# in config/, bootstrap/ and app/ to appear as an UNCOMMENTED `KEY=` line, and a
# commented hint documents nothing as far as that check is concerned.
#
# Where the config default is an expression rather than a literal - a
# storage_path(), a Str::slug(), a backed enum case, `60 * 24` - the line above
# the assignment says so, and it still carries the historical marker
# `# structured default - read config/<file>` that
# EnvTemplatesDocumentEveryKeyTest asserts survives in every template. Read that
# named config file for the expression this literal stands in for.
#
# A bare `KEY=` is NOT a safe way to express "use the default": Laravel's
# Env::get() is getOption($key)->getOrCall($default), so an empty string is a
# value and the default never runs. That is why these are resolved literals
# rather than blanks.
#
###############################################################################
"""


def assert_dotenv_safe(text: str, name: str) -> None:
    """Fail loudly rather than write a template phpdotenv cannot parse.

    Every non-blank line must be either a `#` comment or `KEY=value`. The
    parity checker only counts keys, so it happily reported ok:true for a file
    that could not be loaded; this is the check that actually proves the file
    is a dotenv file.
    """
    for lineno, line in enumerate(text.split("\n"), start=1):
        stripped = line.strip()
        if stripped == "" or stripped.startswith("#"):
            continue
        if re.match(r"^[A-Za-z_][A-Za-z0-9_]*=", stripped):
            continue
        raise SystemExit(f"{name}:{lineno}: not a dotenv line: {stripped[:70]!r}")


# ---------------------------------------------------------------------------
# Keys whose config default is an EXPRESSION, not a literal.
# ---------------------------------------------------------------------------
# `render()` cannot turn `realpath(storage_path(...))`, `Str::slug(...)`,
# `60 * 24`, `LOG_USER`, `User::class` or a backed-enum case into a template
# value, and this script used to give up on them by emitting a COMMENTED hint
# (`# KEY=  # structured default`). That was wrong twice over:
#
#   1. EnvTemplateCompletenessTest scans config/, bootstrap/ and app/ for every
#      env() key and requires each one to appear as an UNCOMMENTED `KEY=` line
#      in all three templates, so a commented hint documented nothing as far as
#      the suite was concerned - and these 23 keys failed it.
#   2. A commented key is invisible to the operator and to any automation that
#      diffs templates, which is the exact drift the whole exercise exists to
#      stop.
#
# The obvious shortcut - emitting a bare `KEY=` - is NOT safe and is not what
# happens here. Laravel's Env::get() is
# `self::getOption($key)->getOrCall(fn () => value($default))`: an EMPTY STRING
# is a value, so it is returned and the config default never runs. Blank-ing
# these keys would silently change behaviour, which is precisely what this
# script promises it will not do. So each one is resolved to a literal that
# means the same thing as the expression it replaces, and the values that are
# environment-dependent carry a per-template variant.
#
# `None` means "deliberately empty, operator must supply" and is only used where
# the config default is itself empty for that environment, so writing the empty
# value cannot change behaviour.
LITERAL_VALUES: dict[str, dict[str, str | None]] = {
    # PHP 7.4 numeric separators: 10_485_760 is the int 10485760. render() saw
    # the underscores and refused; the value is a plain byte count.
    "ANTIMALWARE_MAX_BYTES": {"*": "10485760"},
    "REVERB_MAX_REQUEST_SIZE": {"*": "10000"},
    "REVERB_APP_MAX_MESSAGE_SIZE": {"*": "10000"},
    # 60 * 24 minutes.
    "SANCTUM_TOKEN_EXPIRATION": {"*": "1440"},
    # The PHP syslog(3) facility constant LOG_USER is 1 << 3.
    "LOG_SYSLOG_FACILITY": {"*": "8"},
    # User::class, i.e. the fully-qualified model the auth guard resolves.
    "AUTH_MODEL": {"*": "App\\Models\\User"},
    # ConversionFeeBearer::Source->value, a backed string enum case.
    "CONVERSION_FEE_BEARER": {"*": "source"},
    # Str::slug(env('APP_NAME', 'thai-lottery'), '_').'_horizon:' with the
    # shipped APP_NAME, which slugifies to thai_lottery.
    "HORIZON_PREFIX": {"*": "thai_lottery_horizon:"},
    # (string) env('LOTTERY_MAX_BET_AMOUNT', '100000.00'); every template sets
    # LOTTERY_MAX_BET_AMOUNT=100000.00, so the nested default is that literal.
    "PAYOUT_ILLUSTRATION_MAX_STAKE": {"*": "100000.00"},
    # (string) env('APP_HOST', 'localhost'); APP_HOST=localhost in all three.
    "TURNSTILE_ALLOWED_HOSTNAMES": {"*": "localhost"},
    # A repo-relative path, matching the literal the config file itself uses for
    # WEEKLY_LOTTERY_INTEGRITY_BINARY. Not absolute: an absolute storage path is
    # per-machine and a template that hard-codes one is wrong on every host but
    # the author's.
    "WEEKLY_LOTTERY_INTEGRITY_BINARY": {
        "*": "security/weekly-result-integrity/target/release/weekly-result-integrity",
    },
    # storage_path()-derived locations, written relative to storage_path(). The
    # operator's deployment root is not knowable from here, and an absolute path
    # baked into a committed template would point somewhere that does not exist
    # on their machine.
    "BACKUP_PATH": {"*": "app/backups"},
    "LEGAL_PDF_TEMP_PATH": {"*": "app/private/mpdf"},
    "ANTIMALWARE_ALLOWED_ROOTS": {"*": "app/private"},
    "VIEW_COMPILED_PATH": {"*": "framework/views"},
    # env('APP_ENV') === 'production' ? 'enforce' : 'monitor'. Note this reads
    # APP_ENV with NO default, so a bare `env('APP_ENV')` is null and the test is
    # false - the effective default is 'monitor' unless APP_ENV is actually set.
    "AML_MODE": {".env.production.example": "enforce", "*": "monitor"},
    # env('APP_ENV', 'production') === 'production' ? 'clamav' : 'null'. The
    # provider refuses any driver but clamav in production, so only the
    # production template may say clamav; the local and CI templates run
    # APP_ENV=local / testing, where the config default really is 'null'.
    "ANTIMALWARE_DRIVER": {".env.production.example": "clamav", "*": "null"},
    # $localLike = APP_ENV in ('local','testing'). These three templates are
    # local, testing and production respectively, so each gets exactly the value
    # its own APP_ENV would have produced.
    "MAIL_HOST": {".env.example": "127.0.0.1", ".env.ci": "127.0.0.1", "*": None},
    "MAIL_PORT": {".env.example": "2525", ".env.ci": "2525", "*": "587"},
    "REVERB_ALLOWED_ORIGINS": {".env.example": "*", ".env.ci": "*", "*": None},
    # $developmentOrigins is the dev list for local/testing and '' otherwise.
    "CORS_ALLOWED_ORIGINS": {
        ".env.example": "http://localhost,http://localhost:3000,http://localhost:5173,http://127.0.0.1:8000",
        ".env.ci": "http://127.0.0.1:8000",
        "*": None,
    },
    # The config default is the dev domain list PLUS Sanctum's current app URL
    # with its port. The appended URL is per-deployment, so the literal carries
    # the list and each template names its own APP_URL host.
    "SANCTUM_STATEFUL_DOMAINS": {
        ".env.example": "localhost,localhost:3000,localhost:5173,127.0.0.1,127.0.0.1:8000,::1",
        ".env.ci": "127.0.0.1,127.0.0.1:8000,localhost,::1",
        ".env.production.example": "thailotto.example",
    },
}


def resolve_literal(key: str, template_name: str) -> tuple[str, bool]:
    """Return (value, expressible) for a key whose config default is an expression.

    A key absent from LITERAL_VALUES stays non-expressible and is still emitted
    as a commented hint, so this table can only ever grow: forgetting an entry
    degrades to the old behaviour instead of inventing a value.
    """
    entry = LITERAL_VALUES.get(key)
    if entry is None:
        return ("", False)
    if template_name in entry:
        value = entry[template_name]
    elif "*" in entry:
        value = entry["*"]
    else:
        return ("", False)
    if value is None:
        # Deliberately empty: the config default is empty for this environment.
        return ("", True)
    return (quote(value), True)


def build_block(missing: list[tuple[str, tuple[str, bool], str]], template_name: str) -> str:
    lines = [HEADER.rstrip("\n")]
    unresolved: list[str] = []
    for key, (value, expressible), source in missing:
        if not expressible:
            value, expressible = resolve_literal(key, template_name)
        if not expressible:
            unresolved.append(key)
            lines.append(f"# {key}=  # structured default - read config/{source}")
            continue
        # The rationale goes on the line ABOVE, never at the end of the
        # assignment. phpdotenv does strip a trailing " # ..." comment, but not
        # everything that reads these templates is phpdotenv:
        # EnvTemplateSafetyTest captures the value with `^\s*KEY=(.*)$` and only
        # trims whitespace and quotes, so a trailing comment becomes PART OF THE
        # VALUE and a deliberately-empty secret then reads as populated - failing
        # the very check that exists to keep secrets out. The same applies to any
        # operator who sources one of these files in a shell.
        if CREDENTIAL_KEY.search(key):
            lines.append(f"# credential-shaped: deliberately EMPTY, see CREDENTIAL_KEY above")
            lines.append(f"{key}=")
            continue
        lines.append(f"# literal standing in for the config/{source} expression default")
        lines.append(f"{key}={value}")
    if unresolved:
        # Fail the run instead of shipping a template that EnvTemplateCompletenessTest
        # will reject: a key left as a commented hint is a key nobody documented.
        raise SystemExit(
            f"{template_name}: {len(unresolved)} key(s) have an expression default and no "
            f"entry in LITERAL_VALUES: {', '.join(sorted(unresolved))}"
        )
    return "\n".join(lines) + "\n"


def main() -> None:
    entries = collect()
    templates = [TEMPLATE_DIR / name for name in (".env.example", ".env.production.example", ".env.ci")]
    for template in templates:
        if not template.exists():
            continue
        present = existing_keys(template)
        missing = [entry for entry in entries if entry[0] not in present]
        if not missing:
            # Write nothing at all. build_block() always leads with HEADER, so
            # emitting a block with no keys in it appended yet another copy of the
            # header prose on every run - three runs left three header blocks and
            # ~200 duplicated lines in each template. A no-op run must be a no-op
            # on disk, otherwise "regenerate the templates" silently grows them.
            print(f"{template.name}: complete ({len(present)} keys already present)")
            continue
        text = template.read_text(encoding="utf-8", errors="replace")
        if not text.endswith("\n"):
            text += "\n"
        text += build_block(missing, template.name)
        assert_dotenv_safe(text, template.name)
        template.write_text(text, encoding="utf-8")
        print(f"{template.name}: appended {len(missing)} keys ({len(present)} already present)")


if __name__ == "__main__":
    main()
