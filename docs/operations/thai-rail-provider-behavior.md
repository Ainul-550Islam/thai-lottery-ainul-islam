# Thai payment-rail provider safety and deployment notes

These notes describe the code's safety boundary, not evidence that a live
merchant integration or legal authorization has been approved.

## SlipOK duplicate history

The SlipOK adapter sends `log=true` for QR and image verification. According to
[SlipOK's check-slip documentation](https://slipok.com/api-documentation/check-slip/),
this enables branch-level duplicate checking and checks the receiving bank
account linked to the branch; `log=false` does neither. SlipOK's
[error reference](https://slipok.com/api-documentation/error-status-code/) lists
`1012` as a duplicate slip. A duplicate response without transfer details is
recorded as `provider_duplicate` and does not credit a wallet.

**Operational consequences:** configure and verify the branch's receiving-bank
link before enabling this provider. A provider request can be recorded even if
our later validation, local settlement, or network response fails. A repeated
request can therefore be reported as a duplicate and require an operator to
investigate; do not resend or credit merely because a previous response was
lost. Provider history is scoped to its branch and is not proof that a transfer
was unused in another provider, branch, or legacy system. Preserve and reconcile
historical local transaction references before deployment. Provider retention,
privacy, contract terms, and actual merchant behavior require confirmation with
SlipOK; these have not been established by the synthetic tests.

`SLIP_VERIFICATION_AUTO_CREDIT` defaults to `false`. When enabled, the service
still requires successful provider flags, a fresh nonduplicate transfer, exact
amount and currency, a matching receiving bank, an exact receiver name and a
provable four-digit account suffix, an allowed fraud decision, and an eligible
pending deposit. An account mask such as `xxx-x-x3109-x` does **not** prove that
the account ends in 3109: the last digit is hidden, so the slip is rejected
rather than credited. Inspect such cases through the approved operator process;
do not bypass the receiver check.

## Exact money in JSON responses

Slip, TrueMoney voucher and Thai bank payout adapters read amounts from their
actual JSON fields without binary-float conversion. Ambiguous duplicate JSON
object keys are refused. Scientific-notation amounts are not silently truncated
or rounded: they are refused as unusable financial evidence. If a TrueMoney
response contains multiple recognized amount or currency fields, all must be
valid and mutually consistent. Recognized status aliases, error codes and
explicit success flags must also be mutually consistent; malformed or pending
states are not proof of success or rejection. Conflicting or uncertain evidence
leaves the voucher reserved as `redeemed_unconfirmed`, with no wallet credit and
no automatic retry. The normal reconciliation command cannot automatically
credit that uncertain evidence. This conservative policy may hold legitimate
responses for manual investigation if a live provider uses an unrecognized
status or field meaning. The TrueMoney integration is explicitly unofficial and
requires merchant, contractual and legal validation; field meanings and real
provider responses are not proven by synthetic tests.

An unknown or mismatched payout result must retain the withdrawal hold and
remain subject to reconciliation. A bank's production payload, signature
contract, and live settlement behavior still require independent
merchant-contract and end-to-end validation.

## Other payment evidence boundaries

The Nagad compatibility adapter now reads webhook amounts from the exact signed
JSON bytes and refuses duplicate object keys. The synthetic bKash prototype
also refuses duplicate callback keys and reads both authoritative status-query
amounts without binary-float conversion. These are local safety properties,
**not proof that either provider uses this application's HMAC convention**.
The public bKash and Nagad callbacks, their queued-DTO and generic-envelope
bypasses, and their advertised gateway capabilities remain closed. Do not enable
either until its merchant-authenticated checkout, callback and authoritative
query/settlement contract is implemented and independently validated. Earlier
accepted Nagad replay records cannot re-open the callback. Third-party
integration descriptions are not a substitute for the signed merchant contract.
This refusal is temporary and does **not** reconcile or pay existing BDT
withdrawal obligations; those need a separately authorized evidence-backed
operator procedure.

Crypto has no configured provider-specific invoice, authentication or
confirmation contract. It remains unavailable even if an enable flag or local
webhook secret is set. Previously queued crypto DTOs and generic locally signed
HMAC envelopes cannot credit wallets; historical items require controlled
reconciliation rather than re-enabling an unsupported rail.

The legacy provider registry cannot independently advertise a rail or create
an intent: an enabled method and active provider must match a canonical
manager-approved gateway, currency, configured merchant market and direction.
Crypto, crypto-backed aliases, and unverified bKash/Nagad rows remain
unavailable even if inserted in the registry. Old intents remain readable and
cancellable; closing an ingress does not settle old obligations.

The default site currency is a display/default choice, not a substitute for the
actual wallet currency. An authenticated client may request a catalogue for an
owned wallet's THB, USD or BDT currency; the manager evaluates that currency
against the configured merchant-country allowlist. The country remains server
configuration, not proof of where the customer is or of the operator's licence.
Global deposit and withdrawal switches refuse new operations; an existing held
withdrawal can still be cancelled. The legacy manual withdrawal lane is not
independently authenticated evidence of a real payout.
