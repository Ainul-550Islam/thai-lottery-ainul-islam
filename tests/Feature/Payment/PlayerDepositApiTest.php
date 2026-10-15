<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\Payment;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\PublicPages\FeesPageService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

final class PlayerDepositApiTest extends PaymentTestCase
{
    #[Test]
    public function authenticated_player_can_initiate_stripe_deposit(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.deposit.amount', '500.00');
        $response->assertJsonPath('data.deposit.status', 'pending');
        $response->assertJsonPath('data.deposit.currency', 'THB');
        $response->assertJsonPath('data.payment.status', 'pending');

        $deposit = Deposit::query()->where('user_id', $player['user']->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame('500.00', (string) $deposit->amount);
    }

    #[Test]
    public function player_can_view_their_own_deposit(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '750.00', PaymentMethod::Stripe);
        Sanctum::actingAs($player['user']);

        $response = $this->getJson('/api/v1/deposits/'.$deposit->reference_number);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.reference_number', $deposit->reference_number);
        $response->assertJsonPath('data.amount', '750.00');
    }

    #[Test]
    public function player_cannot_view_another_players_deposit(): void
    {
        $player1 = $this->createPlayer('100.00', Currency::THB);
        $player2 = $this->createPlayer('200.00', Currency::THB);

        $deposit = $this->createPendingDeposit($player1['wallet'], '500.00', PaymentMethod::Stripe);
        Sanctum::actingAs($player2['user']);

        $response = $this->getJson('/api/v1/deposits/'.$deposit->reference_number);

        $response->assertStatus(404);
    }

    // =====================================================================
    // FEES PARITY BATCH — the configured cash-in fee is calculated
    // server-side, exactly once, and can never be altered by the client.
    // =====================================================================

    #[Test]
    public function configured_cash_in_fee_is_calculated_server_side_exactly_once(): void
    {
        Config()->set('finance.deposit.fee_percentage', '2.00');

        $player = $this->createPlayer('100.00', Currency::THB);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
        ]);

        $response->assertStatus(201);

        // Exactly ONE deposit row and ONE payment aggregate for the request.
        $this->assertSame(
            1,
            Deposit::query()->where('user_id', $player['user']->id)->count(),
            'A single deposit request must create exactly one deposit row'
        );
        $deposit = Deposit::query()->where('user_id', $player['user']->id)->firstOrFail();

        $this->assertSame(1, Payment::query()->where('payable_id', $deposit->id)->where('payable_type', Deposit::class)->count());

        // The fee is the canonical 2% — computed by the server, persisted once.
        $this->assertStoredMoneySame('10.00', (string) $deposit->fee);
        $this->assertStoredMoneySame('490.00', (string) $deposit->net_amount);

        // The payment aggregate mirrors the SAME stored fee (data recorded
        // twice, charged once: the completion credits the stored net only).
        $payment = Payment::query()->where('payable_id', $deposit->id)->where('payable_type', Deposit::class)->firstOrFail();
        $this->assertStoredMoneySame('10.00', (string) $payment->fee);

        // And the engine's own resolver agrees to the cent.
        $engineFee = app(DepositService::class)
            ->resolveFee(Money::of('500.00', Currency::THB));
        $this->assertSame('10.00', $engineFee->toString());
    }

    #[Test]
    public function client_supplied_fee_fields_are_completely_ignored(): void
    {
        Config()->set('finance.deposit.fee_percentage', '2.00');

        $player = $this->createPlayer('100.00', Currency::THB);
        Sanctum::actingAs($player['user']);

        // Smuggled fee/rate/net fields must not change the server's numbers.
        $response = $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
            'fee' => '0.01',
            'fee_amount' => '0.01',
            'net_amount' => '499.99',
            'fee_percentage' => '0.00',
        ]);

        $response->assertStatus(201);

        $deposit = Deposit::query()->where('user_id', $player['user']->id)->firstOrFail();
        $this->assertStoredMoneySame('10.00', (string) $deposit->fee, 'The deposit fee must be the canonical server-computed 2.00%');
        $this->assertStoredMoneySame('490.00', (string) $deposit->net_amount);
    }

    #[Test]
    public function default_zero_percent_deposit_fee_keeps_the_deposit_free(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
        ])->assertStatus(201);

        $deposit = Deposit::query()->where('user_id', $player['user']->id)->firstOrFail();
        $this->assertStoredMoneySame('0.00', (string) $deposit->fee);
        $this->assertStoredMoneySame('500.00', (string) $deposit->net_amount);
    }

    #[Test]
    public function an_idempotent_replay_charges_the_fee_exactly_once(): void
    {
        Config()->set('finance.deposit.fee_percentage', '2.00');

        $player = $this->createPlayer('100.00', Currency::THB);
        Sanctum::actingAs($player['user']);

        $key = 'fees-parity-replay-'.bin2hex(random_bytes(8));

        $first = $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
            'idempotency_key' => $key,
        ]);
        $first->assertStatus(201);

        $replay = $this->postJson('/api/v1/deposits', [
            'amount' => '500.00',
            'method' => 'stripe',
            'currency' => 'THB',
            'idempotency_key' => $key,
        ]);

        // A replay must not create a second deposit row, a second payment
        // aggregate, or a second fee: one charge, one fee, one record.
        $this->assertSame(
            1,
            Deposit::query()->where('user_id', $player['user']->id)->count(),
            'An idempotent replay must not duplicate the deposit'
        );

        $deposit = Deposit::query()->where('user_id', $player['user']->id)->firstOrFail();
        $this->assertStoredMoneySame('10.00', (string) $deposit->fee);
        $this->assertStoredMoneySame('490.00', (string) $deposit->net_amount);
    }

    #[Test]
    public function the_public_fees_page_agrees_with_what_the_deposit_engine_charges(): void
    {
        // The whole point of the execution mirror: whatever the live rule
        // says, the public schedule and the engine charge the same number.
        Config()->set('finance.deposit.fee_percentage', '2.00');

        $pageRow = collect(app(FeesPageService::class)->publicFees('en'))
            ->firstWhere('key', 'cash_in');

        $this->assertSame('2.00%', $pageRow['amount_display']);
        $this->assertSame('2.00', app(DepositService::class)
            ->resolveFee(Money::of('100.00', Currency::THB))->toString());
    }
}
