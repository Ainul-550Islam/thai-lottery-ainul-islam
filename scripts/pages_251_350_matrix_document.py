# TYPE: Python matrix document generator
# PURPOSE: Generate complete Pages 251–350 page, route, API, security, financial, lottery, Rust, and runtime matrices from audit rows.

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
audit = (ROOT / "audit.md").read_text(encoding="utf-8")
rows: dict[int, list[str]] = {}
for line in audit.splitlines():
    match = re.match(r"^\|\s*(25[1-9]|2[6-9][0-9]|3[0-4][0-9]|350)\s*\|", line)
    if match:
        page = int(match.group(1))
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if len(cells) == 27:
            rows[page] = cells

if sorted(rows) != list(range(251, 351)):
    raise RuntimeError("Pages 251–350 matrix requires exactly one 27-column audit row per page.")

out = [
    "# Pages 251–350 complete runtime activation matrices",
    "",
    "# TYPE: Markdown runtime, backend, finance, lottery, security, and Rust matrices",
    "# PURPOSE: Preserve one exact row per page and separate evidence matrices for the Pages 251–350 runtime activation phase.",
    "",
    "Final acceptance boundary: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.",
    "",
    "## Complete Page Matrix",
    "",
    "| " + " | ".join(["Page", "Title", "Route", "Route Name", "HTTP Method", "Middleware", "Authorization", "Controller", "Request", "Service", "DTO", "Model", "Database", "API", "Job/Event", "View", "JS", "CSS", "Translation", "Source of Truth", "Financial Impact", "Security", "Audit", "Status", "Tests", "Runtime Status", "Remaining Gap"]) + " |",
    "|" + "---|" * 27,
]
for page in range(251, 351):
    out.append("| " + " | ".join(rows[page]) + " |")

