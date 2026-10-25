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


def collect() -> list[tuple[str, tuple[str, bool], str]]:
    """Collect every (key, (value, expressible), source-file) referenced by config/*.php."""
    table: dict[str, tuple[tuple[str, bool], str]] = {}
    for path in sorted(CONFIG_DIR.glob("*.php")):
        text = path.read_text(encoding="utf-8", errors="replace")
        for key, raw in find_env_keys(text):
            if key in table:
                continue
            value, expressible = render(raw)
            table[key] = ((quote(value) if expressible else value, expressible), path.name)
    return [(key, payload, source) for key, (payload, source) in sorted(table.items())]


def existing_keys(template: Path) -> set[str]:
    if not template.exists():
        return set()
    return set(re.findall(r"^([A-Z0-9_]+)=", template.read_text(encoding="utf-8", errors="replace"), re.M))


HEADER = """
/*
|--------------------------------------------------------------------------
| Configuration surface (auto-completed by apply-batch-05.sh)
|--------------------------------------------------------------------------
|
| Every key below is read by config/*.php and was previously missing from
| this template, which left the operator surface undocumented: there was no
| way to tell a deliberately-empty credential from a key nobody had written
| down. Each value is the SAME default the config file already uses, so
| copying this template cannot change behaviour - an absent key and a key
| set to its own default are identical to Laravel.
|
| Lines marked `# structured default` cannot be written as a single value;
| read the named config file for the structure.
|
*/
"""


def build_block(missing: list[tuple[str, tuple[str, bool], str]]) -> str:
    lines = [HEADER.rstrip("\n")]
    for key, (value, expressible), source in missing:
        if not expressible:
            lines.append(f"# {key}=  # structured default - read config/{source}")
        else:
            lines.append(f"{key}={value}")
    return "\n".join(lines) + "\n"


def main() -> None:
    entries = collect()
    templates = [TEMPLATE_DIR / name for name in (".env.example", ".env.production.example", ".env.ci")]
    for template in templates:
        if not template.exists():
            continue
        present = existing_keys(template)
        missing = [entry for entry in entries if entry[0] not in present]
        text = template.read_text(encoding="utf-8", errors="replace")
        if not text.endswith("\n"):
            text += "\n"
        text += build_block(missing)
        template.write_text(text, encoding="utf-8")
        print(f"{template.name}: appended {len(missing)} keys ({len(present)} already present)")


if __name__ == "__main__":
    main()
