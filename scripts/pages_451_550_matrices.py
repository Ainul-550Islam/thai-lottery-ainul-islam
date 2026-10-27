# TYPE: Python matrix document generator
# PURPOSE: Generate Pages 451–550 acceptance rows and route/API/security/finance/lottery/Rust/runtime/release matrices.

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUDIT = (ROOT / "audit.md").read_text(encoding="utf-8")
section = AUDIT.split("## Pages 451–550 runtime activation audit matrix", 1)[1].split("## Cumulative coverage register through Pages 1–550", 1)[0]
raw = [line for line in section.splitlines() if line.startswith("| ") and line.split("|")[1].strip().isdigit()]
rows = []
for line in raw:
    cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
    rows.append(cells)
if len(rows) != 100 or [int(row[0]) for row in rows] != list(range(451, 551)):
    raise RuntimeError("Pages 451–550 matrix requires exactly 100 ordered rows.")

out = [
    "# TYPE: Pages 451–550 runtime activation and release matrices",
    "# PURPOSE: Preserve one acceptance row per page and map every release-critical runtime boundary to actual evidence.",
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
    "| Pages | Boundary | Required evidence | Status |",
    "|---:|---|---|---|",
    "| 451–462 | Environment, PHP, Composer, Laravel, config, routes, views, health | Execute required binaries, Artisan commands, cached boot, route smoke, view compilation, and health endpoint | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 463–481 | Database, migrations, transactions, Redis, queue, scheduler | Controlled database/service execution, rollback, commit, concurrency, locks, worker, retries, and schedule | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 482–496 | Draw/result lifecycle and historical data behavior | Controlled draw/result pipeline with provenance, conflict, certification, publication, APIs, and safe no-data behavior | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 497–518 | Provider, deposits, wallet, withdrawals, reconciliation | Provider sandbox and canonical wallet/ledger lifecycle with replay, mismatch, timeout, and reconciliation evidence | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 519–531 | Betting, responsible gaming, tickets, prizes, payouts | Server-authoritative controlled purchase-to-payout tests with idempotency and concurrency | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 532–544 | GLO capability, ticket, prizes, claims, KYC, freeze, publication | Fail-closed capability proof or real configured canonical execution | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 545–550 | Browser, security, final release | Browser journeys, security controls, Rust, backup/restore, CI, and final gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Security matrix",
    "",
    "| Control | Pages | Required evidence | Status |",
    "|---|---:|---|---|",
    "| Secret-free environment inventory | 451, 497, 550 | Presence metadata only; no credentials or token values | PARTIALLY VERIFIED |",
    "| PHP/Laravel auth and session | 545–549 | Register, login, reset, MFA, rotation, revocation, and role tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Authorization and IDOR | 547 | Cross-owner API/web attempts for wallet, payment, tickets, claims, support, and KYC | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| CSRF and rate limits | 548 | Browser POST/PATCH/DELETE negative tests and throttle behavior | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Webhook signature/replay/mismatch | 501–505 | Valid, invalid, replayed, mismatched, and malformed callback matrix | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| KYC/age/freeze/responsible gaming | 522–523, 538–542 | Denial and valid-state tests for financial and GLO flows | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Runtime log/error redaction | 462, 497, 545–550 | Search logs and responses for secrets, credentials, tokens, private data, and unsafe internals | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Financial integrity matrix",
    "",
    "| Pages | Flow | Required invariant | Status |",
    "|---:|---|---|---|",
    "| 463–471 | Database transactions | Atomic commit/rollback, exact money, isolation, lock/retry, and no orphaned financial event | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 472–481 | Redis/queue/scheduler | One lock owner, idempotent retry, terminal failed state, and duplicate-safe schedules | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 499–506 | Deposits and callbacks | Provider verification precedes one wallet credit; mismatch/replay/timeout fail closed | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 507–518 | Wallet, withdrawal, reconciliation | Balance equation, holds, one payout, recovery, audit, and provider reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 519–531 | Bets and prizes | Server price, fee, RG, idempotency, one ticket, exact settlement, and one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Lottery and GLO matrix",
    "",
    "| Pages | Pipeline | Required invariant | Status |",
    "|---:|---|---|---|",
    "| 482–496 | Draw/result lifecycle | Cutoff, timezone, provenance, leading zeros, conflict, certification, publication, cache, and safe no-data behavior | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 527–531 | Prize matching and settlement | Backend-authoritative result, exact money, controlled settlement, payout idempotency, and failure recovery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 532 | GLO purchase capability | Canonical capability remains `NOT_CONFIGURED`; no checkout or purchase test is invented | NOT_CONFIGURED |",
    "| 533–544 | GLO L6 lifecycle | Exact ticket/prize engine, claims, age/KYC/holds, freeze, frozen-winner processing, and publication | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Rust matrix",
    "",
    "| Pages | Command | Required evidence | Status |",
    "|---:|---|---|---|",
    "| 451 | Cargo availability | Inventory executable/version | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 550 | `cargo fmt --check` | Formatting gate against locked Rust boundary | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 550 | `cargo check --locked --all-targets` | Locked compile and target validation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 550 | `cargo test --locked` | Deterministic/malformed/leading-zero/exit-code tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 550 | `cargo build --release --locked` | Release binary build | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Backup and restore matrix",
    "",
    "| Gate | Required evidence | Status |",
    "|---|---|---|",
    "| Backup creation | Controlled staging/test database backup and checksum | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Restore | Restore into fresh controlled database | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Restored boot | Laravel boot and schema validation against restored database | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Financial comparison | Wallet/ledger and controlled transaction comparison before/after restore | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Lottery/audit comparison | Controlled lottery and audit record comparison before/after restore | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## CI/CD and deployment matrix",
    "",
    "| Gate | Evidence | Status |",
    "|---|---|---|",
    "| Composer/PHP tests | Hosted CI run identifier and green result | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| NPM/audit/build | Local `npm ci`, `npm audit`, and `npm run build` | VERIFIED |",
    "| Rust CI | Hosted locked Rust commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Deployment config | Production install/build/cache/health execution | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Monitoring/logging | Health, metrics, alerts, redaction, and failure behavior | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Final audit | 100 Pages 451–550 rows and cumulative register through 550 | PARTIALLY VERIFIED |",
    "",
    "## Release decision matrix",
    "",
    "| Decision | Evidence | Result |",
    "|---|---|---|",
    "| Final acceptance | `runtime/pages-451-550-final-acceptance.json` | RELEASE BLOCKED |",
    "| Production ZIP | ZIP creation rule | NOT_APPLICABLE while critical gates are unresolved |",
    "| Code snapshot ZIP | Optional backup snapshot only | NOT_APPLICABLE; no snapshot was requested or created |",
])

(ROOT / "PAGES-451-550-MATRICES.md").write_text("\n".join(out) + "\n", encoding="utf-8")
print("Generated PAGES-451-550-MATRICES.md with 100 page rows.")
