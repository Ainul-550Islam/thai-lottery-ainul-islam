# Part80 — complete account-balance projection before journal writes

## Application change

`LedgerPostingService` now computes the entire ordered account-balance plan before its first account update or journal insert. The new `LedgerAccountBalanceProjector` is pure: it reads the admitted locked account models, performs exact `Money` arithmetic and returns one checked `Money` balance per draft line. It never queries a database, saves a model, mutates an input account or owns a transaction.

The service still acquires the canonical financial-transaction root, validates the draft and Part79 identity, locks admitted accounts in the same ascending order and refuses an existing journal before projection. The original transaction/account/journal admission ordering remains. Every projected value is based on the locked current facts. No absent-journal range lock, global latch or additional connection is introduced.

Both account `current_balance` and journal `balance_after` are signed DECIMAL(24,2): their inclusive limits are -9999999999999999999999.99 and +9999999999999999999999.99. The projector checks the starting current value and every intermediate account balance against that existing storage boundary. A refusal uses `ledger_account_balance_out_of_range` with safe account ID, normalised zero-based line index, currency and `current`/`projected` phase; the raw balance and draft payload are not logged by this domain error.

All five account categories retain their debit/credit normal-balance direction. Negative balances remain permitted for ledger accounts; this is not a wallet floor policy. Opposite sides on one account carry forward its earlier projected value. A final zero-net change does not excuse an overflowing intermediate line. No line is merged, dropped, reordered or silently clipped. The write loop persists the checked values instead of recalculating them during writes.

## Failure being prevented

Previously a valid balanced debit A 10.00 / credit B 10.00 could update A and insert its debit before B's cached balance update exceeded the DECIMAL limit. A caller that catches the later native SQL exception and commits can preserve a one-sided journal. Checking each line immediately before its own write is still too late to protect an earlier leg.

The new whole-plan pass completes before any write. Root and caller-savepoint regressions intentionally catch posting errors and reach the caller's real commit before assertions. They prove exact financial-table snapshots, supplied transaction attributes, caller depth and physical PDO state are preserved, while an unrelated caller marker can commit. Query logs around the posting refusal contain no DML.

## Verification

The pure projector class has 52 cases: all five categories and both sides in THB/BDT; inclusive positive/negative boundaries and a one-cent crossing; earlier projected-value carry; transient overflow hidden by final-net cancellation; and refusal of corrupt already-out-of-range current values without repair. Inputs remain unmodified.

The posting feature class has 30 cases: 16 signed first/later-line overflow refusals across THB/BDT and root/savepoint depth; ten valid same-account opposite-side postings across all categories; two legacy/explicit-null wallet snapshot modes; and two native exact signed-boundary commits. Existing wallet snapshot overrides are unchanged and remain distinct from the projected account cache.

SQLite cannot faithfully persist a 22-integer-digit decimal through its numeric affinity. Refusal fixtures therefore bind the exact synthetic starting decimal bytes as BLOB storage solely in that test database. They prove the preflight and no-write behavior, not native DECIMAL persistence. The two exact-boundary persistence cases explicitly require MySQL/MariaDB and are counted as two new SQLite-only platform skips, not concealed as missing prerequisites. Pure unit cases independently exercise exact 22-digit arithmetic. Disposable MariaDB executes all 82 new cases with no skips and genuinely commits both exact inclusive boundaries.

Audited shadow controls compare whole-plan projection with deferred per-line projection for both signs on SQLite/MariaDB. Further native controls run the exact original Part79 posting service from its unchanged cumulative ZIP. Each mutation has a matching healthy control. The original native baseline catches SQLSTATE 22003; the delayed projection catches its later domain refusal. Independent post-process database reads verify the first synthetic debit really committed with journal imbalance 10.00 when preflight is absent/late, while healthy whole-plan admission writes zero journal rows. The caller marker commits in every control. Only disposable synthetic books are involved; no provider money is moved.

The native workflow adds an 82-case zero-error/failure/skip gate and retained artifact. Previous identity, observer and current-read gates remain. Complete current-source suite counts, hashes, source-file isolation, retained concurrency witnesses and all qualified controls are recorded in the Part80 acceptance evidence. Workflow parsing is local, not a remote CI execution claim.

## Explicit limits

The caller still owns its transaction and savepoints. The service does not start, commit, roll back or repair one. This complete account-balance preflight does not make every possible storage failure safe to catch and commit: entry amount-column precision, supplied wallet snapshot overrides, metadata encoding, foreign keys, model hooks and other late storage exceptions remain separate concerns. The owning operation must still roll back on such failures. A no-write refusal from this low-level posting method also does not undo wallet or other changes the caller made earlier: a failed money operation must still be rolled back as a whole by its owner.

No schema widening, migration, historical repair, balance rounding, new money authorization, provider credentials, external transfer, populated-production restore or worldwide legal certification is added. Locked Vite/Rust prerequisites are built genuinely before broad testing; generated dependencies may be removed only after acceptance, with source and exact historical authorities retained.
