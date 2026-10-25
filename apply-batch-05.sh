#!/usr/bin/env bash
#
# apply-batch-05.sh — apply BATCH 05 (Payments / Support / Ops) to the
# thai-lottery-ainul-islam repository.
#
# WHAT THIS SCRIPT DOES
#   1. Refuses to run anywhere except the repository root.
#   2. Refuses to run when the working tree is dirty (so a failed apply can be
#      reverted with one `git checkout -- .` and nothing else is lost).
#   3. Copies every file in this delivery tree onto its repo-relative path.
#      Files that are not in this tree are never touched: this batch does not
#      reformat, reorder or "improve" anything it does not own.
#   4. Applies DELETE-LIST.txt (see that file for the evidence).
#   5. Completes the three .env templates with every configuration key
#      config/*.php reads that was previously missing.
#   6. Prints the verification commands the operator must still run by hand.
#
# WHAT THIS SCRIPT DOES NOT DO
#   It does not run composer, PHPUnit or PHP's linter. Those are the
#   operator's to run, in their environment, against their database.
#
set -euo pipefail

BATCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${1:-$(pwd)}"

die() { printf 'apply-batch-05: %s\n' "$1" >&2; exit 1; }

# --- 1. where are we? -------------------------------------------------------
[ -f "$REPO_ROOT/artisan" ] || die "not a Laravel repository root: $REPO_ROOT"
[ -d "$REPO_ROOT/app" ] || die "no app/ directory under $REPO_ROOT"
[ -f "$REPO_ROOT/composer.json" ] || die "no composer.json under $REPO_ROOT"
cd "$REPO_ROOT"

EXPECTED_HEAD="ad253c0"
ACTUAL_HEAD="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
if [ "$ACTUAL_HEAD" != "unknown" ] && [ "$ACTUAL_HEAD" != "$EXPECTED_HEAD" ]; then
    printf 'apply-batch-05: WARNING: HEAD is %s, this batch was prepared against %s.\n' \
        "$ACTUAL_HEAD" "$EXPECTED_HEAD" >&2
    printf 'apply-batch-05: continuing; review `git diff` before committing.\n' >&2
fi

# --- 2. a clean tree --------------------------------------------------------
# Only TRACKED modifications block the apply: an untracked leftover from an
# earlier partial run must not make this script unusable, while a modified
# tracked file is somebody's work-in-progress and must not be overwritten.
if [ -n "$(git diff --name-only 2>/dev/null)$(git diff --cached --name-only 2>/dev/null)" ]; then
    die "tracked files are modified. Commit or stash first, so this apply can be reverted cleanly."
fi

UNTRACKED="$(git ls-files --others --exclude-standard 2>/dev/null | head -5)"
if [ -n "$UNTRACKED" ]; then
    printf 'apply-batch-05: note: untracked files are present (leftovers from an earlier run?):\n%s\n' \
        "$UNTRACKED" >&2
fi

# --- 3. the files -----------------------------------------------------------
COPIED=0
while IFS= read -r file; do
    target="$REPO_ROOT/$file"
    mkdir -p "$(dirname "$target")"
    cp "$BATCH_DIR/$file" "$target"
    COPIED=$((COPIED + 1))
# Only application files travel. The batch's own paperwork (this script, the
# deletion list, the README) and the generator stay with the delivery tree: a
# repository root is not a place to keep a delivery note.
done < <(cd "$BATCH_DIR" && find . -type f \
    ! -name 'apply-batch-05.sh' \
    ! -name 'DELETE-LIST.txt' \
    ! -name 'BATCH-05-README.md' \
    ! -path './scripts/*' \
    ! -name '.env.example' \
    ! -name '.env.production.example' \
    ! -name '.env.ci' \
    -printf '%P\n' | sort)

printf 'apply-batch-05: wrote %d application/test/config files\n' "$COPIED"

# --- 4. the .env templates --------------------------------------------------
# The templates in this tree are already complete. They are copied rather than
# regenerated so the applied result is byte-for-byte what was reviewed; the
# generator is kept alongside them for the next time a config key is added.
for template in .env.example .env.production.example .env.ci; do
    cp "$BATCH_DIR/$template" "$REPO_ROOT/$template"
    printf 'apply-batch-05: wrote %s\n' "$template"
done
mkdir -p "$REPO_ROOT/scripts"
cp "$BATCH_DIR/scripts/complete-env-templates.py" "$REPO_ROOT/scripts/"

# --- 5. deletions -----------------------------------------------------------
DELETED=0
while IFS= read -r line; do
    case "$line" in
        app/*|tests/*|config/*|routes/*|database/*)
            if [ -e "$REPO_ROOT/$line" ]; then
                rm -f "$REPO_ROOT/$line"
                DELETED=$((DELETED + 1))
                printf 'apply-batch-05: deleted %s\n' "$line"
            fi
            ;;
    esac
# Only the FILES section is machine-read: the prose above it is for the
# reviewer, and a path mentioned in an explanation must never be deleted
# because it was mentioned.
done < <(sed -n '/^FILES$/,$p' "$BATCH_DIR/DELETE-LIST.txt" \
    | grep -oE '^(app|tests|config|routes|database)/[A-Za-z0-9_./-]+\.php$' | sort -u)

printf 'apply-batch-05: deleted %d file(s)\n' "$DELETED"

# --- 6. what the operator must still do ------------------------------------
cat <<'NEXT'

apply-batch-05: applied. The following MUST be run before this is a release:

  composer dump-autoload -o          # the deleted file is removed from the class map
  find app tests -name '*.php' -print0 | xargs -0 -n1 php -l
  php artisan config:clear
  php artisan route:clear
  vendor/bin/phpunit --testsuite Feature

  # The two lane commands, which must print exactly what they printed before:
  php artisan thai-rails:preflight
  php artisan realtime:check --skip-network

  # The new scheduler entries appear only when their lane is enabled:
  php artisan schedule:list | grep -E 'thai|truemoney'

NEXT
