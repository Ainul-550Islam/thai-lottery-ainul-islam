# Implemented application work and remaining release gates — Part53

## Implemented now

- Canonical deposit checkout uses committed prepared intent and one fenced automatic dispatch claim, outside all caller SQL transactions, with local encryption capability checked before first dispatch.
- A successful original checkout response has independent encrypted custody before local projection; original SQL-only recovery/replay does not contact a provider again.
- Uncertain or merely dispatched attempts do not expire, auto-resend or release their gross reservation through canonical local cancel/reject/fail transitions.
- Original request options, terms, user/wallet/Payment, account scope and provider-configuration HMAC are bound; dispatch uses the locked original source snapshot.
- Current player/wallet and selected-currency gross deposit admission is rechecked immediately before a first claim. Independently verified funding remains the only credit authority.
- Stripe receives a server-owned opaque `Idempotency-Key` and attempt metadata. The caller's request nonce is not sent as provider identity.
- API initiation requires one consistent stable key across body/standard/legacy headers. Unknown outcomes return an owned hold reference and HTTP409. Existing owner-filtered show reads expose only safe coarse checkout status.
- Post-commit interruption, independent-custody/late-projection failure, recovery, callback-before-projection, changed facts, ambient transaction and real InnoDB same-intent races have explicit test coverage. The acceptance JSON contains actual final run results, not projected totals.
- Native CI wiring includes the new checkout profile with exact non-skipped case and two-child-wait checks. Remote CI itself has not been run here.

## Required before migration or activation

1. Back up and independently verify a nonproduction copy first; review the new ownership table and unique/restricting constraints. This work runs migrations only on a disposable local test store, not production.
2. Deploy all canonical checkout callers with durable root semantics; no surrounding SQL transaction, missing request identity or caller-generated dispatch identity is accepted. Ensure clients persist original keys across lost responses.
3. Keep the Laravel encryption key and encrypted backups under controlled custody. Plan original-account routing, configuration changes and key rotation for outstanding attempts; configuration drift intentionally holds recovery.
4. Preserve private access to existing Payment/Deposit response metadata, redact logs, protect redirect/client-secret fields and set appropriate retention. Encryption of the new independent custody column does not encrypt all legacy projection columns.
5. Alert on dispatching/unknown/response_received states without resetting them. Never introduce a lease, TTL or operator button that silently turns uncertain dispatch back into prepared.
6. Audit all direct/custom state writers and all operator/caller authorization. The canonical lifecycle transition guard does not make arbitrary SQL, model writes, external scripts or every global actor safe.

## Still unfinished application/release work

- Authoritative provider lookup and evidence-bound controlled reconciliation for an outcome without durable successful response. A bare exception or failed DTO is not proof of nonacceptance. In particular, unresolved attempts currently cannot be released just because a local review or failure callback requests a terminal status. Integrating explicit provider-confirmed failure release requires a separate scoped authority, receipt and replay protocol.
- Provider-specific refund, chargeback/dispute, cancellation and partial-settlement protocols; checkout/session creation is not settlement or refund authority. No generic refund is enabled by this work.
- Approved merchant/provider accounts, correct account routing, endpoint/credential setup, reconciliation statements, integration certification and verified real sandbox/provider events. A working adapter and synthetic acceptance are not approval to offer lottery payments. Configured-only or simulated rails do not become live integrations through this orchestration; unsupported PromptPay remains unsupported.
- End-to-end global actor/KYC/AML/sanctions/country/age controls and all-writer permission closure, browser/device coverage, operator recovery UI, production deployment/security review, backup/restore drills, load/pentest and monitoring coverage.
- Country-by-country lottery, payment, consumer protection, tax and data obligations. Worldwide architecture does not establish legal permission for every country or processor.
- Provider/production/live verification and controlled activation. Merchant details were deferred by the user; no account was fabricated or activated and no customer money was moved.

## Acceptance interpretation

Full source bodies and cumulative checksum-verified overlays are delivered sequentially from File1007. Prior Part52 artifacts remain immutable. New evidence reports exact source hashes, default and native overlap, explicit skips, faults, lock waits and intended isolated mutation failures. The old SQLite purchase race diagnostic is retained: an actual SQLite write-lock refusal is not relabeled a domain replay success, and the original assertions execute on InnoDB instead.

This completes the scoped Part53 application implementation and its recorded local acceptance, not the entire real-money platform, all live integrations or worldwide100% readiness. The incomplete items above are concrete release work, not a request to supply credentials before implementation can continue.
