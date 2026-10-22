# Part87 — stop ledger posting on refused model saves

## Money-writing defect

Eloquent permits a saving/updating/creating listener to veto persistence by returning false. That is an ordinary framework outcome, not an exception. The posting service previously ignored both account->save() and ledgerEntry->save() return values.

An account balance save could therefore be cancelled while both immutable ledger rows were still inserted. The final debit/credit check accepted those balanced rows and the service reported success even though one cached account balance remained at zero rather than 10.00. A surrounding money operation using a normal transaction wrapper would see no exception and could commit the cache/journal disagreement.

A refused ledger-entry save was also ignored. If the first entry was vetoed, the old service continued updating the next account and writing the opposite journal side, only then failing its final arithmetic assertion. This obscured the actual persistence refusal and unnecessarily executed later posting DML.

## Application fix

persistAccountBalance now captures the admitted account ID before invoking model events and requires save() to return exactly true. Otherwise it throws FinancialException with code `ledger_account_write_refused` and safe context `{stage: account_balance_write, ledger_account_id: admitted_id}`.

writeEntry likewise requires its real save() to return exactly true before returning the model. Otherwise it throws `ledger_entry_write_refused` with context `{stage: ledger_entry_write, ledger_account_id: normalised_draft_id}`. Neither error chains a serializer/listener message or raw payload. Context identifiers come from the admitted plan, not a potentially altered refused model.

The service honors the veto. It does not save quietly, remove observers, force persistence, create replacement rows, or bypass model guards. It stops synchronously at the first refused save rather than proceeding to later legs or returning an unsaved entry. Existing thrown exceptions still propagate normally.

Only these two existing private persistence method bodies change. The public post signature and body, constructor, Part83 context assertion, Part84 physical-PDO invocation lease, Part85 input witness, Part86 callback-journal admission, current-reader ownership, initial root/account order, projection, monetary and frozen-payload plans remain unchanged. There is no migration, schema widening, new lock, observer change, transaction begin/commit/rollback, or distributed mechanism in the application fix.

## Atomicity and retry boundary

A refused save is not proof that the whole operation performed no writes. On a later leg, earlier accepted account/journal writes already exist in the caller transaction. Even the first ledger-entry refusal occurs after its account balance update. The service raises an exception so the owner can roll back the whole failed money unit, including earlier wallet work; it does not take over the owner's transaction or automatically repair history.

The ordinary tests prove root rollback and rollback to an owned savepoint restore the complete financial book. The savepoint owner retains an earlier outer marker. Root rollback correctly removes its staged marker. Six separate disposable committed probes intentionally leave partial state to qualify exactly where the writer stopped and prove caller ownership. Those commits are diagnostics, **not** a recommendation to commit failed accounting.

The tested immediate same-owner retry is deliberately narrow: the first account save was refused before any posting DML, and the listener allows the next attempt. The invocation lease must be released after refusal. A later-leg failure or first-entry refusal is not generally safe to retry without rolling back its failed money unit first.

Requiring a true framework return is not a row-count witness, an authorization policy, a transaction-generation proof or a sandbox for arbitrary listeners. It does not detect every hook that mutates other rows, changes connections, suppresses errors, or returns true without the intended store state. Non-halting saved/updated event return values retain their normal framework semantics. A database failure or arbitrary later model/query hook still requires whole-operation handling.

## Tests and controls

54 new feature cases are explicit:

- 32 ordinary refusal cases: account saving/updating and entry saving/creating, first/last leg, THB/BDT and caller root/savepoint. They assert the exact refusal code/context, one veto, no later posting DML, unchanged caller model/depth/physical transaction, exact staged partial rows/balances, restored original event dispatcher and complete financial-book rollback by the owner.
- 16 allowed/retry cases: no extra listeners, listeners returning true, listeners returning null and first-account refusal followed by an allowed sequential retry, across both currencies and depths. They preserve the ordinary balanced two-row 10.00 posting and expected event counts without leaking listeners to other tests.
- Six clearly named committed probes: first-account saving veto, last-account updating veto and first-entry saving veto, in THB/BDT. These qualify stopping points while leaving ownership to the caller; they do not certify the committed partial book as valid.

Six exact delivered-original Part86 counterfactuals (SQLite/native MariaDB × the three THB committed probes) run the same unmodified tests with reflection-audited loading and independent committed database reads. Old account-veto runs must fail the healthy assertion after silently succeeding with two balanced rows but one stale cached balance. The old first-entry-veto run must fail after a late `ledger_unbalanced_posting`, one credit-only row and both caches advanced. Healthy controls must stop at the refused model save, with the earlier accepted DML left caller-owned exactly as the probe specifies. Expected assertion failures are controls, not failures of the new source acceptance.

All previous test IDs and 112 genuine contention profiles must remain. Complete measured results, overlapping focused selections and source hashes are dedicated evidence/acceptance-part-87.json, not inferred from the planned case count. The native workflow adds an exact 54-unique-case, positive-assertion, zero-skip/error/failure gate and retains its XML. Workflow parsing is local, not a remote CI execution. Root inventory reports remain generator-owned STATIC_ONLY. No provider transfer, populated-production restore, universal legal approval or elimination of all platform defects is claimed.
