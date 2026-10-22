# Part71 — completed reversal transaction-boundary scope

Reversal execution and verified existing-reversal lookup now reject divergent current Laravel/write-PDO state before preliminary routing SQL and recheck before opening/joining a transaction or savepoint. Healthy standalone and nested reversal, proved read-only replay, walletless system journals, canonical mirrors and caller atomicity remain supported. Entry admission does not take ownership or silently repair a lost transaction.

The exact completed-run metrics are recorded in `evidence/acceptance-part-71.json` and `evidence/runtime-part-71.txt`; the design, test matrix and limits are in `reversal-transaction-boundary-part-71.md`. Prior accepted source case IDs are retained and rerun. This completed scope is not a statement that every remaining application `DB::transaction()` wrapper has adopted the helper.

Current counter/PDO agreement cannot attest historical transaction identity or recover previously committed/rolled-back facts. Advisory reversal eligibility, uncontrolled writers, status-only and other upstream wrappers, deployment/restore qualification and applicable-country/provider authorization have distinct responsibilities. No historical adoption, live transfer or worldwide operational approval is fabricated. Provider/merchant details remain owner-supplied later as requested.
