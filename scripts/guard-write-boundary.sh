#!/usr/bin/env bash
#
# =============================================================================
# WRITE-BOUNDARY REGRESSION GATE
# =============================================================================
#
# WHY THIS SCRIPT EXISTS
#
# Commit 544d319 ("feat: apply GLO write boundary security patch") added the
# four-eyes guard to DrawResultConfirmationService and made GloResultImportService
# delegate to DrawResultIngestionService.
#
# Commit f5cec15 ("feat: restore and sync missing core source code from backup")
# silently reverted both. 147 files changed, +2,433 / -4,595, and nothing in the
# pipeline noticed — because the pipeline has never once been green (11 runs, 0
# successes).
#
# That is the failure mode this gate closes. The control it protects is the
# separation between the operator who INGESTS an official result and the operator
# who CONFIRMS it. Without it, one person can publish a lottery result.
#
# A grep-based gate is a blunt instrument, and it is deliberately blunt: it must
# be impossible to satisfy by accident, cheap to run, and legible to a reviewer
# who is not a PHP developer. It is a tripwire, not a proof — the behavioural
# proof lives in tests/Feature/Glo/GloWriteBoundaryTest.php. Both are required:
# the tests prove the control works, this gate ensures the tests still have
# something to test.
#
# USAGE
#   bash scripts/guard-write-boundary.sh
#   exit 0 = boundary intact
#   exit 1 = boundary breached (CI must fail)
#
set -euo pipefail

FAIL=0

red()   { printf '\033[31m%s\033[0m\n' "$1"; }
green() { printf '\033[32m%s\033[0m\n' "$1"; }
warn()  { printf '\033[33m%s\033[0m\n' "$1"; }

fail() { red "  ✗ $1"; FAIL=1; }
pass() { green "  ✓ $1"; }

# Strip PHP COMMENTS ONLY, then search the remaining CODE.
#
# Two things this must get right, and the first draft got one of them wrong:
#
#   1. COMMENTS MUST GO. The source files document the vulnerable pattern in
#      their own docblocks (explaining what was wrong and why). A naive grep
#      matches that documentation and reports a breach that is not there — or,
#      worse, someone "fixes" it by deleting the explanation, destroying the
#      institutional knowledge that stops the pattern returning.
#
#   2. STRING LITERALS MUST STAY. An earlier version of this gate stripped
#      strings as well, and consequently could never pass: `ingested_by` reaches
#      the code as the array key $record['ingested_by'], and the fail-closed
#      emptiness test is `$fingerprint === ''`. Both vanish when string literals
#      are removed. A gate that can never pass is worse than no gate — it trains
#      people to ignore the red.
#
# Uses PHP's own lexer, so it is exactly as strict as the language.
strip_php_code() {
    php -r '
        $src = file_get_contents($argv[1]);
        if ($src === false) { fwrite(STDERR, "cannot read\n"); exit(2); }
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                echo $t[1];
            } else {
                echo $t;
            }
        }
    ' "$1"
}

# -----------------------------------------------------------------------------
# 1. The four-eyes guard must exist on the confirmation path.
# -----------------------------------------------------------------------------
echo
echo "1. Four-eyes guard present on DrawResultConfirmationService"

CONFIRM="app/Services/Draw/DrawResultConfirmationService.php"

if [ ! -f "$CONFIRM" ]; then
    fail "$CONFIRM is missing entirely"
else
    if strip_php_code "$CONFIRM" | grep -q 'ingested_by'; then
        pass "confirmation refuses self-confirmation (reads ingested_by)"
    else
        fail "FOUR-EYES GUARD MISSING — an operator can confirm their own ingested result"
    fi

    if strip_php_code "$CONFIRM" | grep -q 'confirmationForbidden'; then
        pass "refusal is raised as DrawResultException::confirmationForbidden"
    else
        fail "no confirmationForbidden refusal — the guard may be present but not raised"
    fi
fi

# -----------------------------------------------------------------------------
# 2. The importer must not write draw_results.
# -----------------------------------------------------------------------------
echo
echo "2. GLO importer cannot write draw_results"

IMPORT="app/Services/Lottery/GloResultImportService.php"

