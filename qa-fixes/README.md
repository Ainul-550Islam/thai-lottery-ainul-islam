# Re-apply order (after a sandbox reset)

Run from /home/user, with `composer install --no-scripts` and `npm ci && npm run build` done first:

1. `python3 qa-fixes/apply_fixes.py` - GloTicket price accessor; P0P1P2 helpers, KYC, agent wallets, commission enum.
2. `python3 qa-fixes/apply_decisions.py` - placeBulkBet wrapper; isSelfExcluded wrapper; exclusion via request()+activate(); p0_03 Stripe fixture; calculator test rewrite.
3. `python3 qa-fixes/apply_round3.py` - p0_03 deposit confirmed through PaymentWebhookService; supported market codes.
4. `python3 qa-fixes/apply_round4.py` - number-limit rows for bulk fixtures; p0_03 withdrawal via request path; FW test 04 limits.
5. `python3 qa-fixes/apply_round5.py` - moves the two bulk-bet tests into tests/Feature/Betting/BulkBetPlacementTest.php (skipped if the file already exists).
6. Then `sed` for `WithdrawalStatus::COMPLETED`/`PENDING` -> `Completed`/`Pending` in the two suites, and the `IDEM-WD-HUB-0000001` key (see QA_REPORT Update 17).
7. Copy `templates/BulkBetPlacementResult.php` to `app/DTOs/Betting/` if missing. Copy `templates/BulkBetPlacementTest.php` to `tests/Feature/Betting/` if missing.

Scripts are not idempotent for round 5 after a partial run. Check the state first.

7. `python3 qa-fixes/apply_round6.py` - full fixture for the GLO prize-claim approval test in `RealMoneyBusinessReadinessTest.php` (QA_REPORT Update 18). Run after step 1.
