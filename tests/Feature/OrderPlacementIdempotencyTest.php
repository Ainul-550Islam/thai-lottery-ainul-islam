<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WalletStatus;
use App\Exceptions\LegacyLotteryEntryException;
use App\Models\Draw;
use App\Models\LotteryEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Order\OrderPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OrderPlacementService is retained only for old callers and queue payloads.
 * Legacy lottery-entry sales are refused until an audited migration and
 * settlement path exists, so an idempotency key must never let an order
 * through, create an entry, or move wallet money.
 *
 * Idempotent purchases now belong to the canonical purchase services, which
 * have their own tests (see tests/Feature/Betting).
 */
class OrderPlacementIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private OrderPlacementService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = app(OrderPlacementService::class);
    }

    public function test_legacy_order_placement_with_idempotency_key_is_refused_without_side_effects(): void
    {
        $user = User::factory()->create();

        $wallet = new Wallet([
            'user_id' => $user->id,
            'type' => 'main',
            'currency' => 'THB',
        ]);
        $wallet->status = WalletStatus::Active;
        $wallet->save();
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '500.0000']);

        $draw = Draw::create([
            'draw_code' => 'THAI-20261016-01',
            'game_type' => 'THAI_6D',
            'draw_date' => now()->addDays(2),
            'betting_starts_at' => now()->subHours(1),
            'betting_ends_at' => now()->addHours(24),
            'status' => 'OPEN',
            'total_entries_count' => 0,
            'total_pool_amount' => '0.0000',
        ]);

        $idempotencyKey = 'IDEM-ORDER-TEST-UNIQUE-KEY-001';

        // 1st attempt: refused
        $this->assertPlacementRefused($user, $draw, $idempotencyKey);

        // 2nd attempt with the same idempotency key: still refused
        $this->assertPlacementRefused($user, $draw, $idempotencyKey);

        // No entry was created and no money moved (500 THB untouched)
        $this->assertSame(0, LotteryEntry::query()->count());
        $this->assertEquals('500.0000', bcadd((string) $wallet->fresh()->balance, '0', 4));
    }

    private function assertPlacementRefused(User $user, Draw $draw, string $idempotencyKey): void
    {
        try {
            $this->orderService->placeOrder(
                userId: $user->id,
                drawId: $draw->id,
                ticketNumber: '592314',
                betType: 'DIRECT_6D',
                stakeAmount: '100.0000',
                idempotencyKey: $idempotencyKey
            );
        } catch (LegacyLotteryEntryException $e) {
            $this->assertStringContainsString('Legacy lottery-entry sales is unavailable', $e->getMessage());

            return;
        }

        $this->fail('Legacy order placement must be refused, but it was accepted.');
    }
}
