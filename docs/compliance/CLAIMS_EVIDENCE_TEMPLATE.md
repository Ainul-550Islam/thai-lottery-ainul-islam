# CLAIMS EVIDENCE REGISTER

Rule: **no public claim exists without evidence.** A "public claim" is any statement
shown to a member or visitor (web page, email, app, API, Filament-editable text) that
asserts a licence, certification, audit, security level, uptime, payout speed or
similar fact.

Mechanism in the code base:

- Licence and similar badges are produced ONLY by `ComplianceBadgeService`, which
  returns a badge only when a valid, non-revoked row in `compliance_attestations`
  backs it.
- `MarketingClaimGuard` scans text against `config/player_experience.php`
  `compliance.forbidden_claims`.
- `tests/Architecture/ForbiddenClaimsTest.php` scans `app/`, `resources/views`, `lang/`
  and `config/` for forbidden phrases.

This register is the human side: who is accountable for each claim and when it must be
re-checked.

## How to use

1. Before publishing a claim, add one row below.
2. Attach the evidence document and enter its hash (same value stored in the
   attestation row, where one exists).
3. Set an expiry. On the expiry date the claim is removed or re-verified. An expired
   row means the claim must come down.
4. Revoke the attestation in the admin panel the same day evidence is withdrawn.

## Register

| ID | Claim text (exact words shown to the public) | Where shown | Evidence document | Document hash (SHA-256) | Issuer / authority | Reference no. | Valid from | **Expiry** | Owner | Last verified | Attestation id | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| C-001 | `<exact wording>` | `<page / API / email>` | `<file name and storage location>` | `<hash>` | `<authority>` | `<ref>` | `<date>` | `<date>` | `<name>` | `<date>` | `<id or n/a>` | active / expired / revoked |
| C-002 | | | | | | | | | | | | |
| C-003 | | | | | | | | | | | | |

## Claim types that need special care

| Type | Evidence that is acceptable | Not acceptable |
|---|---|---|
| Licence ("licensed by ...") | The licence document from the issuing authority, with reference and dates | A logo, a screenshot of someone else's site, a pending application |
| Security audit / certification | The signed report or certificate from the auditor, covering the current version | A vendor's marketing page |
| "Secure" / "100% secure" / "bank-level" wording | None exists. Do not use. | Anything |
| Uptime figure | Monitoring export for the stated period | A target or an estimate |
| Payout speed | Measured data for the stated period | "Usually fast" |
| Fairness / RNG | Independent test report | Internal statement |
| Age policy (18+) / responsible gaming notice | Always allowed; it is a notice, not a credential | n/a |

## Review cadence

- Monthly: owner re-reads each active row.
- 30 days before expiry: owner renews evidence or schedules removal.
- On any release that edits public wording: compliance owner checks the register and
  `ForbiddenClaimsTest` is green.

## Removal record

| ID | Removed on | By | Reason |
|---|---|---|---|
| | | | |
