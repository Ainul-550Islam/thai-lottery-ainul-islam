# Part68 — current member eligibility before new conversion authority

## Application change

`ConversionPolicyGuard` now delegates member authorization to `ConversionMemberEligibility`. Both real quote issuance and real conversion execution already call that policy inside their owner-serialized SQL transaction. The new component uses current locking reads instead of a fresh model backed by an old consistent-read snapshot. A second ordinary SELECT after waiting for `users ... FOR UPDATE` does not refresh a REPEATABLE READ snapshot.

The member gate requires an exact raw `active` account status. Missing, soft-deleted, suspended, banned, pending, inactive and malformed statuses cannot grant new authority. Raw persisted values are checked without trusting enum casts or caller-loaded principal relations.

When the configured exclusion gate is enabled, the component checks current canonical Active self-exclusions and the legacy responsible-gaming row. An active canonical pronouncement still gates if its legacy projection is missing or cleared. Future `self_excluded_until` **or** `cool_off_until` blocks the conversion lane. Soft-deleting a still-live legacy safety row does not cancel its protection. Malformed non-null safety timestamps fail closed. Null and lapsed legacy windows do not permanently exclude the member. Canonical semantics remain Active and before `ends_at`; this does not activate pending requests, shorten an exclusion or repair historical projections.

When identity is required, the component reads actual non-deleted KYC documents. It preserves the any-usable-verified-document rule and support for verified documents with no declared expiry. A document can grant identity only when its raw status is exactly `verified` and its declared expiry, if present, is a valid stored-second timestamp strictly in the future. Expired, exact-boundary, malformed, overflow-normalized, revoked and soft-deleted documents cannot create this authority. Other unverified documents do not override a genuinely live verified document. This is conversion eligibility based on stored evidence, not a claim that a synthetic test document establishes a real person's identity or satisfies every country's compliance rules.

The optional verified-mobile gate reads current `phone_verified_at` from the current User row. Removing that value prevents new authorization when the existing configuration requires it. Its historical non-null verification contract is retained; this change is not a new telecommunications identity protocol. Optional configuration switches retain their named meaning. None can relax the mandatory active-account gate.

## Transaction, ordering and bounded hydration

`assertAllowedForExecution()` requires both Laravel's transaction counter and a real PDO transaction before any decisive read. A stale framework counter or a raw PDO transaction alone is insufficient. Transactional `assertAllowed()` delegates to that method; out-of-transaction evaluation remains a nonlocking advisory check on the write connection. Advisory allowance is neither a reservation nor permission to execute without rechecking.

Execution holds its User mutex first, then reads canonical exclusions, the legacy safety row and KYC evidence using `FOR UPDATE`, before the existing wallet-lock protocol. Canonical exclusion activation uses compatible User-first ordering. The same transaction retains fact locks through the quote/money commit. No separate transaction releases eligibility before money movement. The existing second conversion policy check remains in place.

Canonical exclusion and document hydration uses ordered ID pages of at most 250 selected rows, including when a healthy document is beyond the first page. This bounds each hydrated page, not total history scanning, all SQL work, lock footprint or production latency. No index/migration, backfill, evidence relabeling or historical lifecycle rewrite was added.

## Original replay is not new authority

The exact idempotent original conversion replay still happens before member policy. It still requires the existing owner, request fingerprint and settled original accounting proof. Revoking account standing, exclusion, KYC and mobile facts does not post a second transfer or require a new quote for that original observation. This does not bypass HTTP authentication, restore deleted accounts, loosen reversal operator permissions or permit a changed request/key identity to move money.

Quote issuance remains a nonbinding offer and never reserves funds. Execution still rechecks policy, exact rolling allowance, quote/pricing identity and the existing locked financial protocol. A released quote slot does not restore monetary allowance. Refund/recovery admission, original accounting integrity and operator reversal authority remain independent gates.

## Executable qualification

The dedicated feature suite exercises both quote and conversion refusal, original quote preservation, unchanged financial paper, raw status refusal, expiry and exact-second boundaries, malformed evidence, revoked/deleted KYC, canonical projection drift, cooling-off, deleted safety rows, late-page healthy evidence, stale loaded models, optional configuration and actual/framework transaction divergence. Strict native SQL rejects impossible date writes at its storage boundary; SQLite separately proves the guard rejects persisted malformed date fixtures.

Ten native profiles establish two independent old REPEATABLE READ snapshots **before** the parent mutation. The parent then holds the real User lock and changes suspension, canonical exclusion, legacy exclusion, cooling-off, KYC rejection, KYC soft deletion, document expiry or mobile verification. Information-schema evidence must show both child connections waiting on that parent. After parent commit, one real child attempts a new quote and the other a new conversion. Both must refuse each of the eight revocations without changing money or consuming original offers. A ninth profile restores an old suspended member and proves current positive authority; a tenth proves two original exact replays remain observational after all member gates are revoked.

The retained CI workflow requires the exact 47-case dedicated native selection, ten genuine two-child waits, nine healthy old snapshot pairs and one suspended old snapshot pair. Local parsing/compilation is distinct from a remote CI execution. Full acceptance results and qualified omission controls are recorded in `evidence/acceptance-part-68.json` and `evidence/runtime-part-68.txt`; suites overlap and must not be summed.

## Qualification boundary

This change hardens member facts on the tested local owner-serialized InnoDB REPEATABLE READ quote/conversion paths. It does not certify every KYC/account/exclusion writer, arbitrary privileged SQL, all isolation modes, other money lanes, distributed failover, global legal access or custom DST accounting timezones. Ordinary wallet/balance advisory reads and all other application subsystems are not newly represented as current-read proofs by this component. Existing financial locking and original accounting tests remain separately required.

Provider and merchant credentials remain owner-supplied later. No live capture, payout, conversion across currencies, refund, remote provider operation, provider approval or worldwide licensing is inferred from application tests. No provider I/O occurs inside the eligibility transaction.
