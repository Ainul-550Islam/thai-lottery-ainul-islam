# Quarantined frontend code (C2)

The files here are **not part of the build** and are **not loaded by any page**.
They are kept deliberately rather than deleted.

## `components/*.tsx` — non-functional React mockups

Twelve React components that were Vite entries but had no `@vite()` reference
anywhere in `resources/views`, so no page ever mounted them.

They are mockups, not implementations:

- account data is hardcoded (`memberId: 'TL-894120'`, `cashBalance: 5450.00`);
- money handlers are `alert()` stubs — `handleTransfer()` prints "transfer
  executed" without calling an endpoint.

Mounting one of these on a real page would show a member fabricated balances
and fake success messages for real-money operations. Do not re-add any of them
to `vite.config.js` until its data layer is actually implemented.

## Why kept instead of deleted

The visual design is substantial real work (~3,000 lines across the twelve).
Deleting it is irreversible; quarantining it is not, and it removes the hazard
just as effectively because these files are no longer in the build.
