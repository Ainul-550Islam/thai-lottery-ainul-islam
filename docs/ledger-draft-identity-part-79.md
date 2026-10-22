# Part79 — journal draft storage identity preflight

## Implemented behavior

`LedgerPostingService::post` now rejects a repeated `(ledger_account_id, type)` inside one normalised, balanced draft with `ledger_duplicate_entry_identity`. The financial transaction is already the one locked canonical root, so this is exactly the remaining identity in the existing database unique key `(financial_transaction_id, ledger_account_id, type)`.

The check runs after existing structural, currency, positive-money, both-sides and balance validation, but before account lookup/locking, existing-journal observation, account balance changes or ledger inserts. It uses the normalised enum value, not the raw string or object representation. Diagnostics contain only account ID, side and zero-based normalised first/repeated line indexes. Sparse input keys are not reported as line positions.

No duplicate is merged, dropped, reassigned to a different account or made unique using its amount, wallet, description, reference or metadata. Annotation changes do not turn one account/side identity into two. A debit and credit on the same account remain distinct identities. Same-side splits to different accounts remain legal. Exact arithmetic and currency boundaries are unchanged.

The storage guard belongs to the posting service, not the reusable arithmetic validator. `LedgerBalanceValidator::validate` can still normalise and total repeated conceptual account/side lines without merging them. The database unique key is retained as a second line of defence; no migration or historical journal rewrite is introduced.

## Why preflight matters

Before this check, a balanced draft such as debit A 4.00, debit A 6.00, credit B 10.00 could pass arithmetic validation. The posting loop updates each cached account balance before saving that line. The second debit then hits the database unique key, after the first debit and some balance changes already exist in the caller's transaction. If the caller catches that SQL exception and commits, the database's uniqueness guarantee alone does not undo the earlier statements.

The regression deliberately catches any posting exception and reaches an actual caller commit before its assertions. It therefore distinguishes safe domain refusal from a late SQL failure; it cannot pass merely because PHPUnit aborted or test teardown rolled back the transaction. Root and caller-savepoint cases also check physical PDO state, framework depth, unchanged supplied model attributes and complete financial-table snapshots. A query log captured around posting proves duplicate refusal performs neither account/journal queries nor DML.

## Verification contract

The new 25-case feature class has 16 duplicate cases covering both sides, root/savepoint depth, enum/string equivalence, different annotations and sparse nonadjacent input. Four distinct-account split cases cover THB and BDT, committed root posting and caller-savepoint rollback. Two same-account/opposite-side cases preserve that legitimate identity. Three further cases preserve arithmetic-validator behavior, existing unbalanced-error precedence and the real database unique constraint against a raw non-service insert.

Both SQLite and disposable MariaDB execute the new cases. Audited shadow-source omission controls remove only the service guard call. Each side/driver profile has a matching healthy control. Independent post-process database reads verify zero committed journal rows for the healthy guard, versus one debit leg with imbalance 4.00 or two legs with imbalance 6.00 when the guard is omitted and the caller catches the constraint error then commits. The unrelated caller marker commits in either case. These are intentionally invalid synthetic books in disposable databases, not production funding or provider transfers.

Complete-suite counts and the final current-source hashes are recorded in `evidence/acceptance-part-79.json` and `evidence/runtime-part-79.txt`. The native workflow adds a strict 25-case, zero-error/failure/skip gate and retains its report artifact; the previous 18-case observer and 21-case current-read gates remain. This workflow is parsed locally, not claimed to have run remotely.

## Boundaries and operation

This is a low-level writer used inside its caller's existing transaction; it still does not open, commit or roll back a transaction or savepoint itself. This preflight removes the repeated-draft-identity late-constraint path, not every possible storage failure. Other exceptions still require the owning operation to roll back its atomic unit; callers must not treat arbitrary failed posting attempts as universally safe to commit.

Already-persisted journal duplication remains covered by the existing current-read admission check and database constraint. The new draft failure is checked earlier than account-store admission, intentionally treating a repeated line as malformed posting input. Earlier canonical-root and arithmetic exception ordering remains unchanged. Account locks and the Part77/78 observer ownership/current-read protections are not broadened or bypassed. No absent-journal range locks, global posting latch, retries, hidden line aggregation, credential changes, new payout authorization, external transfer, worldwide legal approval or populated-production restore are implied.
