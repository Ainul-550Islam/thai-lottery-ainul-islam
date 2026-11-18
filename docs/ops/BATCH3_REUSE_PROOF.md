# Batch 3 reuse proof (Gate G8, second half)

Each NEW service is listed with the EXISTING class it delegates to. The "Imports"
column was produced from the `use App\...` lines of the delivered files, not from
memory. "Delegates to" is the reviewer's reading of what the imported class is used
for. Re-generate the imports with:

```bash
for f in $(find app/Services -name '*.php' | sort); do
  echo "## $f"; grep -hoE '^use App\\(Services|Models|Support|Enums|ValueObjects|DTOs)\\[A-Za-z\\]+' "$f"
done
```

## Read models (must never write money tables)

| New class | Delegates to (existing) | Writes anything? |
|---|---|---|
| `Wallet\WalletOverviewService` | `Wallet` model (balances), `Fx\FxDisplayService` (new, display only), optional Batch 1 `CashbackBalanceReader` | No |
| `Wallet\WalletTransactionFeedService` | `FinancialTransaction`, `Enums\TransactionType` | No |
| `Account\ProfileOverviewService` | `Wallet\WalletOverviewService`, `Enums\KycStatus`, `User` | No |
| `MyBets\MyBetsQueryService` | `Bet` model, `Enums\BetStatus` | No |
| `Security\SecurityOverviewService` | `SecurityEvent`, `SecuritySession`, `TotpCredential`, `TrustedDevice` | No |
| `Security\SecurityAlertFeedService` | `SecurityEvent` | No |
| `Promotions\ActivePromotionsQueryService` | `Promotions\PublicBonusService`, `PointsMultiplierCampaign` | No |
| `Compliance\ResponsibleGamingSummaryService` | `ResponsibleGamingLimit`, `SelfExclusion` | No |
| `Compliance\ComplianceBadgeService` | `ComplianceAttestation` (valid scope) | No |
| `Ops\LedgerIntegrityChecker` | `Wallet`, `LedgerEntry`, `FinancialTransaction`, `Enums\LedgerEntryType` | No (report only) |
| `Ops\HealthCheckService` | `Observability\SystemHealthService` | No |
| `Fx\FxDisplayService` | `Fx\FxRateProvider` (interface; null driver by default) | No |

## Single-owner writers

| New class | Owns | Delegates the hard part to |
|---|---|---|
| `BetSlip\BetSlipService` | `bet_slips`, `bet_slip_items` | `ValueObjects\LotteryNumber`, `Draw` (read), `config('lottery.markets')` |
| `BetSlip\BetSlipConfirmationService` | status change of a slip to confirmed | `Betting\BetPurchaseService::purchase()` for every item (the only money path) |
| `BetSlip\BetSlipPricingService` (read-only) | nothing | `Betting\BetCalculationService`, `Betting\MarketPayoutService` |
| `Wallet\WalletBalanceSnapshotService` | `wallet_balance_snapshots` | `Wallet` (read) |
| `Notifications\NotificationPreferenceGuardService` | the `locked_by_policy` column on `notification_preferences` (the only direct write) | `Notification\NotificationPreferenceService` for every preference write and read |
| `Support\SupportConversationService` | nothing of its own | `Chat\ChatConversationService` |
| `Support\SupportMessageService` | the write-once rating on `chat_conversations` (`rating`, `rated_at`, `rating_comment`) | `Chat\ChatMessageService` for messages |
| `Loyalty\PointsMultiplierService` (read-only) | nothing | `PointsMultiplierCampaign` |

## Edits to existing classes (additive)

| Existing class | Added | Default behaviour when unused |
|---|---|---|
| `Loyalty\LoyaltyPointsService` | optional `PointsMultiplierService` collaborator; `effectivePoints()`; campaign stamp in ledger metadata | identical to before (pinned by `LoyaltyPointsRegressionTest`) |
| `Notification\NotificationDispatchService` | optional `NotificationPreferenceGuardService`; one extra `allows()` check before sending | guard `null` or category mandatory = send as before |
| `Console\Commands\Ops\HeartbeatCommand` | also writes the cache heartbeat key | file heartbeat unchanged |

## Differences from the task prompt's wording, stated plainly

| Prompt says | Delivered | Consequence |
|---|---|---|
| Validate via Batch 2 `GameAvailabilityService` + `DigitNumberValidator` | `config('lottery.markets')` + `LotteryNumber` | Batch 2 classes are not in this repository |
| Pricing via Batch 2 `PayoutCalculator` | `BetCalculationService` + `MarketPayoutService` | same reason |
| Free-bet items via Batch 2 `FreeBetFundingAdapter` | refused at add time (`funding_source_unsupported`) | no voucher path exists in the code base |
| Cashback via Batch 1 `CashbackBalanceReader` | optional reader; `cashback = null` when absent | feature dormant until the reader exists |
| `NotificationPreferenceService` (new) | extends the existing one through a guard | avoids a second preference writer |
| New `support_conversations` / `support_messages` tables | existing chat tables + rating columns | prompt allows this when an equivalent exists |
| `upcoming_draws` on `PlayerDashboardSummaryService` | NOT delivered | needs Batch 2 `UpcomingDrawsService` and the target file |

When Batch 2 is present in the repository, re-point the first three rows at the Batch 2
classes. Each is a one-constructor change inside the named service; no controller or
resource changes.
