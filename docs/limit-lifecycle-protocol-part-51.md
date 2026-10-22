# Responsible-gaming limit lifecycle and original-request ownership — Part51

Date: 2026-10-17. Continuation Files963–983; next File984. This is a local application-source change, not a production launch, universal writer certification or live-money approval. Apply the cumulative overlay to retained base commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, then install its locked dependencies and apply migrations only to an authorized target. Acceptance used disposable SQLite and socket-only MariaDB stores, never customer funds.

## Actual application changes

The canonical version service now separates historical request replay from desired-current-state choice. Fresh protective choices cancel pending increases and replace every active head in the same user/type/currency namespace. Delayed increases retain the currently binding ceiling, a minimum twenty-four-hour delay and any later explicitly requested start. The legacy summary setter no longer swallows version failures, writes the summary before synchronizing, hardcodes THB, or reports a pending requested amount as immediately effective. Its selected-currency version writes, binding summary and audit share one transaction, the current player mutex and a locked legacy summary row.

The existing summary API returns `currency` and `pending_limits` separately from binding amounts. The explicit declaration API supports an optional validated request key. Expected responsible-gaming and self-exclusion refusals produce fixed safe HTTP422 JSON or a web limits error; raw exception messages are not sent to the customer. Existing routes/authentication are retained. No payment credential, provider, merchant, jurisdiction or production configuration is activated.

## Historical requests versus current choices

`pronounce(data)` represents a historical request. Canonical input binds user, type, selected currency, exact two-decimal amount and the originally requested start/end Unix-second windows. An optional caller key is scoped to the player globally, not only the type or currency. Reusing it with a changed payload fails. Exact replay returns its original row, including Cancelled/Replaced/Expired state, and cannot restore that row to Active or recreate a cancelled increase. Omitted keys retain deterministic historical semantics. Callers needing independent identical new requests supply new keys rather than treating historical replay as current desire.

`choose(data)` and convenience setters represent desired current state, not durable client-request identity or an unknown-outcome replay contract. They reject explicit caller keys; use `pronounce` for those. With default windows an equal sole active head or matching sole pending desire is reused without a write. Fresh choices bind the current heads, statuses, latest namespace tail and canonical input. Consequently a real intervening cycle may legitimately choose a previous lower number again, without reopening its earlier terminal request. Retrying a choice after intervening state is a new decision, not certified recovery of an unknown earlier result.

## Source-owned facts, not historical enrollment

The new ownership table has one unique restricted version foreign key and one unique request identity. It records original payload, immutable persisted terms, the full original active basis, backend timezone, exact activation epoch and nullable expiry epoch. The creating source transaction writes ownership with the new version. No backfill adopts old rows. Ownerless old pending versions are held rather than activated; ownerless active versions are not automatically expired. Their operators need a separately authorized reconciliation/migration decision, not a guessed identity.

Immutable terms include version identity, player, type, currency, amount, key and effective date fields. The active basis binds every active head and its terms in stable order. Transactional permission reads verify owned active terms. Changed owned terms fail closed. The proof assumes trusted application/schema writers and retained ownership; arbitrary trusted SQL, deletion of ownership, operator compromise and every legacy/third-party writer are not certified by these hashes.

## Clock and cooldown

Requested instants normalize to Unix seconds; a future boundary with fractional seconds rounds safely for the twenty-four-hour minimum. A later requested start is preserved; an already ended or impossible effective window is refused. Persisted wall-clock fields use the configured backend timezone, which in the tested application is Asia/Bangkok, while owned transitions retain exact Unix epochs. Ordinary non-UTC deployment is supported. A later backend-zone change must not reinterpret old owned records: the original zone is checked and an explicit original-instant migration is required before those records can be reused or swept.

## Current locking and lifecycle transitions

Supported limit writers use the same current-player transaction mutex as the Part50 paid-purchase gate. Sweep candidate selection is only a hint. Each sweep transaction first takes the hinted player's mutex, then locks the version and verifies that its actual player still matches. This avoids establishing an ordinary stale transactional hint snapshot before a MariaDB mutex wait. A changed player hint is held, not transitioned.

Activation requires original source ownership and unchanged terms, a due exact start, an unexpired exact end, the unchanged complete active basis, exactly one pending namespace and a currently eligible player without legacy exclusion/cool-off or live versioned exclusion/cooling-off. Newly added or changed active heads, pending forks, protection and tampering hold or terminalize unsafe transitions instead of silently loosening limits. Expiry is authorized only by original owned expiry facts, not merely an old mutable timestamp. Cancellation, replacement and replay remain distinct transitions.

## Binding summary contract

`ResponsibleGamingService::setLimits` selects the configured betting currency and writes all submitted non-null numeric choices as one atomic operation. Failure of a later type or an audit rolls back the earlier versions, ownership and summary. Pending increases are exposed as pending; the summary stores the currently binding value. A lower choice takes effect immediately and cancels pending increases. Other currencies retain independent chains. A single legacy summary is not a universal enrollment or authoritative projection for every historical currency/time policy.

## Reproducing acceptance safely

The acceptance record and runtime file enumerate final-source results and their evidence hashes. The evidence archive includes full discovery/JUnit IDs, default shard outputs, selected native results, focused web/API coverage, observed two-child SQL waits, isolated omission controls, dependency gates and build/lint outputs. Default and native profiles overlap and must not be added as a unique-test total. Explicit default skips remain skips except where the selected native profile demonstrably executes their same IDs.

Use the evidence harness only against owned disposable stores and install the repository's pinned Node/npm, locked Composer/npm/Rust dependencies, PHP GD/bcmath/PDO and a native SQL engine before reproduction. Negative controls mutate only an isolated shadow source/store and must fail assertions without infrastructure errors; the accepted application sources remain frozen. Initial timezone/snapshot failures are retained as diagnostics, not counted as passes. The final full matrices and final-source focused results, not earlier partial runs, are authoritative. No browser journey, external provider callback, live settlement or deployment was tested here.
