<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Object-level authorization for withdrawal reads and mutations.
 *
 * A player must never read or cancel another player's withdrawal, and a
 * refused request must leave the withdrawal row untouched.
 */
final class WithdrawalObjectAuthorizationTest extends PaymentTestCase
{
    #[Test]
    public function player_can_view_their_own_withdrawal_status(): void
    {
        $player = $this->createPlayer('1000.00');
        $withdrawal = $this->createWithdrawalFor($player);
        Sanctum::actingAs($player['user']);

        $response = $this->getJson('/api/v1/withdrawal/status/'.$withdrawal->reference_number);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    #[Test]
    public function player_cannot_view_another_players_withdrawal_status(): void
    {
        $owner = $this->createPlayer('1000.00');
        $intruder = $this->createPlayer('1000.00');
        $withdrawal = $this->createWithdrawalFor($owner);
        Sanctum::actingAs($intruder['user']);

        $response = $this->getJson('/api/v1/withdrawal/status/'.$withdrawal->reference_number);

        $response->assertStatus(404);
        $response->assertJsonMissingPath('data.amount');
    }

    #[Test]
    public function player_cannot_cancel_another_players_withdrawal_and_nothing_changes(): void
    {
        $owner = $this->createPlayer('1000.00');
        $intruder = $this->createPlayer('1000.00');
        $withdrawal = $this->createWithdrawalFor($owner);
        Sanctum::actingAs($intruder['user']);

        $response = $this->postJson('/api/v1/withdrawals/'.$withdrawal->reference_number.'/cancel', [
            'reason' => 'attempted cross-account cancel',
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertLessThan(500, $response->status());

        $fresh = $withdrawal->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(WithdrawalStatus::Pending, $fresh->status);
    }

    #[Test]
    public function unauthenticated_request_cannot_read_a_withdrawal(): void
    {
        $owner = $this->createPlayer('1000.00');
        $withdrawal = $this->createWithdrawalFor($owner);

        $this->getJson('/api/v1/withdrawal/status/'.$withdrawal->reference_number)
            ->assertStatus(401);
    }

    private function createWithdrawalFor(array $player): Withdrawal
    {
        return Withdrawal::factory()
            ->for($player['user'])
            ->for($player['wallet'])
            ->create([
                'status' => WithdrawalStatus::Pending,
                'amount' => '100.00',
                'fee' => '0.00',
                'net_amount' => '100.00',
            ]);
    }
}
