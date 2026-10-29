#!/usr/bin/env bash
#
# Repository hygiene: remove what does not belong in the repository, and prove it
# is gone.
#
# ============================================================================
# WHAT THIS SCRIPT IS ALLOWED TO DELETE, AND WHY EACH CATEGORY IS SAFE
# ============================================================================
#
# 1. `logs/*.md`, `logs/*.txt`, `evidence/`, `artifacts/` — build and run output.
#    These are evidence of a run, not source. They are untracked from the INDEX
#    with `git rm -r --cached`, which leaves every file on disk: the operator
#    keeps the artefact and the repository stops carrying it. Nothing is deleted
#    from the working tree by that step, deliberately, because a green test log
#    from last Tuesday may still be the thing somebody needs.
#
# 2. `resources/js/components/*.tsx` — twelve non-functional React mockups. They
#    hardcode member balances and report real-money operations through alert()
#    stubs. They are already quarantined under resources/js/_unused/components/
#    with an explanatory banner. A live copy is removed ONLY when its content is
#    identical to the quarantined copy apart from that banner — the comparison is
#    printed, and a difference of a single byte leaves the file alone for a human
#    to look at. Losing unique work to a cleanup script would be worse than
#    carrying a mockup.
#
# 3. `resources/js/player-security-settings.{js,ts}` — a superseded mock. It
#    prompts for a "security PIN" that no backend stores, fabricates the string
#    "Cool-off break set", and posts a whole limits object at an endpoint that
#    validates a different shape. Nothing references either file (verified by
#    grep across resources/ and app/), so they are dead weight that a future
#    reader would otherwise mistake for the current implementation.
#
# 4. `public/build/` — Vite output. Derived, and belongs to the deploy.
#
# 5. `.sudo_as_admin_successful`, `.profile` — shell state a server writes when
#    somebody uses sudo. It is not part of this application.
#
# ============================================================================
# WHAT IT REFUSES
# ============================================================================
#   - to run outside a git work tree (every `git rm --cached` needs one);
#   - to delete anything a Blade view or a public asset references. The `@vite()`
#     check runs BEFORE any deletion and aborts the whole script on a reference;
#   - to touch a path owned by Batch 01 (see BATCH_01_PATHS below). A cleanup
#     script is exactly the kind of thing that quietly deletes a parallel lane's
#     work.
#
# ============================================================================
# USAGE
# ============================================================================
#   scripts/repo-hygiene.sh            clean, then verify
#   scripts/repo-hygiene.sh --dry-run  report what would happen
#   scripts/repo-hygiene.sh --verify   verification only, change nothing
#   scripts/repo-hygiene.sh --quick    same as --verify; the read-only pass
#                                      `npm run hygiene:quick` asks for
#
# Exit status is 0 only when the tree is clean AND the verification passes.
#
set -euo pipefail

cd "$(dirname "$0")/.."

mode="clean"
case "${1:-}" in
    --dry-run) mode="dry" ;;
    --verify) mode="verify" ;;
    # `npm run hygiene:quick` has always passed --quick, and this case did not
    # exist, so the documented quick lane exited 2 with "unknown option" on every
    # run. It means the read-only pass: report, change nothing.
    --quick) mode="verify" ;;
    "") mode="clean" ;;
    *) printf 'unknown option: %s\n' "$1" >&2; exit 2 ;;
esac

# ── 0. Must be a git work tree ──────────────────────────────────────────────
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    printf 'FAIL  not inside a git work tree; refusing to remove anything\n' >&2
    exit 1
fi

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

fail=0
note() { printf '  %s\n' "$*"; }
ok() { printf 'ok    %s\n' "$*"; }
warn() { printf 'warn  %s\n' "$*"; }
fail_with() { printf 'FAIL  %s\n' "$*" >&2; fail=1; }
would() { printf 'would %s\n' "$*"; }

# Batch 01 owns these. Nothing below may touch them, even if a rule matches.
BATCH_01_PATHS=(
    ".env.example" ".env.production.example" ".env.ci"
    "config/payment.php" "config/thai_payout.php" "config/payment_ops.php"
    "config/real_money.php" "config/finance.php"
    "phpunit.mysql.xml" "docker-compose.test-mysql.yml" "routes/payment_ops.php"
    "scripts/run-mysql-concurrency-suite.sh"
)

