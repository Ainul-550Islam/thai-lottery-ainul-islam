# Transfer lane repair brief — what #53/#54 lost, and the exact boundary of what may be written

Status: **open, assigned to the next cluster. Do not "close" it by inventing the money-moving half.**

This file exists because a sandbox reset reverted `app/` to the one-commit HEAD, and 53 clusters of uncommitted work
went with it. Cluster #54 committed what was left (see the `freeze the campaign layer` commit, whose parent is the
`base_commit` every evidence block cites), then measured the remainder instead of papering over it. Every number below
was produced by a command in this workspace on 2026-10-17; the commands are printed so the next cluster can re-run
them rather than trust this text.

## 1. The measured blast radius

Seven classes are referenced by files that ARE present, and are themselves absent from the tree, from `HEAD`, and
from all 53 pair archives:

| missing class | referenced by (count) |
| --- | --- |
| `App\Enums\TransferDirection` | 7 |
| `App\Exceptions\BalanceTransferException` | 4 |
| `App\Services\Transfer\BalanceTransferService` | 3 |
| `App\Models\BalanceTransfer` | 3 |
| `App\Enums\TransferStatus` | 2 |
| `App\Services\Transfer\TransferPanelService` | 1 |
| `App\Services\Transfer\TransferLimitService` | 1 |

plus `App\Services\Finance\TransferFeeCalculator`, named by `TRANSFER-FEATURE-MANIFEST.md:214` and by the docblock in
`config/transfer.php` ("TransferFeeCalculator calls FeesPageService::calculate() on that category").

