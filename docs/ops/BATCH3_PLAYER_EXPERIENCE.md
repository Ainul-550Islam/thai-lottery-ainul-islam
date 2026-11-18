# Batch 3 — Player Experience API, Compliance Guard, Ops Hardening

Operator-facing reference for what Batch 3 added, what it deliberately did **not** add,
and how to operate it. Written to be read by the person on call, not by the person who
wrote it.

---

## 1. Scope

Three lanes, one batch:

| Lane | Surface |
|---|---|
| **Player experience** | bet slip, `/me/bets`, wallet overview + money feed, profile, security, notification preferences, support conversations, promotions, responsible-gaming summary |
| **Compliance** | `ComplianceBadgeService`, `MarketingClaimGuard`, attestation rows |
| **Ops** | `/health/live`, `/health/ready`, scheduler heartbeat, read-only `ledger:verify` |

Everything is **read-only or additive**. No existing endpoint changed shape, no existing
table changed meaning, and no existing service was rewritten.

---

## 2. Routes added (25)

### Member routes — all behind `auth:sanctum` + `EnsureUserIsActive`

| Method | Path |
|---|---|
| `GET` / `DELETE` | `/api/v1/me/bet-slip` |
| `POST` | `/api/v1/me/bet-slip/items` |
| `DELETE` | `/api/v1/me/bet-slip/items/{item}` |
| `POST` | `/api/v1/me/bet-slip/confirm` |
| `GET` | `/api/v1/me/bets` · `/api/v1/me/bets/counts` · `/api/v1/me/bets/{bet}` |
| `GET` / `PUT` | `/api/v1/me/notification-preferences` |
| `GET` | `/api/v1/me/profile/overview` |
| `GET` | `/api/v1/me/promotions/active` |
| `GET` | `/api/v1/me/responsible-gaming/summary` |
| `GET` | `/api/v1/me/security/overview` · `/api/v1/me/security/alerts` |
| `GET` / `POST` | `/api/v1/me/support/conversations` |
| `GET` / `POST` | `/api/v1/me/support/conversations/{id}/messages` |
| `POST` | `/api/v1/me/support/conversations/{id}/rating` |
| `GET` | `/api/v1/me/wallet/overview` · `/api/v1/me/wallet/transactions` |

`POST /me/bet-slip/confirm` additionally carries `throttle:bet`, `EnsureAdult` and
`RequireCurrentLegalConsent`.

