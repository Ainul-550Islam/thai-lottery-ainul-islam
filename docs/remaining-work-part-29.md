# Part29 — shared withdrawal-reference ownership and remaining work

Date: 2026-10-15. Continuation File413–442; next File443.
Base repository: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Prior delivered authority: Full-Code-All-333-Files.zip, SHA-256 `c92e054c986fbf0466c95f898f0d060535bdffb049b72d0d63ff40b850a8b121`.

## Actual application changes

The Part28 shared-reference limitation is addressed for withdrawal dispatch, accepted withdrawal callbacks, canonical completion and independent human reconciliation. These paths now consult the same SQL owner for a canonical provider, server-owned account scope and exact provider reference. One identity cannot pay or release two different withdrawal obligations, including across different wallets. Canonical `manual` aliases `bank_transfer`; currencies do not create independent ownership of the same external transfer.

The new `withdrawal_provider_references` table has unique identity and withdrawal keys, a nullable one-time Payment association, provider/account labels and reference hashes. It stores no raw reference or private destination. Binding validates existing financial/Payment facts, stored account context, historical withdrawal and Payment rows including soft-deleted history, and the previous Part28 reconciliation index. A historical own Payment is not hidden simply because its gateway is incompatible. There is no speculative backfill, automatic deduplication, owner reassignment or metadata rewrite.

A valid known reference on an uncertain acknowledgement is now reserved and retained in the withdrawal, Payment and durable claim. That preserves information; it does **not** imply completion or rejection. Funds stay held and the claim cannot be resent, expired, reset or taken over. An invalid uncertain reference is discarded rather than made authoritative. Binding conflicts roll back local finalization; an external invocation may already have happened, so a conflict is not permission to resend.

New requests strip reserved caller metadata and capture a server-owned `outbound_provider_account`. Dispatch passes server-owned idempotency/account context after caller options. The account label is not a secret, credential, beneficiary selector, routing permission or merchant authorization. A changed/malformed account configuration fails closed for a recorded obligation instead of silently reinterpreting its original scope. The current architecture provides one active configured account per provider, not a multi-account credential/router product.

The human reconciliation private destination adapter also accepts the actual browser request shape: `payout_details.method` must match the canonical method, and supported mobile/crypto destinations may use the browser's `account_number` field. Unsupported fields/destinations still require private adapter review. This is domain/service compatibility evidence, not a browser end-to-end run or real provider payment.

Part28 private encrypted artifacts, independent maker/checker authorization, immutable facts, original-destination inspection and canonical completion/rejection remain. Part27 non-expiring dispatch claims remain. Member-to-member transfers remain fail-closed; approved own-wallet conversions, fee rules and locked execution are not loosened.

## Deployment and account compatibility

Apply `2026_10_15_000200_create_withdrawal_provider_references_table.php` through an approved, backed-up release process before enabling these application sources. It was migrated only in disposable test databases here. A populated ownership table deliberately refuses destructive migration rollback. Do not delete financial identity rows to make rollback succeed.

`config/payment.php` supports these optional non-secret labels:

- `PAYMENT_BANK_TRANSFER_ACCOUNT_SCOPE`
- `PAYMENT_STRIPE_ACCOUNT_SCOPE`
- `PAYMENT_BKASH_ACCOUNT_SCOPE`
- `PAYMENT_NAGAD_ACCOUNT_SCOPE`
- `PAYMENT_CRYPTO_ACCOUNT_SCOPE`

Defaults are `legacy-primary`, preserving unscoped historical interpretation. Labels are 1–80 ASCII characters, beginning with an alphanumeric character and continuing with alphanumerics, dot, underscore, colon or hyphen. Null, empty and malformed values fail closed. Do not rename an account label to make blocked historical rows pass, reuse a label for a different actual merchant account, or change credentials/routing independently without controlled migration and evidence review. No such migration is provided or authorized here.

The empty new table is intentional. Existing known references are checked against history and indexed lazily only through valid application transactions. Conflicting historical owners are refused, not arbitrarily ranked or repaired. The legacy Part28 reference key remains relevant for legacy-primary; new scoped cases rely on the common index.

## Read-only operator inspection

With authorized server access:

```bash
php artisan payments:withdrawal-reference-audit --withdrawal=123
php artisan payments:withdrawal-reference-audit --withdrawal=EXACT_INTERNAL_REFERENCE
php artisan payments:withdrawal-reference-audit --attention
```

An exact selector accepts ID, internal reference or UUID, including soft-deleted history. `--attention` scans at most the oldest 200 rows; it is not a full-database certificate or a paginated repair tool. Do not supply both selector modes. Output contains redacted IDs/status/provider/account, static issue codes and `automatic_resend_allowed=false`; no raw reference, destination, claim key/token or artifact content. It performs no bind, destination decryption, money write or gateway invocation. A successful command means inspection ran, not that all inspected items are clear. CLI access remains an operations/server authorization boundary, not a new public endpoint.

