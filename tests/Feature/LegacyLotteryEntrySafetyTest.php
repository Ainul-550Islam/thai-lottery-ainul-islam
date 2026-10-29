<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LegacyLotteryEntryException;
use App\Jobs\Settlement\ChunkedSettlementJob;
use App\Jobs\Settlement\ProcessWinningEntryPayoutJob;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\LotteryEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Lottery\BatchWinningSettlementService;
use App\Services\Order\OrderPlacementService;
use App\Services\ResultIngestion\DrawResultConfirmationService as LegacyConfirmation;
use App\Services\ResultIngestion\GloResultIngestionService as LegacyIngestion;
use App\Services\Settlement\DrawSettlementCoordinatorService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A retired path must not mutate money, entries, or an existing result. */
final class LegacyLotteryEntrySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_legacy_writers_and_previously_queued_jobs_fail_before_any_change(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'type' => 'main', 'currency' => 'THB']);
        $wallet->forceFill(['status' => 'active', 'balance' => '100.00'])->save();
        $draw = Draw::factory()->closed()->create(['scheduled_at' => now()->subDay()]);
        $result = DrawResult::query()->create([
            'draw_id' => $draw->id, 'first_prize' => '123456',
            'published_at' => now(), 'metadata' => ['verification_status' => 'CONFIRMED'],
        ]);
        $entry = LotteryEntry::query()->create([
            'draw_id' => $draw->id, 'user_id' => $user->id,
            'ticket_number' => '123456', 'bet_type' => 'DIRECT_6D',
            'stake_amount' => '10.0000', 'status' => 'PENDING',
            'idempotency_key' => 'existing-legacy-entry', 'chunk_index' => 0,
        ]);

        $snapshot = static fn (): array => [
            'wallet' => (string) $wallet->fresh()->balance,
            'entry' => $entry->fresh()->getAttributes(),
            'draw' => $draw->fresh()->getAttributes(),
            'result' => $result->fresh()->getAttributes(),
            'entries' => DB::table('lottery_entries')->count(),
            'results' => DB::table('draw_results')->count(),
            'payouts' => DB::table('payouts')->count(),
            'ledger_transactions' => DB::table('ledger_transactions')->count(),
            'financial_transactions' => DB::table('financial_transactions')->count(),
        ];
        $before = $snapshot();
        $operations = [
            fn () => app(OrderPlacementService::class)->placeOrder($user->id, $draw->id, '123456', 'DIRECT_6D', '10.00'),
            fn () => app(LegacyIngestion::class)->ingestPayload($draw->id, ['first_prize' => '999999', 'two_digit_back' => '88'], $user->id),
            fn () => app(LegacyConfirmation::class)->confirmResult($result->id, $user->id),
            fn () => app(DrawSettlementCoordinatorService::class)->initiateSettlement($draw->id, $user->id),
            fn () => (new ChunkedSettlementJob($draw->id, 0, $result->id))->handle(app(RealtimeSyncService::class)),
            fn () => (new ProcessWinningEntryPayoutJob($entry->id, $draw->id, $user->id, '999999.00', 'FORGED'))
                ->handle(app(WalletMutationService::class)),
            fn () => app(BatchWinningSettlementService::class)->settleDrawEntries($result),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('The unverified legacy writer unexpectedly ran.');
            } catch (LegacyLotteryEntryException $exception) {
                $this->assertStringContainsString('unavailable', $exception->getMessage());
            }
            $this->assertSame($before, $snapshot());
        }
    }

    public function test_retired_lifecycle_proof_and_benchmark_create_no_synthetic_data(): void
    {
        $users = User::query()->count();
        $draws = Draw::query()->count();
        $wallets = Wallet::query()->count();
        $this->artisan('fintech:verify-full-lifecycle')
            ->expectsOutputToContain('unavailable until LotteryEntry settlement is safely migrated')
            ->assertFailed();
        $this->artisan('benchmark:order-placement')
            ->expectsOutputToContain('no benchmark data was created')
            ->assertFailed();
        $this->assertSame($users, User::query()->count());
        $this->assertSame($draws, Draw::query()->count());
        $this->assertSame($wallets, Wallet::query()->count());
    }
}