### Public routes — no auth, throttled

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/v1/public/compliance-badges` | Attestation-backed badges + the two policy notices |
| `GET` | `/health/live` | Registered in `routes/web.php`; named `probe.live` |
| `GET` | `/health/ready` | Registered in `routes/web.php`; named `probe.ready` |

**Why `/health/*` is in `web.php`:** `routes/api.php` inherits the `api` prefix, so a
route declared there answers at `/api/health/live`. The deployment contract is the bare
path. The names are `probe.*` and not `health.*` because `/up/live` and `/up/ready`
already own those names, and Laravel silently lets a duplicate name overwrite.

---

## 3. The compliance rule, stated once

**A badge is published if and only if a valid attestation row exists.**

Valid means: not revoked, started on or before today, ending today or later, and
carrying both a reference and a SHA-256 evidence hash.

- No attestation → no badge. The endpoint returns an **empty `badges` array**, which is
  the correct answer. It never returns a placeholder.
- The **18+** and **responsible-gaming** notices need no attestation: they assert a
  policy the operator applies to itself, not a third-party credential. They are returned
  in a separate `notices` key so a client cannot render them as an audited badge.
- `MarketingClaimGuard` refuses any phrase asserting a credential, an absolute or a
  guarantee. It is wired into the Filament save hook, so an operator typing
  `100% secure` is refused at the point of typing.
- A repository-wide scan (`ForbiddenClaimsTest`) fails the build if such a phrase is
  hardcoded in `app/`, `resources/views`, `lang/` or `config/`.
- The guard understands negation: `"No guaranteed winnings"` is a disclaimer, not a
  claim, and is not refused.

**To publish a licence badge:** create an attestation in the admin console
(*Compliance → Compliance attestations*) with the reference and the document hash.
Publish happens by the row existing; unpublishing is the revoke action (the row stays,
with a reason).

---

## 4. Operating the wallet trend and FX

- **Trend** compares today's balance snapshot against the most recent snapshot at or
  before the horizon (default 30 days). With no snapshot it reports `percent: null` —
  never a fabricated number.
- **Snapshots** are written nightly at 00:35 by `wallet:snapshot-balances`. The write is
  idempotent per `(member, wallet_type, day)`; running it twice is harmless.
- **FX is display-only and off by default.** `player_experience.fx.driver` defaults to
  `null`: no network call, no rate, `fx: null` in the payload. To show a rate, set the
  driver to `manual` and enter rates in *Finance → Display FX rates*. No money path reads
  this table — conversions keep using the audited conversion quote service.

---

## 5. Notification preferences

The screen is a matrix saved in one request. Validation happens for **every** row before
**any** write, so a refused batch leaves no rows behind.

Mandatory categories (security, deposit/withdrawal status, KYC, responsible gaming,
account restriction) cannot be switched off. The refusal is `422` with reason
`mandatory_category`, **and** the dispatcher independently ignores a stored
`enabled = false` row for those categories — because a row can arrive by a path that
never went through the validator.

---

## 6. Ops

| Check | What it proves |
|---|---|
| `GET /health/live` | The process is running. It touches **no** dependency — a liveness probe that queries the database removes healthy containers when the database blips. |
| `GET /health/ready` | DB, cache, queue, scheduler freshness, provider config. `200` ready / `503` not ready, with a closed snake_case reason vocabulary and **no** DSN, host or secret in the payload. |
| `ops:heartbeat` | Every minute. Writes the file `ops:health` reads **and** the cache key `/health/ready` reads. |
| `ledger:verify` | Daily 03:10. Read-only. Exit `0` clean / `1` discrepancy / `2` could-not-run. See `RUNBOOK_ledger_mismatch.md`. |
| `bet-slips:expire` | Hourly. Marks drafts past their TTL as expired. |

Scheduled entries are in `routes/console.php` (Batch 3 section), not `bootstrap/app.php`.

---

## 7. Known gaps — reported, not invented

| Gap | Status |
|---|---|
| **"GLO L6 2D/3D direct bet"** from the mockups | **Does not exist** in this codebase. `BetSlipService` refuses it with reason `game_not_implemented` rather than inventing a market. A test pins the refusal. |
| Cashback balance | Batch 1's `CashbackBalanceReaderInterface` is not installed in this repository. The overview reports `cashback: null` and `cashback_available: false` instead of a zero. |
| Free-bet voucher funding | Not implemented. A slip item with a non-wallet funding source is refused with `funding_source_unsupported` at add time, not at confirm. |
| Support ratings below 1 / above 5 | Range is config-driven (`player_experience.support.rating_min|max`). |
| `ledger:verify` performance | The sweep chunks by id but scans every wallet. On a very large dataset, tune `--chunk`. |

---

## 8. Deliberate non-behaviour

- **A bet slip holds no money.** It reserves no number, appears in no ledger, and
  consults no risk engine. Money moves only in `BetSlipConfirmationService`, via the
  existing purchase pipeline.
- **Confirm is all-or-nothing.** One refused item rolls the whole confirm back.
- **Numeric claims are absent by construction.** No `+5.2%`, no `100% Secure`, no `78%`.
  Where a figure is unknown the payload carries `null`, and the tests assert the absence.
- **Digits stay strings.** `'007'` is never `7`, and every amount is a `decimal:2` string
  from the model, forwarded without a float ever appearing.
