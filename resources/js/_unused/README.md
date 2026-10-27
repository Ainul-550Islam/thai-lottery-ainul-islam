# Removed frontend code — and why this file is still here

This directory used to hold the quarantined React mockups. Batch 02 removed
them. What remains is this note, so that the next person who wonders where the
`.tsx` files went, and why there is a `_unused` directory with nothing in it,
finds an answer instead of guessing.

## What was removed

Twelve React components (`AuthCard`, `DepositPortal`, `GloResultsChecker`,
`LotteryHistory`, `LottoFinAdminDashboard`, `PlayerDashboard`,
`PlayerProfile`, `PlayerSecuritySettings`, `PlayerSettings`,
`ThaiLotteryBetting`, `WalletManagement`, `WithdrawalPortal`) plus eleven
TypeScript modules that duplicated a live `.js` file
(`admin-lottofin.ts`, `auth-portal.ts`, `deposit-portal.ts`,
`glo-results-checker.ts`, `lottery-history.ts`, `player-dashboard.ts`,
`player-profile.ts`, `player-settings.ts`, `player-security-settings.ts`,
`thai-lottery-betting.ts`, `wallet-management.ts`, `withdrawal-portal.ts`).

## Why they were removed rather than kept

Three reasons, in order of how much they mattered.

1. **They were not implementations.** Member data was hardcoded
   (`memberId: 'TL-894120'`, `cashBalance: 5450.00`) and real-money handlers
   were `alert()` stubs: `handleTransfer()` announced "transfer executed"
   without calling any endpoint. A page that mounted one would show a member a
   fabricated balance and a fake confirmation for a money movement.
2. **They were unreachable.** No `@vite()` reference, no import, no loader. The
   build compiled them and nothing ever ran them, so they only consumed build
   time and gave reviewers the impression that a screen was implemented twice.
3. **The TypeScript twins were stale copies of live modules.** Two files that
   claim to implement the same thing, one of which silently rot, is how a
   reader ends up editing the wrong one. The `.js` file is the implementation;
   the `.ts` file was a snapshot of it.

The design work is not lost: every one of these files is in the repository's git
history, which is what history is for. It is not in the working tree, where it
would keep being mistaken for code that runs.

## Rules that still apply

- Nothing under `resources/js` may be TypeScript or TSX. There is no TypeScript
  build step: `vite.config.js` registers `.js` and `.css` inputs only, so a
  `.ts` file in this tree is a file that cannot be built.
- `tests/Feature/Frontend/NoDeadFrontendFilesTest.php` and
  `tests/Feature/Hygiene/ViteEntryIntegrityTest.php` enforce both halves: no
  `.ts`/`.tsx` files, and no build entry pointing at `resources/js/components/`.
- If a screen is needed, it is a Blade view backed by a controller and a
  service, with its behaviour in a module under `resources/js`, and every figure
  on it comes from the server.