Reproduce with (case matters: the directory is `app/`, the namespace is `App\`, so lowercase only the first segment):

```sh
cd /home/user/repo
for f in app/Http/Controllers/Transfer/BalanceTransferController.php \
         app/Http/Requests/Transfer/StoreBalanceTransferRequest.php \
         app/DTOs/Transfer/TransferFee.php app/DTOs/Transfer/TransferQuote.php \
         tests/Feature/Transfer/BalanceTransferServiceTest.php \
         tests/Unit/Transfer/TransferDirectionTest.php; do
  grep -oE '^use App\\[A-Za-z\\]+;' "$f" | sed 's/^use App\\//;s/;$//' | while read -r c; do
    p="app/$(printf '%s' "$c" | sed 's#\\#/#g').php"
    [ -f "$p" ] || echo "MISSING App\\$c"
  done
done | sort -u
```

Confirming nothing is recoverable from the archives — 0 of 53 archives contain a member whose content defines the
enum, and `git log --all -- app/Enums/TransferDirection.php` is empty because the repo has one commit and this file
was never in it:

```sh
cd /home/user && for a in backup/*.tar.gz; do tar -xzOf "$a" 2>/dev/null | grep -l "enum TransferDirection" -; done
git -C repo log --all --oneline -- app/Enums/TransferDirection.php | wc -l
```

An earlier note in `logs/MEMORY-next-cluster.md` claimed the tests that specify this enum were themselves
unrecoverable. **That was wrong and is corrected here**: `tests/Unit/Transfer/TransferDirectionTest.php` (214 lines,
15 test methods) and `tests/Feature/Transfer/BalanceTransferServiceTest.php` are both in the tree and now committed.
The specification survived; only the implementation did not. That inverts the difficulty: nothing about the enum's
*contract* needs to be guessed.

## 2. The half that may be written now — the contract is already fixed

`TransferDirection`'s entire public surface is whatever the tree calls. Nothing more, nothing less:

```
values(): array                # ['cash_to_win', 'win_to_cash']  — order asserted
cases()                        # native
tryFromInput(mixed): ?self     # trims, lowercases, returns null for '' / blank / non-string / unknown ('cash-to-win', 'primary_to_bonus', 42, ['cash_to_win'])
betweenWalletTypes(WalletType, WalletType): self   # Primary+Win -> CashToWin, Win+Primary -> WinToCash; throws on same-type and on a non-pocket source
sourceWalletType() / destinationWalletType(): WalletType
opposite(): self               # exact inverse; opposite()->opposite() === $this
pocketNames(): array{from:string,to:string}        # from === source->transferPocketName(), to === dest->transferPocketName(), and the two differ
feeCategory(): string          # config/transfer.php: 'cash_to_win' and 'win_to_cash' respectively — i.e. the case value, sourced from config, not from a literal in the enum
label(): string                # non-empty, distinct
```

and on `App\Enums\WalletType`, two methods nothing else in `app/` calls (they exist for the coupling assertion):

```
isTransferPocket(): bool       # true for Primary and Win, false for Bonus and Commission
transferPocketName(): ?string  # non-null for the two pockets, null for Bonus and Commission
```

The values are pinned by files already in the tree: `config/transfer.php` `'wallets' => ['cash' => WalletType::Primary->value, 'win' => WalletType::Win->value]`
gives the pocket naming, and `WalletType::Win = 'win'` is itself a fleet rule
(`transfer-backup.sh:88` — `app/Enums/WalletType.php|case Win = 'win'`), which #54 re-applied after the reset. The
fee categories exist so the load-bearing assertion in the unit test can pass: `config('fees.categories.cash_to_win')`
and `...win_to_cash` must each be an array with `enabled` truthy and `calculation === 'percentage'`, and must appear in
`FeesPageService::categoryOrder()`.

`TransferStatus` and `BalanceTransfer` (model) are mechanical once the migration is read: `grep -rn "balance_transfers" database/migrations`
names the columns and the enum is the set of statuses the desk controller and the feature test switch on.

## 3. The half that must NOT be written from a test

`BalanceTransferService`, `TransferLimitService`, `TransferPanelService`, `TransferFeeCalculator` move money. Their
correct behaviour is not fully determined by the surviving tests: two wallet row locks, idempotency replay-vs-conflict
(`config/transfer.php` 'idempotency'), the rolling-24h per-player-per-direction counters, the cooldown, and
four-eyes reversal at or above `50000.00` are each specified *in prose in that config file* but the arithmetic and the
failure ordering are not asserted anywhere that still exists. Reconstructing a ledger engine from a red test suite is
how a fleet ends up with a green build and a wrong balance.

So: implement §2, get `php artisan route:list` to boot (it currently dies on the missing enum during route file
loading, which is a *different* cause than the one `final-release-acceptance.json` records for `app-base`), and then
let the transfer feature tests decide §3 rather than deciding it for them. If they stay red, `RELEASE BLOCKED`
stays, and that is the correct outcome.

## 4. Two things already repaired, and how to tell they stayed repaired

* `app/Services/Finance/WalletService.php` gained `public const ACCOUNT_WALLET_TRANSFER_CLEARING = '1300';`. The value
  is not a guess: line 89 of `transfer-backup.sh` (an archived copy is in `backup/docs-and-fleet-truth.tar.gz`) pins
  it as an exact-content rule, and `TRANSFER-FEATURE-MANIFEST.md:214` repeats it. `LedgerAccountSeeder.php:84`
  (tracked) requires the constant; before this fix **22 of 42** `tests/Feature/Infra` tests failed on that seeder, and
  the failure class was `Error`, not an assertion. Code uniqueness was checked: `'1100'` is used only at
  `WalletService.php:58`, and `'1200'`/`'1300'` appeared nowhere in `app/`, `config/`, or `database/`.
* `app/Enums/WalletType.php` regained `case Win = 'win';`.

## 5. Why the loss keeps happening, and what #54 changed

`git -C repo log --oneline | wc -l` was **1**, and `git ls-files tests | wc -l` was **0** while
`find tests -name '*.php' | wc -l` was **227**. Every cluster's app-layer edit was therefore uncommitted, so
`setup-env.sh`'s `git checkout -- .` reverted them at each reset, and `restore53lost.py` could only fill *absent*
files — never a tracked file whose content had been rolled back. The 31 deliberately deleted paths had the mirror
defect: the deletions were uncommitted, so each reset resurrected them and turned the gates that assert their absence
red.

#54 committed both halves in one commit: the tests, the app edits, and the 31 deletions. A reset now restores a tree
that already contains the tests and already contains the deletions. After the commit, `git status --porcelain | wc -l`
was 0 and `git ls-files tests | wc -l` was 232.

## 6. What #54 could not fix

* `docs/*.md` at the fleet root are gone and were **never archived**: `for a in backup/*.tar.gz; do tar -tzf "$a" | grep -c '^docs/\|^repo/docs/'; done`
  returns 0 for all 53. The reason is structural, not accidental — `tools/write52pair.py` derives that pair's owned
  paths through `git ls-files` in the `/home/user` worktree, and that worktree's `.git` no longer exists, so the rule
  returned nothing and the save silently dropped the paths. A pair's guard can only refuse over paths that are still
  *listed*; a path that vanishes from the list is invisible to it. Any ownership rule computed through a git query
  needs a non-git fallback, and a "the owned list shrank" check needs to live in the generator, not the guard.
* `runtime/evidence/final-release-acceptance.json` is likewise in 0 archives (the copy at `runtime/final-release-acceptance.json`
  is tracked and survived, and it is what the 26-claim ledger in §5 of the report is read from).
* Of the fleet's 260 exact-content rules across 118 files, `tools/rules54census.py` measures **69 satisfied / 191
  violated / 0 broken** right now. Violated rules are repair targets: the pattern is the specification the fleet
  recorded for that file. They are not all lane damage — a reset also reverted cosmetic and comment-level pins — but
  the census is the first tool that says which ones, in one pass, instead of one pair at a time.