Use Part28's private evidence workflow for genuinely uncertain rows with one coherent Payment and a supported original destination. Review account mismatches and historical conflicts through authorized evidence and migration procedures; this command never clears them.

## Final executed verification

| Check | Result |
|---|---|
| Full default PHPUnit on final application sources | 2751 tests / 131517 assertions; exit0, zero failures/errors; 42 explicit skips |
| Entire Payment feature directory, MariaDB | 276 tests / 1084 assertions PASS |
| SQL race profile, all cases | 3 repetitions × 27 tests / 600 assertions PASS; no skips |
| Actual contention witnesses | 23 per iteration, including 2 new shared-reference races with distinct wallets |
| Outbound process-death/replacement recovery | 3 × 2 tests / 61 assertions PASS; 2 actual SIGKILL witnesses per run |
| Offline Python guards | 86 PASS |
| PHP syntax / focused Pint | 1699 source files / 20 changed PHP files PASS |
| Rust tests / locked release build | 27 tests PASS / PASS |
| Frontend production build | PASS; build output not packaged |
| Static source preflight / generated inventory / whitespace | PASS; source presence/hashes are not runtime approval |

The reference-race witness requires two owned child connections **directly** blocked on the parent's actual shared `identity_key` unique-index lock, not an indirect child chain or a common wallet lock. The owner callback versus foreign human-review case completes only its owner. The dispatch-response versus foreign callback case pauses the synthetic driver after committed claim/Payment and one fsynced synthetic acceptance, then races the response finalization against the callback. Its witness includes that pre-barrier acceptance marker; the owner remains pending, the foreign callback is refused and both reservations remain intact. Exactly one synthetic invocation is asserted. No real provider is contacted.

Early SQL attempts exposed a Payment index/gap lock before fresh dispatch preparation reached the reference binder. The test was corrected to pause at the actual post-acceptance boundary, not to weaken the direct-parent wait criterion. An early full regression found a stale source-hash inventory; regenerated hashes fixed it. The final audit fixture also verifies that redacted projection still loads the identity facts required to recognize its coherent Payment without a false historical conflict. Final full/default and MariaDB Payment regressions were rerun after those fixes.

The default 42 skips are explicit server-only profiles plus the pre-existing settlement simulation: SQL worker recovery9, conversion9, Redis7, settlement4, callbacks4, dispatch2, dispatch-recovery2, reconciliation2, shared-reference2, simulation1. Current all-race runs cover the race profiles without skips; current dispatch recovery is refreshed. SQL worker recovery and Redis hard restart were **not** rerun in this part; Part28 retains their historical evidence. Skips are not represented as passed tests.

Complete current all-race JSON is a separate delivered body. Complete current dispatch-recovery JSON, static-preflight JSON and runtime logs are embedded verbatim in `evidence/runtime-part-29.txt`; their scratch files are not additional sequential bodies. Passive root inventory reports point to these separate runs but do not certify them.

## What is still not all done

1. **Live operation and country authorization:** no approved operator, merchant account, provider/callback contract, jurisdiction, bank beneficiary or actual transfer authorization has been supplied. No provider activation, real transfer, production migration or deployment occurred. Reference-site brand images are not approvals.
2. **Worldwide scope:** current configured currency architecture supports THB/USD/BDT; it does not claim every currency or legal availability in every country. Country/provider gates, KYC/AML, tax, licensing, geolocation and operator economics require jurisdiction-specific approved policy and integrations. No currency or legal authorization is invented.
3. **Provider routing/authenticity:** account labels are identity context only. Actual credential routing, merchant credential rotation, real provider idempotency/statement guarantees and callback account authentication need approved adapters and provider-specific evidence. Exactly-once external transfer is not certified.
4. **Historical remediation:** ambiguous legacy rows, missing/coherent-Payment problems, stale account labels, conflicting raw references, unsupported destinations and pre-index duplicates require controlled evidence/import/migration review. There is no bulk dedup or automatic repair. Preserve holds on uncertainty.
5. **Identity coverage:** this common index covers withdrawal paths listed above, not every polymorphic Payment, inbound deposit or prize `PayoutTransferService` identity. Audit is bounded, not exhaustive certification. Further cross-domain ownership integration must respect provider-specific reference namespaces.
6. **Operations:** hosted CI, multi-host deployment, production queue/Redis behavior, restore drills, credential management, private-artifact retention/quota and monitoring still need authorized environment acceptance. Part28 evidence-storage retention and original-destination review requirements continue.
7. **Product coverage:** all requested pages/routes/worldwide workflows are not declared complete merely because the regression passes; passive page slots remain honest about absent authoritative mappings. Continue remaining application work from File443 without resetting the delivered source set.

This part delivers substantive application protections and full sources, not an unverified universal live-money or all-pages-complete claim.