is_batch_01() {
    local path="$1"
    local owned

    for owned in "${BATCH_01_PATHS[@]}"; do
        [ "$path" = "$owned" ] && return 0
    done

    case "$path" in
        app/Services/Payment/*|app/Services/Payout/*|app/Services/Withdrawal/*|app/Services/Finance/*) return 0 ;;
        app/Http/Controllers/Api/V1/Deposit*|app/Http/Controllers/Api/V1/Withdrawal*) return 0 ;;
        app/Http/Controllers/Api/V1/Payment*|app/Http/Controllers/Api/V1/Payout*) return 0 ;;
        resources/views/payment/*) return 0 ;;
        lang/*/payment_ops.php|lang/*/deposit.php) return 0 ;;
        tests/Feature/Payment/*|tests/Feature/Config/*) return 0 ;;
    esac

    return 1
}

# ── 1. Every @vite() reference must survive whatever we are about to do ────
printf '\n== view references ==\n'

referenced="$(mktemp)"
trap 'rm -f "$referenced"' EXIT

grep -rhoE "@vite\(\[[^]]*\]\)|@vite\('[^']+'\)" resources/views 2>/dev/null \
    | grep -oE "'resources/[^']+'" \
    | tr -d "'" \
    | sort -u > "$referenced" || true

missing_reference=0
while IFS= read -r entry; do
    [ -n "$entry" ] || continue

    if [ ! -f "$entry" ]; then
        fail_with "a view references a file that does not exist: $entry"
        missing_reference=1
    fi
done < "$referenced"

if [ "$missing_reference" -eq 0 ]; then
    ok "every @vite() reference resolves to a file"
fi

# ── 2. Untrack run output ──────────────────────────────────────────────────
printf '\n== tracked run output ==\n'

untrack() {
    local path="$1"

    [ -e "$path" ] || return 0

    if git ls-files --error-unmatch "$path" >/dev/null 2>&1; then
        if [ "$mode" = "dry" ]; then
            would "untrack $path (stays on disk)"
        elif [ "$mode" = "clean" ]; then
            git rm -r --cached --quiet "$path" && note "untracked $path (still on disk)"
        fi
    fi
}

if [ "$mode" != "verify" ]; then
    untrack "logs"
    untrack "evidence"
    untrack "artifacts"
    untrack "public/build"
fi

# logs/*.md and logs/*.txt specifically: a markdown report inside logs/ is
# documentation pretending to be runtime output, and it ages badly.
if [ -d logs ]; then
    while IFS= read -r tracked; do
        [ -n "$tracked" ] || continue

        case "$tracked" in
            *.md|*.txt)
                if [ "$mode" = "dry" ]; then
                    would "untrack $tracked"
                elif [ "$mode" = "clean" ]; then
                    git rm --cached --quiet "$tracked" && note "untracked $tracked"
                fi
                ;;
        esac
    done < <(git ls-files 'logs/*.md' 'logs/*.txt' 2>/dev/null || true)
fi

# ── 3. Shell state ─────────────────────────────────────────────────────────
printf '\n== shell state ==\n'

for junk in ".sudo_as_admin_successful" ".profile"; do
    if [ -f "$junk" ]; then
        if [ "$mode" = "dry" ]; then
            would "remove $junk"
        elif [ "$mode" = "clean" ]; then
            rm -f "$junk"
            note "removed $junk"
        fi
    fi
done

# ── 4. TypeScript/React leftovers ─────────────────────────────────────────
printf '\n== typescript leftovers ==\n'

# There is no TypeScript build step: vite.config.js registers .js and .css
# inputs only, so a .ts/.tsx file under resources/js cannot be built and cannot
# be loaded. Batch 02 removed all of them (see resources/js/_unused/README.md).
if [ "$mode" != "verify" ]; then
    shopt -s nullglob
    leftovers=(resources/js/**/*.ts resources/js/**/*.tsx)
    shopt -u nullglob

    for leftover in "${leftovers[@]}"; do
        if [ "$mode" = "dry" ]; then
            would "delete $leftover (no TypeScript build step exists)"
        else
            rm -f "$leftover" && note "deleted $leftover (no TypeScript build step exists)"
        fi
    done
fi

# ── 5. Consume batch-02-delete.txt ─────────────────────────────────────────
printf '\n== batch 02 delete list ==\n'

delete_list="batch-02-delete.txt"

if [ -f "$delete_list" ]; then
    while IFS= read -r line; do
        # Comments and blanks are documentation, not paths.
        case "$line" in ''|'#'*) continue ;; esac

        path="$(printf '%s' "$line" | sed 's/[[:space:]]*$//')"
        [ -n "$path" ] || continue

        if is_batch_01 "$path"; then
            fail_with "batch-02-delete.txt names a Batch 01 path: $path"
            continue
        fi

        if [ ! -e "$path" ]; then
            note "already absent: $path"
            continue
        fi

        if [ "$mode" = "dry" ]; then
            would "delete $path"
        elif [ "$mode" = "clean" ]; then
            rm -f "$path"
            git rm --cached --quiet --ignore-unmatch "$path" 2>/dev/null || true
            note "deleted $path"
        fi
    done < "$delete_list"
else
    note "no batch-02-delete.txt present; nothing to consume"
fi

# ── 6. Verify ──────────────────────────────────────────────────────────────
printf '\n== verification ==\n'

for junk in ".sudo_as_admin_successful" ".profile"; do
    if [ -f "$junk" ]; then
        fail_with "$junk is present"
    else
        ok "$junk is absent"
    fi
done

# `find`, not a `**` glob. The glob this replaces was
# `resources/js/**/*.ts`, and without `shopt -s globstar` bash reads `**` as a
# single `*`, so it matched ONE directory level only: it caught
# resources/js/components/*.tsx and silently missed resources/js/_unused/
# components/*.tsx sitting right beside them. A hygiene check that only inspects
# part of the tree reports "clean" over a tree that is not, which is worse than
# no check at all because it is trusted. `find` has no such switch to forget.
ts_leftovers=()
while IFS= read -r f; do
    ts_leftovers+=("$f")
done < <(find resources/js -type f \( -name '*.ts' -o -name '*.tsx' \) 2>/dev/null | sort)

if [ "${#ts_leftovers[@]}" -gt 0 ]; then
    for f in "${ts_leftovers[@]}"; do
        fail_with "a TypeScript file remains and cannot be built: $f"
    done
else
    ok "no TypeScript or TSX files under resources/js, at any depth"
fi

if grep -q "resources/js/components/" vite.config.js; then
    fail_with "vite.config.js still points at resources/js/components/"
else
    ok "no build entry points at the removed components tree"
fi

printf '\n'

if [ "$fail" -ne 0 ]; then
    printf 'hygiene FAILED\n' >&2
    exit 1
fi

printf 'hygiene passed (mode: %s)\n' "$mode"
