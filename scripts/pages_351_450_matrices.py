# TYPE: Python matrix document generator
# PURPOSE: Generate the Pages 351–450 matrix artifact with exactly 100 page rows and operational integrity matrices.

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUDIT = (ROOT / "audit.md").read_text(encoding="utf-8")
section = AUDIT.split("## Pages 351–450 production runtime audit matrix", 1)[1].split("## Cumulative coverage register through Pages 1–450", 1)[0]
raw_rows = [line for line in section.splitlines() if line.startswith("| ") and not line.startswith("| Page |")]
rows = []
for line in raw_rows:
    cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
    if cells and cells[0].isdigit():
        rows.append(cells)
if len(rows) != 100 or [int(row[0]) for row in rows] != list(range(351, 451)):
    raise RuntimeError("Matrix requires exactly one ordered row for every Page 351–450.")

out: list[str] = [
    "# TYPE: Pages 351–450 production runtime matrices",
    "# PURPOSE: Map every acceptance page to its execution target and preserve route/API/security/finance/lottery/Rust/runtime boundaries.",
    "",
    "## Acceptance page ledger",
    "",
    "| Page | Title | Route / Command / Test Target | Status | Runtime Status | Remaining Gap |",
    "|---:|---|---|---|---|---|",
]
for row in rows:
    out.append(f"| {row[0]} | {row[1]} | {row[2]} | {row[22]} | {row[24]} | {row[26]} |")

out.extend([
    "",
    "## Route and API matrix",
    "",
    "| Page range | Boundary | Required evidence | Status |",
    "|---|---|---|---|",
    "| 351–357 | PHP, Composer, Laravel boot, route listing, configuration and cache commands | Execute CLI and inspect route/middleware/cache output | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 358–376 | Database, migrations, Redis, queue, scheduler | Connect controlled services; run schema, transaction, lock, worker, retry, and scheduler commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 377–400 | Draw lifecycle, result import, historical provenance, certification, publication, correction, conflict, public APIs | Execute canonical draw/result pipeline with genuine source data or isolated test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 401–425 | Payment providers, deposits, callbacks, withdrawals, payout, reconciliation, wallet/ledger | Execute configured provider callbacks, replay/mismatch/failure cases, and ledger reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 426–439 | Bet, responsible gaming, idempotency, concurrency, ticket, prize, payout | Execute server-authoritative purchase-to-settlement flows with controlled test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 440–449 | GLO capability, ticket engine, prize, claim, age/KYC/freeze/publication | Reassess capability and execute only canonical GLO services; no invented checkout | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 450 | Enterprise gate | Require every underlying evidence package before acceptance | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Security matrix",
    "",
    "| Control | Pages | Evidence required | Status |",
    "|---|---:|---|---|",
    "| Authentication, session rotation, MFA, revocation | 351–357, 401–449 | PHP/browser login, session, MFA, replay, and revocation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| CSRF, IDOR, ownership, authorization | 355, 391–394, 401–449 | Guest/role/cross-owner/cross-case/cross-ticket denial tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Webhook signature, replay, mismatch, timeout | 401–418 | Provider sandbox callback matrix and idempotency assertions | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Rate limits and abuse controls | 391–394, 401–449 | Throttled endpoint and retry metadata tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Secret/log/error redaction | 351–357, 400, 450 | Runtime logs and error responses contain no secret or unsafe internal data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| KYC, age, freeze, responsible gaming | 411–413, 426–449 | Denial/allowance tests for financial and GLO sensitive states | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Financial integrity matrix",
    "",
    "| Pages | Flow | Required invariant | Status |",
    "|---:|---|---|---|",
    "| 358–366 | Database and transaction | Exact money, atomicity, constraints, isolation, rollback, no double spend | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 367–376 | Redis, queue, scheduler | Lock ownership, idempotent retry, failed-job recovery, single scheduled effect | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 401–410 | Deposits | Verified provider event before one wallet credit; replay/mismatch rejection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 411–419 | Withdrawals | Hold before payout, authorization, one transfer, exact reversal/reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 420–425 | End-to-end finance | Wallet/ledger balances and reconciliation remain consistent | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 426–439 | Bets and prizes | Server price, fee, RG gate, idempotency, one ticket, one settlement/payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Lottery integrity matrix",
    "",
    "| Pages | Pipeline | Required invariant | Status |",
    "|---:|---|---|---|",
    "| 377–381 | Draw lifecycle | Timezone, cutoff, state transition, settlement dispatch, duplicate prevention | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 382–390 | Import and provenance | Genuine source, exact values, duplicate/conflict detection, versioned rollback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 391–400 | Public result surface | Certified/published only, bounded search, safe correction and cache invalidation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 426–439 | Bet/ticket/prize | Ticket ownership, verification, matching, settlement, one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 440–449 | GLO L6 | Canonical capability, exact ticket/prize calculation, claims, freeze, publication | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Rust integrity matrix",
    "",
    "| Page | Boundary | Required evidence | Status |",
    "|---:|---|---|---|",
    "| 351 | Cargo/rustc preflight | Executable availability and version | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 400 | Result/integrity boundary | Rust output validated before canonical publication | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 420 | Financial acceptance | No Rust output may authorize money without Laravel validation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 449 | GLO integrity | Leading-zero and malformed-input vectors through the real boundary | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 450 | Release build | `cargo check --locked`, `cargo test --locked`, `cargo build --release --locked` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Runtime and release matrix",
    "",
    "| Area | Evidence artifact | Result |",
    "|---|---|---|",
    "| Dependency preflight | `runtime/page-351-preflight.json` | PARTIALLY VERIFIED — preflight executed; required runtimes missing |",
    "| Command gate | `runtime/pages-351-450-command-results.json` | 1 command executed successfully; 25 blocked |",
    "| NPM remediation | `runtime/page-351-dependency-remediation.json` | VERIFIED locally: `npm ci`, `npm audit`, and `npm run build` exit 0 |",
    "| PHP/Laravel | `RUNTIME-VERIFICATION-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Database | `DATABASE-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Finance | `FINANCIAL-INTEGRITY-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Lottery/GLO | `LOTTERY-INTEGRITY-REPORT.md` | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Rust | `RUST-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Security | `SECURITY-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| CI/CD source | `CI-CD-VERIFICATION-REPORT.md` | PARTIALLY VERIFIED — workflow source updated; hosted run not executed |",
    "| Enterprise acceptance | Page 450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
])

(ROOT / "PAGES-351-450-MATRICES.md").write_text("\n".join(out) + "\n", encoding="utf-8")
print("Generated PAGES-351-450-MATRICES.md with 100 page rows.")