out.extend([
    "",
    "## Route Matrix",
    "",
    "| Pages | Boundary | HTTP methods | Authorization and middleware | Runtime evidence |",
    "|---|---|---|---|---|",
    "| 251–259 | Python preflight, dependency, Laravel, database, migration, seed, factory, constraint, and transaction audit scripts | CLI/static | Process/filesystem boundary only | Preflight and static JSON executed; Laravel/PHP commands blocked. |",
    "| 260–294 | Existing wallet, ledger, payment, withdrawal, betting, and ticket routes/services | HTTP, CLI, queue, PHPUnit | Existing auth, owner, KYC, CSRF, rate-limit, draw, and provider boundaries | Canonical source/test inventory present; runtime blocked. |",
    "| 295–308 | Existing ticket, draw, result, Rust boundary, import, certification, and publication routes | HTTP, CLI, API, PHPUnit | Existing ticket, draw, result, operator, provider, and process boundaries | Runtime blocked; no result or certification success claimed. |",
    "| 309–318 | Product-specific National, Weekly, PCSO, GLO L6, pricing, prize, and exact-arithmetic lanes | API, CLI, service, PHPUnit | Product and result lane isolation | Runtime blocked; GLO purchase remains NOT_CONFIGURED where canonical capability is not enabled. |",
    "| 319–337 | GLO claims, holds, freezes, settlement, payouts, agents, commissions, referrals | HTTP, API, CLI, queue, PHPUnit | Claim, KYC, age, payout, agent, ownership, and idempotency boundaries | Runtime blocked; no claim, payout, or commission success claimed. |",
    "| 338–340 | Notification dispatch, receipt, suppression, and preferences | Event, queue, API | Recipient ownership and preference policy | Runtime blocked; no delivery claim. |",
    "| 341–346 | Owner-scoped support case contract and portal | GET/POST, auth, CSRF, throttle | Authenticated owner query, bounded reference, no hidden owner field | Implementation is static-only; migration and routes are not runtime verified. |",
    "| 347–349 | Security events, MFA, and session revocation | Event, API | Authentication, MFA, session, and security-event boundaries | Runtime blocked. |",
    "| 350 | Final runtime acceptance gate | CLI | Full enterprise acceptance boundary | 2 frontend commands executed; 11 required commands blocked. |",
    "",
    "## API Contract Matrix",
    "",
    "| Pages | Request / response contract | Canonical owner | Idempotency / ownership | Evidence |",
    "|---|---|---|---|---|",
    "| 260–280 | Wallet, ledger, payment, callback, webhook, queue, replay, and reconciliation contracts | Existing Finance and Payment DTOs/services/enums | Stable references, signatures, callback ownership, replay safety | Static architecture and existing tests; runtime blocked. |",
    "| 281–294 | Withdrawal and bet purchase contracts | Existing Withdrawal and Betting DTOs/services | Session ownership, KYC gate, draw state, wallet reservation, idempotency | Static architecture and existing tests; runtime blocked. |",
    "| 295–308 | Ticket verification, draw lifecycle, result import, certification, publication, and Rust JSON boundary | Existing Ticket, Draw, Result, and Rust crate | Opaque references, source provenance, certification gate, malformed-input validation | Static architecture and existing tests; runtime blocked. |",
    "| 309–328 | Product-specific lottery and GLO result/purchase/claim APIs | Existing product and GLO services | Lane isolation, source state, leading-zero preservation, capability gate | Static architecture and existing tests; runtime blocked. |",
    "| 329–340 | Prize, payout, agent, commission, notification, and preference contracts | Existing Prize, Agent, Finance, and Notification services | Payout/commission idempotency and recipient ownership | Static architecture and existing tests; runtime blocked. |",
    "| 341–349 | Support, security event, MFA, and session contracts | New SupportCaseService plus existing Security services | Owner-scoped case reference, CSRF, throttle, MFA/session policy | Static source and contract tests; runtime blocked. |",
    "",
    "## Security Matrix",
    "",
    "| Pages | Control | Source | Negative case | Evidence |",
    "|---|---|---|---|---|",
    "| 251–259 | Secret-free preflight and configuration inspection | Python scripts and config source | No secrets emitted; no unavailable runtime treated as pass | Executed preflight JSON and static audit JSON. |",
    "| 260–286 | Wallet/payment/withdrawal auth, ownership, KYC, signatures, replay, rate limits, and provider error handling | Canonical services, middleware, DTOs, and existing tests | Cross-owner access, invalid signature, stale/replayed callback, duplicate withdrawal, provider failure | Runtime blocked. |",
    "| 287–307 | Bet/ticket/draw/result controls | Canonical purchase, ownership, draw, result, certification, and publication services | Closed draw, invalid price/currency, duplicate bet, unverified/conflicting result | Runtime blocked. |",
    "| 308–318 | Leading zeros, product lane isolation, Rust process boundary, GLO capability and exact arithmetic | Rust crate and canonical lottery services | Numeric coercion, product crossover, malformed input, disabled purchase | Runtime blocked; Page 314 remains NOT_CONFIGURED. |",
    "| 319–337 | Claim age/KYC, holds, freezes, payout, agent and referral ownership | Canonical GLO, Compliance, Finance, and Agent services | Expired claim, duplicate claim/payout/commission, cross-agent access | Runtime blocked. |",
    "| 338–349 | Notification recipients, support owner cases, security events, MFA, and session revocation | Notification, SupportCase, and Security services | Wrong recipient, cross-owner case, invalid MFA, revoked session | Runtime blocked. |",
    "",
    "## Financial Integrity Matrix",
    "",
    "| Pages | Operation | Atomicity / idempotency | Ledger / audit rule | Runtime status |",
    "|---|---|---|---|---|",
    "| 259–270 | Database transactions, wallet balance, holds, reservations, idempotency, ledger posting, reversals, reconciliation | DB transaction, locks, reservations, stable references, read-only reconciliation | Double-entry and discrepancy evidence remain canonical; no silent repair | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 271–280 | Deposits, callbacks, provider errors, payment states, events, queues, dead letters, replay | Verified callback, signature, replay-safe webhook, queue retry/terminal state | No credit before verification; callback and provider evidence persisted | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 281–294 | Withdrawals, KYC gates, holds, refunds/recovery, bet purchase, price/currency/limits, tickets | Hold/approval/completion atomicity and purchase idempotency | No double hold, payout, debit, or orphan ticket | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 319–332 | Claims, age/KYC, payment holds, freezes, matching, settlement, payout | Claim and payout idempotency; exact money arithmetic | Gross/deductions/net and ledger treatment must reconcile | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 333–337 | Agent commissions, duplicates, settlements, referrals, owner isolation | Qualifying event idempotency and owner-scoped attribution | No duplicate accrual or cross-agent commission claim | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Lottery Integrity Matrix",
    "",
    "| Pages | Lottery boundary | Integrity requirements | Source | Runtime status |",
    "|---|---|---|---|---|",
    "| 287–300 | Bet purchase, ticket issuance, draw open/close, draw state, cutoff | Server price/currency, owner scope, atomic purchase, closed-draw rejection | Betting and Draw services, enums, middleware, existing tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 301–308 | Result import, provenance, duplicate/conflict handling, certification, publication, correction, leading zeros | Preserve source/version/evidence; no auto-publish conflict; exact string values | Draw result services and Rust canonicalization crate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 309–313 | National, Weekly, Mega/other, PCSO, and GLO L6 lanes | Separate product models/services/results; no lane crossover | Product-specific canonical services/models | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 314–318 | GLO purchase capability, six-digit range, pricing, prize allocation, exact scaling | Do not invent checkout; preserve leading zeros; exact decimal arithmetic | GLO L6 services and calculator | Page 314 NOT_CONFIGURED; others blocked. |",
    "| 319–328 | Claim windows, creation, duplicate claim, age/KYC, payment holds, freezes, frozen winners, publication | Winning ticket/result authority, claim eligibility, payout gate, freeze lifecycle | GLO claim, freeze, result, compliance, and payout services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 329–332 | Prize matching, settlement, payout idempotency, payout failure | Backend matching only; no duplicate payout; recoverable failed claim | Prize, Draw, Finance, Payment services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Rust Runtime Matrix",
    "",
    "| Pages | Crate / binary | Input | Output / validation | Determinism / timeout | Evidence |",
    "|---|---|---|---|---|---|",
    "| 308 | `security/weekly-result-integrity` canonicalization | Result JSON with string-preserving values | Canonical bytes/hash/error boundary; Laravel validates output | Leading-zero vectors and deterministic tests exist; Cargo not available | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 350 | Rust build and execution acceptance | Cargo workspace and crate commands | `cargo check`, `cargo test`, `cargo build --release`, malformed input, timeout, exit code, stderr, resource boundary | No execution occurred | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Runtime result summary",
    "",
    "- Page 251 Python preflight executed and produced machine-readable JSON.",
    "- Page 252 NPM installation executed; Composer was unavailable; NPM audit reported one moderate and one high vulnerability.",
    "- Page 350 acceptance gate executed 13 commands: 2 succeeded, 0 failed after execution, and 11 were blocked by unavailable PHP, Composer, Cargo, or related runtime components.",
    "- Laravel, database, queue, payment provider, browser, accessibility, and Rust execution remain `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.",
])

(ROOT / "PAGES-251-350-MATRICES.md").write_text("\n".join(out) + "\n", encoding="utf-8")
print("Generated PAGES-251-350-MATRICES.md with 100 page rows.")
