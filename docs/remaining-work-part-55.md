# Part55 completion and remaining release work

## Implemented in the application

- A separate staff-confirmed Stripe captured-funding recovery action that can actually credit the existing wallet through canonical deposit accounting, not just display a session or change a paid label.
- Authenticated fixed-host GETs for the owned original session, linked PaymentIntent, linked Charge and charge-scoped refund list. Exact original gross/currency/mode/identity and full captured amounts are required; partial, unpaid, refunded, disputed, pending-refund and unsupported Connect evidence hold.
- Default-off server flag and dedicated current active-staff permission, not automatically granted to normal admins. Fresh checks before custody and before financial application.
- Independently committed encrypted observation history, permanent per-lane account-scoped intent/charge ownership, original-fenced receipt markers and proved SQL-only canonical replay without double credit.
- Current player/wallet/responsible-gaming admission remains mandatory for new credit. Held observed evidence older than five minutes needs fresh provider reads; a new receipt is appended without erasing the old one.
- Receipt-aware provider reconciliation without fabricating a webhook or masking an existing Applied webhook's later drift observation.
- Focused binding/fault/admission/authorization/UI coverage and a genuine native two-child wallet-lock race with both four-GET response arrivals forced before receipt publication.

## Activation work still required from the operator

1. Add approved merchant/account credentials and confirm processor permission for the intended lottery/payment business. No account or live credential was fabricated.
2. Back up the deployment and rehearse the additive schema migration/restore on a nonproduction copy; retain the application encryption key and receipt history. No production migration was run.
3. Grant `reconcile stripe deposit settlements` deliberately to approved active staff; configure production panel authentication, MFA, audit access and incident procedures.
4. Enable `STRIPE_DEPOSIT_SETTLEMENT_ENABLED` only in the approved deployment. Original account scope/secret/configuration/application URL bindings must still agree with the original checkout.
5. Monitor holds, failed reads, stale observations, receipt/application failures, actual refunds/disputes and accounting drift. Captured funding does not prove merchant bank payout availability or irrevocable finality.

## Still separate or unfinished

- Provider-confirmed negative/cancellation/failure release. Empty session searches, local timeout, expired checkout or administrative review still cannot free an uncertain original reservation or permit resend.
- Real provider sandbox/live integration certification; merchant-balance/statement/payout-bank reconciliation, fee settlement, broader provider/account migration and approved credential rotation.
- Refund execution, chargebacks/dispute workflows, partial capture/partial settlement/refunds, other providers' settlement protocols and Connect-specific money movement.
- Automatic recovery queues or customer-triggered credit application, advanced operator case management, large-volume operational certification, browser/load/pentest, disaster recovery and production observability.
- Cross-wallet duplicate-capture-owner race certification, universal callback/manual-writer ownership closure and globally synchronized permission/KYC/AML/age/sanctions/country writers. The new intent/charge uniqueness applies to this scoped lane, not every legacy ingress.
- Country-specific lottery/payment/data/tax approvals. Worldwide architecture is not legal permission to operate everywhere.

## Delivery

Full sequential source starts File1061. All supplied bodies are complete; prior Part54 artifacts are immutable. Current acceptance totals, discovery equality, overlapping native/default profiles, explicit skips and intended isolated omission failures are in the supplied evidence, not inferred from earlier runs. Earlier fixture or command diagnostics are retained as diagnostics. No claim of live funds movement, remote CI execution or worldwide100% completion is made.