if [ ! -f "$IMPORT" ]; then
    fail "$IMPORT is missing entirely"
else
    CODE="$(strip_php_code "$IMPORT")"

    if printf '%s' "$CODE" | grep -qE 'DrawResult::(create|firstOrCreate|updateOrCreate|insert)'; then
        fail "importer WRITES draw_results directly — write boundary breached"
    else
        pass "importer does not write draw_results"
    fi

    if printf '%s' "$CODE" | grep -qE 'new[[:space:]]+DrawResult'; then
        fail "importer constructs a DrawResult — write boundary breached"
    else
        pass "importer does not construct DrawResult"
    fi

    if printf '%s' "$CODE" | grep -q 'published_at'; then
        fail "importer sets published_at — an unattended import can publish an official result"
    else
        pass "importer does not set published_at"
    fi

    if printf '%s' "$CODE" | grep -q 'DrawResultIngestionService'; then
        pass "importer delegates to the ingestion gate"
    else
        fail "importer does not delegate to DrawResultIngestionService"
    fi

    if printf '%s' "$CODE" | grep -qE "fingerprint[[:space:]]*===[[:space:]]*''"; then
        pass "missing-fingerprint guard present (fails closed)"
    else
        fail "missing-fingerprint guard gone — an unpinnable payload can be staged"
    fi
fi

# -----------------------------------------------------------------------------
# 3. The lifecycle guard must still gate ingestion.
# -----------------------------------------------------------------------------
echo
echo "3. Ingestion lifecycle guard intact"

INGEST="app/Services/Draw/DrawResultIngestionService.php"

if [ ! -f "$INGEST" ]; then
    fail "$INGEST is missing entirely"
else
    if strip_php_code "$INGEST" | grep -q 'mayIngestIn'; then
        pass "ingestion consults mayIngestIn before staging"
    else
        fail "lifecycle guard gone — a result can be staged into an Open or Settled draw"
    fi
fi

# -----------------------------------------------------------------------------
# 4. No other writer may publish a result unilaterally.
# -----------------------------------------------------------------------------
echo
echo "4. No unguarded second publication door"

SECOND_DOOR="app/Services/Lottery/GloResultPublicationService.php"

if [ ! -f "$SECOND_DOOR" ]; then
    pass "GloResultPublicationService (legacy second door) is absent"
else
    if strip_php_code "$SECOND_DOOR" | grep -q 'assertFourEyesSatisfied'; then
        pass "legacy publication door requires a confirmed ingestion"
    else
        fail "legacy publication door is UNGUARDED — it can publish without a second operator"
    fi
fi

# -----------------------------------------------------------------------------
# 5. Publication itself must not accept caller-supplied winners or money.
# -----------------------------------------------------------------------------
echo
echo "5. Publication refuses caller-controlled winners and money"

# The refusal lives on the VALIDATOR the publication service calls, not on the
# publication service itself. Checking the wrong file is how a gate ends up
# permanently yellow and permanently ignored.
VALIDATOR="app/Services/Draw/DrawResultValidator.php"

if [ -f "$VALIDATOR" ]; then
    if strip_php_code "$VALIDATOR" | grep -q 'REFUSED_FIELDS'; then
        pass "validator refuses server-derived fields (winners, multipliers, payouts)"
    else
        fail "refused-fields list gone — a caller could submit winners or prize money"
    fi

    if strip_php_code "$VALIDATOR" | grep -q 'assertNoRefusedFieldsSupplied'; then
        pass "refusal is actually enforced on every validate() call"
    else
        fail "refused-fields list exists but is not enforced"
    fi
else
    fail "$VALIDATOR is missing entirely"
fi

# -----------------------------------------------------------------------------
echo
if [ "$FAIL" -ne 0 ]; then
    red "WRITE BOUNDARY BREACHED — see failures above."
    echo
    echo "This gate protects the maker/checker separation over official results."
    echo "If a legitimate change requires updating this gate, update it in the SAME"
    echo "commit and say why in the message. Do not delete it to go green:"
    echo "commit f5cec15 did exactly that to the code, and nothing noticed for weeks."
    echo
    exit 1
fi

green "WRITE BOUNDARY INTACT"
echo
exit 0
