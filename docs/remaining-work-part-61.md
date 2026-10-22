# Remaining work after Part61

## Completed in this increment

- New withdrawal requests and new owned reservations now use exact source-backed refund exposure admission across selected wallets of the original player/currency.
- The new admission is inside a real transaction and current selected-wallet/player serialization. Pending or unproved recovery custody cannot silently authorize new request or reservation rows.
- Existing request replay, existing owned settlement, rejection/release and reconciliation remain separate from new authority. There is no generic debit veto.
- The member API keeps first request and initial hold atomic, retains the player/source lock through the hold, rolls back both on hold failure, and no longer creates another initial hold on exact replay.
- The member exposure response is a safe HTTP 422 `refund_recovery_required`.
- Existing wagering exposure admission delegates to the same retained exact proof; it has not been disabled.
- Full bodies, current-source regression evidence, native lock witnesses, isolated omission failures and a cumulative source archive accompany this delivery. Read `evidence/acceptance-part-61.json` for the actual tested scope and overlapping counts.

## Application work not asserted complete by this increment

1. Audit and, where necessary, migrate the remaining legacy generic API-hold-to-canonical-reservation handoff. Making the initial hold atomic and replay-safe does not by itself turn it into approved reservation ownership. Preserve held-fund floors and existing externally paid obligations during any such migration.
2. Extend explicit source-risk policies to other independently authorized economic actions only after defining their obligation boundaries. Do not copy the new guard onto generic debits, already-owned settlement or release; those must remain able to account for existing obligations.
3. A known custody source is not a certificate that arbitrary writers, legacy SQL, every provider, every partial refund, or every dispute liability have all been discovered. Such global clearance remains a separate proof problem.
4. Preserve and test the original-source, capture, currency, receipt-history and complete financial-closure proof when extending support to additional providers or liability types. Never clear debt using age, a feature switch, a staff label or a synthetic completion row.

## Operational inputs retained for deployment

Production merchant/provider details will be supplied later as the user directed. The application changes here do not wait for those credentials, but the local tests do not create a live transfer or prove merchant authorization. Configure production secrets outside source archives, complete provider-specific activation and controlled reconciliation, exercise a populated backup/restore and execute the remote CI environment before production operation.

A multi-country application still requires the applicable country-specific gambling, payout, KYC/AML, currency and privacy permissions. Local code acceptance is not worldwide legal approval. Keep unauthorized production transfers disabled rather than manufacturing a success record.

## Continuation discipline

Part61 begins at File1220. The delivery registry gives its last file, cumulative path count and exact next file. Earlier numbered bodies, archives and immutable Part60 primaries remain historical records; current cumulative source updates do not overwrite their bytes. Continue with complete source bodies rather than excerpts or progress-only output.
