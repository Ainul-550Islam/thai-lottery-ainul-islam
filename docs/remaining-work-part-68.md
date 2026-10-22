# Part68 — completed current member-eligibility scope

## Delivered application functionality

Both actual quote issuance and new conversion execution now require current account, configured exclusion/cooling-off, KYC evidence and mobile facts, not stale REPEATABLE READ or loaded-model state. Canonical exclusion survives legacy projection drift. Soft-deleting a live safety row cannot cancel it. Expired, malformed, revoked or deleted identity evidence cannot grant new conversion authority. Existing non-expiring verified documents remain supported. A restored eligible member can proceed; original exact financial replay remains observational after eligibility revocation.

- Complete default: 4386 cases / 143731 assertions; 211 conditional skips; 317 isolated source files; exact current discovery and every previous 4339 ID retained; zero failures/errors.
- Executed native: 613 cases / 7840 assertions; zero skips/errors/failures; 68 genuine two-child parent waits, including ten new member-eligibility old-RR profiles.
- Raw native selection has 614 IDs and one SQLite-trigger-only skipped record. That same ID passed default; it is not native execution.
- Dedicated overlapping subset: 47 cases / 337 assertions; 37 feature and ten native cases, zero skips/errors/failures.
- Fourteen qualified single-assertion omission failures, zero setup errors/skips. Nine preserve the User owner mutex, prove actual old member snapshots, genuine parent/two-child waits and all three shadow-loaded policy/member sources. The other five prove physical/framework transaction guards, the actual policy hook, exact-second document expiry and deleted safety-row protection. Real source bytes were never mutated.
- PHP syntax 2177 files; Pint seven changed PHP files; Python 86; Node 54; Rust 27 locked tests and release build; genuine Vite build; clean Composer and complete npm dependency graph.
- Static 35 cases / 1728 assertions; standalone 29 cases / 94 assertions. No new migration. Separate empty SQLite up/down/up is not a populated production restore.

## Separate remaining production boundary

Provider/merchant details are owner-supplied later; their absence did not defer this application implementation. No external transfer, provider approval or worldwide legal authorization is invented. Existing production/live-operation gates remain in force.

The tested local owner-serialized InnoDB repeatable-read member-admission path is qualified. Arbitrary privileged account/KYC/exclusion writers, other isolation modes, current wallet-advisory behavior, other financial lanes, custom DST timezones, production load, distributed failover and populated backup/restore are separate qualification work. Every real execution must retain the existing financial and quote locks, exact rolling allowance, original accounting proof, refund-recovery gate and operator reversal permission controls. Advisory UI allowance and quote issuance are not money reservations.

Full-body source delivery starts at File1361 without elision. The numbered registry identifies the final file, cumulative archive and next continuation. Locked dependencies, generated frontend assets, Rust binaries and disposable databases are runtime prerequisites, not abbreviated source deliveries. Historical primary bytes remain recoverable exactly; duplicate consolidation must validate the old checksums and preserve every source/test path.
