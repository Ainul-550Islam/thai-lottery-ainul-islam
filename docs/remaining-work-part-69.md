# Part69 — completed current conversion wallet eligibility

## Application functionality delivered

New quote/conversion policy now reads current wallet status, lock/deletion state and available funds under the caller transaction. Execution reuses current role identities instead of old plain source lookup/firstOrCreate. This fixes stale refusals for legitimately restored/funded wallets and duplicate destination insertion when a destination was committed while waiting. Source must permit debit; suspended destination still permits credit. A quote is advisory, never a hold. Original exact replay remains observational under the existing original accounting proof.

- Complete default: 4432 cases / 143836 assertions; 225 conditional skips; 319 isolated source files; exact discovery and all previous 4386 IDs retained; zero failures/errors.
- Executed native: 659 cases / 8223 assertions; zero skips/errors/failures; 82 genuine two-child parent waits including fourteen new old-wallet-snapshot profiles.
- Raw native selection has 660 IDs and one SQLite-trigger-only skipped record, separately passed default and not counted as native execution.
- Dedicated overlapping subset: 46 cases / 383 assertions; 32 feature and fourteen native cases; zero skips/errors/failures.
- Twenty qualified expected single-assertion omission failures, zero setup errors/skips. Fourteen native controls retain owner locking and prove both actual old snapshots, genuine parent/two-child waits and all three policy/wallet-guard/engine source paths in all three processes. Thirteen remove the current wallet read; one restores the stale engine identity lookup and firstOrCreate. Five SQLite controls remove actual/framework transaction requirements, policy integration, held-balance subtraction or deleted-wallet protection. A further native contract-only omission proves evaluate remains wallet-nonlocking inside a transaction. Real source bytes were never mutated.
- PHP syntax 2181 files; Pint eight changed PHP files; Python 86; Node 54; Rust 27 locked tests and release build; genuine Vite build; clean Composer and complete npm lock graph.
- Static 35 cases / 1736 assertions; standalone 29 cases / 94 assertions. No new migration; empty SQLite up/down/up is not a populated production restore.

## Separate production qualification

Provider and merchant details remain owner-supplied later; their absence did not defer this implementation. No live provider activation, external transfer, licensing or worldwide legal permission is inferred from local financial tests.

This qualifies tested local owner-serialized InnoDB REPEATABLE READ admission/identity reuse. Arbitrary wallet/identity writers or creators, every isolation mode, engine physical scan/lock ordering, all other financial lanes, production load, distributed failover, custom DST timezones and populated restore remain separate qualification. Query output ordering is not a proof of every engine lock's physical acquisition order. Raw native fixture lifecycle/counter changes are not historical accounting receipts. No old wallet/history was adopted, relabelled or repaired to manufacture authority.

Source delivery starts at File1380, without elision; the registry names the final file and next continuation. Dependencies, assets and binaries are rebuilt from locked prerequisites. Every old primary byte and removed duplicate report remains verifiably recoverable; do not delete recipe bases without preserving the recovery chain.
