<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Draw;
use App\Models\LotteryEntry;
use App\Models\Wallet;
use App\Services\Order\OrderPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderPlacementIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private OrderPlacementService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderService = app(OrderPlacementService::class);
    }

    public function test_duplicate_order_placement_with_same_idempotency_key_is_deduplicated(): void
    {
        $userId = Str::uuid()->toString();

        $wallet = Wallet::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'type' => 'main',
            'currency' => 'THB',
            'status' => 'active',
        ]);
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '500.0000']);

        $draw = Draw::create([
            'id' => Str::uuid()->toString(),
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

        // 1st Attempt: Places Order and deducts 100 THB
        $entry1 = $this->orderService->placeOrder(
            userId: $userId,
            drawId: $draw->id,
            ticketNumber: '592314',
            betType: 'DIRECT_6D',
            stakeAmount: '100.0000',
            idempotencyKey: $idempotencyKey
        );

        // 2nd Attempt: Same idempotency key
        $entry2 = $this->orderService->placeOrder(
            userId: $userId,
            drawId: $draw->id,
            ticketNumber: '592314',
            betType: 'DIRECT_6D',
            stakeAmount: '100.0000',
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($entry1->id, $entry2->id);
        $this->assertEquals(1, LotteryEntry::where('idempotency_key', $idempotencyKey)->count());

        // Balance should ONLY be deducted once (500 - 100 = 400 THB)
        $this->assertEquals('400.0000', $wallet->fresh()->balance);
    }
}
