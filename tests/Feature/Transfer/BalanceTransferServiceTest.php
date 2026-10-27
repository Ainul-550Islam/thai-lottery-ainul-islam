<?php

declare(strict_types=1);

namespace Tests\Feature\Transfer;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\BalanceTransferException;
use App\Models\BalanceTransfer;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
use App\Services\Transfer\BalanceTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BalanceTransferService: the money actually moves, exactly once, and the books
 * stay balanced.
 *
 * WHAT MAKES THIS SUITE DIFFERENT FROM A CONTROLLER TEST
 * Every assertion here is about STATE, not about a response shape: wallet
 * balances, ledger account totals, row counts and statuses. A transfer that
 * returned 201 while moving the wrong amount would pass an HTTP test and fail
 * here, which is the failure that actually costs money.
 *
 * THE INVARIANT EVERY TEST IMPLICITLY CHECKS
 * Before a transfer the player's two pockets sum to X. After a transfer of A
 * with fee F they sum to X - F, and fee revenue is up by exactly F. Value is
 * neither created nor destroyed; it is either moved between the player's own
 * pockets or taken as a fee. BalanceTransferLedgerTest asserts that at the
 * account level; this suite asserts it at the wallet level.
 */
final class BalanceTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private BalanceTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BalanceTransferService::class);

        // Deterministic limits: the shipped config allows 20.00 - 500000.00 with
        // a 5 second cooldown. Tests that exercise the cooldown override it.
        config([
            'transfer.enabled' => true,
            'transfer.currency' => 'THB',
            'transfer.directions.cash_to_win.cooldown_seconds' => 0,
            'transfer.directions.win_to_cash.cooldown_seconds' => 0,
            'transfer.directions.cash_to_win.daily_count_limit' => 0,
            'transfer.directions.cash_to_win.daily_amount_limit' => '0.00',
            'transfer.directions.win_to_cash.daily_count_limit' => 0,
            'transfer.directions.win_to_cash.daily_amount_limit' => '0.00',
        ]);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function player(): User
    {
        return User::factory()->create();
    }

    /**
     * The cash pocket (WalletType::Primary) with a balance.
     */
    private function cashWallet(User $user, string $balance = '1000.00'): Wallet
    {
        return Wallet::factory()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
            'status' => WalletStatus::Active,
            'balance' => $balance,
            'locked_balance' => '0.00',
        ]);
    }

    /**
     * The win pocket with a balance.
     */
    private function winWallet(User $user, string $balance = '0.00'): Wallet
    {
        return Wallet::factory()->create([
            'user_id' => $user->id,
            'type' => WalletType::Win,
            'currency' => Currency::THB,
            'status' => WalletStatus::Active,
            'balance' => $balance,
            'locked_balance' => '0.00',
        ]);
    }

    private function balance(Wallet $wallet): string
    {
        return bcadd((string) $wallet->refresh()->balance, '0', 2);
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    #[Test]
    public function a_cash_to_win_transfer_moves_the_amount_and_charges_the_fee_on_top(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user, '0.00');

        $outcome = $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        $this->assertFalse($outcome->isReplay());
        $this->assertSame(201, $outcome->httpStatus());

        // The published cash_to_win rate is 1%, so the fee on 100.00 is 1.00.
        $this->assertSame('100.00', (string) $outcome->transfer->amount);
        $this->assertSame('1.00', (string) $outcome->transfer->fee_amount);
        $this->assertSame('101.00', (string) $outcome->transfer->total_debited);

        // Source pays amount + fee; destination receives exactly the amount.
        $this->assertSame('899.00', $this->balance($cash));
        $this->assertSame('100.00', $this->balance($win));
    }

    #[Test]
    public function a_win_to_cash_transfer_moves_money_back_the_other_way(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '50.00');
        $win = $this->winWallet($user, '500.00');

        $outcome = $this->service->transfer($user, TransferDirection::WinToCash, '200.00');

        // The published win_to_cash rate is also 1%.
        $this->assertSame('2.00', (string) $outcome->transfer->fee_amount);
        $this->assertSame('202.00', (string) $outcome->transfer->total_debited);

        // The source pocket pays amount + fee (config/transfer.php fee_model
        // 'on_top'), so 500.00 - 202.00 = 298.00. The earlier expectation of
        // 300.00 ignored the fee this same test asserts 202.00 for, and
        // contradicted the cash->win twin above (1000.00 -> 899.00 on a 101.00
        // debit). The destination is credited the amount only: 50.00 + 200.00.
        $this->assertSame('298.00', $this->balance($win));
        $this->assertSame('250.00', $this->balance($cash));
    }

    #[Test]
    public function the_transfer_row_records_the_completed_state_and_both_transaction_legs(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '100.00')->transfer;

        $this->assertSame(TransferStatus::Completed, $transfer->status);
        $this->assertTrue($transfer->isSettled());
        $this->assertNotNull($transfer->completed_at);
        $this->assertNull($transfer->failure_code);

        $this->assertNotNull($transfer->debit_transaction_id);
        $this->assertNotNull($transfer->credit_transaction_id);
        $this->assertNotNull($transfer->fee_transaction_id);

        // The three legs are three distinct financial transactions.
        $this->assertSame(
            3,
            count(array_unique([
                (int) $transfer->debit_transaction_id,
                (int) $transfer->credit_transaction_id,
                (int) $transfer->fee_transaction_id,
            ])),
        );

        foreach ([$transfer->debit_transaction_id, $transfer->credit_transaction_id, $transfer->fee_transaction_id] as $id) {
            $this->assertNotNull(FinancialTransaction::query()->find($id));
        }
    }

    #[Test]
    public function the_row_snapshots_the_balances_as_they_were_at_that_moment(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user, '25.00');

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '100.00')->transfer;

        $this->assertSame('1000.00', (string) $transfer->source_balance_before);
        $this->assertSame('899.00', (string) $transfer->source_balance_after);
        $this->assertSame('25.00', (string) $transfer->destination_balance_before);
        $this->assertSame('125.00', (string) $transfer->destination_balance_after);
    }

    #[Test]
    public function the_fee_is_snapshotted_so_a_later_rate_change_cannot_rewrite_history(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '100.00')->transfer;

        $this->assertSame('cash_to_win', (string) $transfer->fee_category);
        $this->assertSame('0.010000', (string) $transfer->fee_rate);

        // Change the published rate afterwards. The stored row must not move.
        config(['fees.categories.cash_to_win.rate' => '0.0900']);

        $this->assertSame('1.00', (string) $transfer->refresh()->fee_amount);
        $this->assertSame('0.010000', (string) $transfer->fee_rate);
    }

    // ------------------------------------------------------------------
    // Wallet provisioning
    // ------------------------------------------------------------------

    #[Test]
    public function a_win_wallet_is_created_on_first_use(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '500.00');

        $this->assertSame(0, Wallet::query()->where('type', WalletType::Win->value)->count());

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        $win = Wallet::query()
            ->where('user_id', $user->id)
            ->where('type', WalletType::Win->value)
            ->where('currency', Currency::THB->value)
            ->first();

        $this->assertNotNull($win);
        $this->assertSame('100.00', bcadd((string) $win->balance, '0', 2));
        // Created through the model, so the production version invariant holds.
        $this->assertGreaterThanOrEqual(Wallet::INITIAL_VERSION, (int) $win->version);
    }

    #[Test]
    public function transferring_twice_reuses_the_same_wallet_rather_than_creating_a_second_one(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '500.00');

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
        $this->service->transfer($user, TransferDirection::CashToWin, '50.00');

        $this->assertSame(
            1,
            Wallet::query()
                ->where('user_id', $user->id)
                ->where('type', WalletType::Win->value)
                ->count(),
        );

        $win = Wallet::query()
            ->where('user_id', $user->id)
            ->where('type', WalletType::Win->value)
            ->first();

        $this->assertSame('150.00', bcadd((string) $win->balance, '0', 2));
    }

    // ------------------------------------------------------------------
    // Total conservation
    // ------------------------------------------------------------------

    #[Test]
    public function the_players_total_balance_falls_by_exactly_the_fee(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user, '200.00');

        $before = bcadd($this->balance($cash), $this->balance($win), 2);
        $this->assertSame('1200.00', $before);

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '300.00')->transfer;

        $after = bcadd($this->balance($cash), $this->balance($win), 2);

        $this->assertSame(
            bcsub($before, (string) $transfer->fee_amount, 2),
            $after,
            'An internal transfer must conserve the player total up to the fee.',
        );
        // 1200.00 held before the move, fee 3.00 on 300.00 at the published 1%
        // rate, so 1197.00. The previous literal (897.00) could not be reached
        // from this fixture by any fee model and contradicted the invariant
        // asserted on the line above.
        $this->assertSame('1197.00', $after);
    }

    #[Test]
    public function a_transfer_with_a_zero_fee_moves_value_exactly(): void
    {
        config(['fees.categories.cash_to_win.rate' => '0.0000']);

        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user, '0.00');

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '250.00')->transfer;

        $this->assertSame('0.00', (string) $transfer->fee_amount);
        $this->assertSame('250.00', (string) $transfer->total_debited);
        $this->assertFalse($transfer->wasFeeCharged());

        // No fee leg at all: a zero-value ledger posting would be noise.
        $this->assertNull($transfer->fee_transaction_id);
        $this->assertSame(2, FinancialTransaction::query()->count());

        $this->assertSame('750.00', $this->balance($cash));
        $this->assertSame('250.00', $this->balance($win));
    }

    // ------------------------------------------------------------------
    // Refusals that must not move money
    // ------------------------------------------------------------------

    #[Test]
    public function an_amount_the_source_cannot_cover_is_refused_and_nothing_moves(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '100.00');
        $win = $this->winWallet($user, '0.00');

        try {
            // 100.00 + 1.00 fee = 101.00 required, only 100.00 available.
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected an insufficient-funds refusal.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_insufficient_funds', $e->machineCode());
            $this->assertSame(422, $e->httpStatus());
        }

        $this->assertSame('100.00', $this->balance($cash));
        $this->assertSame('0.00', $this->balance($win));
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    #[Test]
    public function locked_funds_are_not_spendable_by_a_transfer(): void
    {
        $user = $this->player();

        Wallet::factory()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
            'status' => WalletStatus::Active,
            'balance' => '500.00',
            'locked_balance' => '450.00',
        ]);
        $win = $this->winWallet($user);

        try {
            // Only 50.00 is available; 100.00 + fee is not.
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected a refusal: reserved funds must not be transferable.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_insufficient_funds', $e->machineCode());
        }

        $this->assertSame('0.00', $this->balance($win));
    }

    #[Test]
    public function an_amount_below_the_lane_minimum_is_refused(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '19.99');
            $this->fail('Expected a below-minimum refusal.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_below_minimum', $e->machineCode());
        }

        $this->assertSame('1000.00', $this->balance($cash));
        $this->assertSame(0, BalanceTransfer::query()->count());
    }

    #[Test]
    public function an_amount_above_the_lane_maximum_is_refused(): void
    {
        config(['transfer.directions.cash_to_win.max_amount' => '500.00']);

        $user = $this->player();
        $this->cashWallet($user, '100000.00');

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '500.01');
            $this->fail('Expected an above-maximum refusal.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_above_maximum', $e->machineCode());
        }
    }

    #[Test]
    public function a_malformed_amount_never_reaches_bcmath(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');

        $malformed = ['1e3', '-100', ' 100', '100,00', '100.000', 'abc', '0x64', '100.', '.5', '1 000'];

        foreach ($malformed as $value) {
            try {
                $this->service->transfer($user, TransferDirection::CashToWin, $value);
                $this->fail(sprintf('Expected "%s" to be refused.', $value));
            } catch (BalanceTransferException $e) {
                $this->assertSame(
                    'transfer_amount_malformed',
                    $e->machineCode(),
                    sprintf('"%s" produced %s', $value, $e->machineCode()),
                );
            }
        }

        $this->assertSame(0, BalanceTransfer::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    #[Test]
    public function a_zero_amount_is_refused_even_though_it_is_syntactically_valid(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');

        foreach (['0', '0.00', '00.00'] as $value) {
            try {
                $this->service->transfer($user, TransferDirection::CashToWin, $value);
                $this->fail(sprintf('Expected "%s" to be refused.', $value));
            } catch (BalanceTransferException $e) {
                $this->assertSame('transfer_amount_malformed', $e->machineCode());
            }
        }
    }

    #[Test]
    public function a_frozen_source_wallet_is_refused_without_disclosing_why(): void
    {
        $user = $this->player();

        Wallet::factory()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
            'status' => WalletStatus::Frozen,
            'balance' => '1000.00',
            'locked_balance' => '0.00',
            'locked_at' => now(),
            'locked_reason' => 'Compliance review',
        ]);
        $this->winWallet($user);

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected a frozen wallet to be refused.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_wallet_not_transactable', $e->machineCode());
            $this->assertSame(409, $e->httpStatus());

            // The refusal must not name the control that caught the player.
            $this->assertStringNotContainsStringIgnoringCase('frozen', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('compliance', $e->getMessage());
            $this->assertArrayNotHasKey('status', $e->context());
        }
    }

    #[Test]
    public function a_disabled_lane_refuses_with_503_and_moves_nothing(): void
    {
        config(['transfer.enabled' => false]);

        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected a disabled lane to refuse.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_disabled', $e->machineCode());
            $this->assertSame(503, $e->httpStatus());
        }

        $this->assertSame('1000.00', $this->balance($cash));
        $this->assertSame(0, BalanceTransfer::query()->count());
    }

    #[Test]
    public function a_single_disabled_direction_leaves_the_other_working(): void
    {
        config(['transfer.directions.win_to_cash.enabled' => false]);

        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user, '500.00');

        // Cash -> Win still works.
        $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        try {
            $this->service->transfer($user, TransferDirection::WinToCash, '50.00');
            $this->fail('Expected the disabled direction to refuse.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_direction_disabled', $e->machineCode());
            $this->assertSame(503, $e->httpStatus());
        }
    }

    #[Test]
    public function a_withdrawn_fee_row_refuses_rather_than_transferring_for_free(): void
    {
        config(['fees.categories.cash_to_win.enabled' => false]);

        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user);

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected an unpriceable lane to refuse.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_fee_not_configured', $e->machineCode());
            $this->assertSame(503, $e->httpStatus());
        }

        $this->assertSame('1000.00', $this->balance($cash));
        $this->assertSame('0.00', $this->balance($win));
    }

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------

    #[Test]
    public function the_same_key_with_the_same_payload_replays_without_moving_money_again(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user);

        $first = $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'key-abc-123');
        $afterFirst = $this->balance($cash);

        $second = $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'key-abc-123');

        $this->assertFalse($first->isReplay());
        $this->assertTrue($second->isReplay());
        $this->assertSame(200, $second->httpStatus());
        $this->assertSame((int) $first->transfer->getKey(), (int) $second->transfer->getKey());

        // Money moved once.
        $this->assertSame($afterFirst, $this->balance($cash));
        $this->assertSame('100.00', $this->balance($win));
        $this->assertSame(1, BalanceTransfer::query()->count());
        $this->assertSame(3, FinancialTransaction::query()->count());
    }

    #[Test]
    public function the_same_key_with_a_different_amount_is_a_conflict_not_a_replay(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'key-shared');

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '999.00', 'key-shared');
            $this->fail('Expected a key reuse with a different payload to be refused.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_idempotency_conflict', $e->machineCode());
            $this->assertSame(409, $e->httpStatus());
        }

        // The second amount never moved.
        $this->assertSame(1, BalanceTransfer::query()->count());
    }

    #[Test]
    public function the_same_key_with_a_different_direction_is_a_conflict(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user, '1000.00');

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'key-dir');

        $this->expectException(BalanceTransferException::class);

        $this->service->transfer($user, TransferDirection::WinToCash, '100.00', 'key-dir');
    }

    #[Test]
    public function different_keys_both_execute(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user);

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'key-1');
        $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'key-2');

        $this->assertSame(2, BalanceTransfer::query()->count());
        // 1000 - 101 - 101
        $this->assertSame('798.00', $this->balance($cash));
        $this->assertSame('200.00', $this->balance($win));
    }

    #[Test]
    public function no_key_at_all_still_executes_and_records(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $outcome = $this->service->transfer($user, TransferDirection::CashToWin, '100.00', null);

        $this->assertFalse($outcome->isReplay());
        $this->assertNull($outcome->transfer->idempotency_key);
        $this->assertSame(1, BalanceTransfer::query()->count());
    }

    #[Test]
    public function an_oversized_key_is_hashed_rather_than_truncated(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $long = str_repeat('a', 400);

        $outcome = $this->service->transfer($user, TransferDirection::CashToWin, '100.00', $long);

        $stored = (string) $outcome->transfer->idempotency_key;

        // Truncation would make two distinct long keys sharing a prefix collide,
        // silently replaying the wrong transfer. Hashing keeps them distinct.
        $this->assertLessThanOrEqual(128, strlen($stored));
        $this->assertNotSame(substr($long, 0, 128), $stored);

        // Two long keys differing only after the column width still differ.
        $other = str_repeat('a', 200).'X'.str_repeat('b', 199);
        $outcome2 = $this->service->transfer($user, TransferDirection::CashToWin, '50.00', $other);

        $this->assertNotSame($stored, (string) $outcome2->transfer->idempotency_key);
    }

    // ------------------------------------------------------------------
    // Velocity
    // ------------------------------------------------------------------

    #[Test]
    public function the_cooldown_refuses_a_second_transfer_that_arrives_too_soon(): void
    {
        config(['transfer.directions.cash_to_win.cooldown_seconds' => 60]);

        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected the cooldown to refuse the second transfer.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_cooling_down', $e->machineCode());
            $this->assertSame(429, $e->httpStatus());
            $this->assertNotNull($e->retryAfter());
            $this->assertGreaterThan(0, (int) $e->retryAfter());
        }

        $this->assertSame(1, BalanceTransfer::query()->settled()->count());
    }

    #[Test]
    public function the_cooldown_does_not_apply_across_directions(): void
    {
        config([
            'transfer.directions.cash_to_win.cooldown_seconds' => 60,
            'transfer.directions.win_to_cash.cooldown_seconds' => 60,
        ]);

        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user, '1000.00');

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        // The other lane has its own cooldown clock.
        $outcome = $this->service->transfer($user, TransferDirection::WinToCash, '100.00');

        $this->assertFalse($outcome->isReplay());
    }

    #[Test]
    public function the_cooldown_allows_a_transfer_once_enough_time_has_passed(): void
    {
        config(['transfer.directions.cash_to_win.cooldown_seconds' => 60]);

        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        // An old completed transfer, outside the cooldown.
        BalanceTransfer::factory()
            ->for($user)
            ->direction(TransferDirection::CashToWin)
            ->completedSecondsAgo(120)
            ->create([
                'source_wallet_id' => Wallet::query()->where('user_id', $user->id)->where('type', WalletType::Primary->value)->value('id'),
                'destination_wallet_id' => Wallet::query()->where('user_id', $user->id)->where('type', WalletType::Win->value)->value('id'),
            ]);

        $outcome = $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        $this->assertFalse($outcome->isReplay());
    }

    #[Test]
    public function the_daily_count_limit_refuses_once_reached(): void
    {
        config(['transfer.directions.cash_to_win.daily_count_limit' => 2]);

        $user = $this->player();
        $this->cashWallet($user, '100000.00');
        $this->winWallet($user);

        $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'k1');
        $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'k2');

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'k3');
            $this->fail('Expected the daily count limit to refuse.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_daily_count_reached', $e->machineCode());
            $this->assertSame(429, $e->httpStatus());
        }

        $this->assertSame(2, BalanceTransfer::query()->settled()->count());
    }

    #[Test]
    public function the_daily_amount_limit_refuses_a_transfer_that_would_exceed_it(): void
    {
        config(['transfer.directions.cash_to_win.daily_amount_limit' => '250.00']);

        $user = $this->player();
        $this->cashWallet($user, '100000.00');
        $this->winWallet($user);

        $this->service->transfer($user, TransferDirection::CashToWin, '200.00', 'a1');

        try {
            // 200 already used, 100 more would make 300 > 250.
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'a2');
            $this->fail('Expected the daily amount limit to refuse.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_daily_amount_reached', $e->machineCode());
        }

        // A transfer that fits inside the remaining allowance is allowed.
        $this->service->transfer($user, TransferDirection::CashToWin, '50.00', 'a3');

        $this->assertSame(2, BalanceTransfer::query()->settled()->count());
    }

    #[Test]
    public function failed_attempts_do_not_consume_daily_allowance(): void
    {
        config(['transfer.directions.cash_to_win.daily_count_limit' => 1]);

        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        // A refusal, not a transfer.
        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '99999.00');
            $this->fail('Expected insufficient funds.');
        } catch (BalanceTransferException) {
            // expected
        }

        // The allowance must still be intact: a refusal is not a transfer.
        $outcome = $this->service->transfer($user, TransferDirection::CashToWin, '100.00');

        $this->assertFalse($outcome->isReplay());
    }

    // ------------------------------------------------------------------
    // Quoting
    // ------------------------------------------------------------------

    #[Test]
    public function a_quote_prices_the_lane_without_moving_anything(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user, '50.00');

        $quote = $this->service->quote($user, TransferDirection::CashToWin, '200.00');

        $this->assertSame('200.00', $quote->amount);
        $this->assertSame('2.00', $quote->fee);
        $this->assertSame('202.00', $quote->totalDebited);
        $this->assertSame('1000.00', $quote->sourceAvailable);
        $this->assertSame('50.00', $quote->destinationBalance);
        $this->assertSame('250.00', $quote->destinationAfter);
        $this->assertTrue($quote->affordable);
        $this->assertTrue($quote->isExecutable());

        // Nothing moved and nothing was written.
        $this->assertSame('1000.00', $this->balance($cash));
        $this->assertSame('50.00', $this->balance($win));
        $this->assertSame(0, BalanceTransfer::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    #[Test]
    public function a_quote_reports_unaffordable_without_throwing(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '10.00');
        $this->winWallet($user);

        // Below the lane minimum too, so both blockers are reported.
        $quote = $this->service->quote($user, TransferDirection::CashToWin, '100.00');

        $this->assertFalse($quote->affordable);
        $this->assertFalse($quote->isExecutable());
        $this->assertNotContains('transfer_below_minimum', $quote->blockers);
    }

    #[Test]
    public function a_quote_lists_limit_blockers_so_the_ui_can_explain_them(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '100000.00');
        $this->winWallet($user);

        $quote = $this->service->quote($user, TransferDirection::CashToWin, '10.00');

        $this->assertContains('transfer_below_minimum', $quote->blockers);
        $this->assertFalse($quote->isExecutable());
    }

    #[Test]
    public function the_quote_and_the_execution_agree_on_the_fee(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $quote = $this->service->quote($user, TransferDirection::CashToWin, '137.00');
        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '137.00')->transfer;

        // The single most important promise the UI makes: the number shown is
        // the number charged.
        $this->assertSame($quote->fee, (string) $transfer->fee_amount);
        $this->assertSame($quote->totalDebited, (string) $transfer->total_debited);
        $this->assertSame($quote->amount, (string) $transfer->amount);
    }

    // ------------------------------------------------------------------
    // Integrity
    // ------------------------------------------------------------------

    #[Test]
    public function every_completed_transfer_has_a_unique_reference_and_uuid(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '100000.00');
        $this->winWallet($user);

        $references = [];
        $uuids = [];

        for ($i = 0; $i < 5; $i++) {
            $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '100.00', 'loop-'.$i)->transfer;

            $references[] = (string) $transfer->reference_number;
            $uuids[] = (string) $transfer->uuid;

            $this->assertMatchesRegularExpression('/^[A-Z]{2,4}-[A-HJ-NP-Z2-9]{8}$/', (string) $transfer->reference_number);
        }

        $this->assertCount(5, array_unique($references));
        $this->assertCount(5, array_unique($uuids));
    }

    #[Test]
    public function a_reference_never_uses_an_ambiguous_character(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '100.00')->transfer;
        $suffix = substr((string) $transfer->reference_number, -BalanceTransfer::REFERENCE_SUFFIX_LENGTH);

        // 0/O and 1/I/L are excluded because the reference is read aloud to
        // support and typed back in by a human.
        $this->assertSame(0, preg_match('/[0O1IL]/', $suffix));
    }

    /**
     * The mass-assignment boundary, asserted from both directions.
     *
     * First: the fillable list itself, which is the durable contract.
     * Second: an actual create() carrying monetary attributes. The application
     * enables preventSilentlyDiscardingAttributes(), so in a test environment
     * that attempt THROWS rather than quietly dropping the fields — which is the
     * stronger guarantee, and the one worth pinning, because silent discarding is
     * exactly how the original wallets.version defect reached production.
     */
    #[Test]
    public function monetary_columns_are_not_mass_assignable(): void
    {
        $fillable = (new BalanceTransfer)->getFillable();

        $forbidden = [
            'amount',
            'fee_amount',
            'total_debited',
            'fee_rate',
            'status',
            'source_wallet_id',
            'destination_wallet_id',
            'source_balance_before',
            'source_balance_after',
            'destination_balance_before',
            'destination_balance_after',
            'debit_transaction_id',
            'credit_transaction_id',
            'fee_transaction_id',
            'reference_number',
            'uuid',
        ];

        foreach ($forbidden as $column) {
            $this->assertNotContains(
                $column,
                $fillable,
                "{$column} is mass assignable. Monetary and state columns must be "
                .'written only by the transfer service inside a locked transaction.',
            );
        }

        $this->assertNotSame([], (new BalanceTransfer)->getGuarded());
    }

    #[Test]
    public function attempting_to_mass_assign_monetary_columns_is_refused_outright(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');
        $win = $this->winWallet($user);

        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        BalanceTransfer::query()->create([
            'user_id' => $user->id,
            'direction' => TransferDirection::CashToWin,
            'currency' => Currency::THB,
            'amount' => '999999.00',
            'total_debited' => '999999.00',
            'status' => TransferStatus::Completed,
            'source_wallet_id' => $cash->id,
            'destination_wallet_id' => $win->id,
        ]);
    }

    #[Test]
    public function the_whole_transfer_commits_or_rolls_back_together(): void
    {
        $user = $this->player();
        $cash = $this->cashWallet($user, '1000.00');

        // A frozen DESTINATION wallet is discovered only after the source has
        // been resolved, which is the interesting rollback case.
        Wallet::factory()->create([
            'user_id' => $user->id,
            'type' => WalletType::Win,
            'currency' => Currency::THB,
            'status' => WalletStatus::Frozen,
            'balance' => '0.00',
            'locked_balance' => '0.00',
        ]);

        try {
            $this->service->transfer($user, TransferDirection::CashToWin, '100.00');
            $this->fail('Expected a refusal from the frozen destination wallet.');
        } catch (BalanceTransferException $e) {
            $this->assertSame('transfer_wallet_not_transactable', $e->machineCode());
        }

        // Nothing moved, and no transaction rows survived.
        $this->assertSame('1000.00', $this->balance($cash));
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    #[Test]
    public function two_players_balances_are_never_intermingled(): void
    {
        $alice = $this->player();
        $bob = $this->player();

        $aliceCash = $this->cashWallet($alice, '1000.00');
        $aliceWin = $this->winWallet($alice, '0.00');

        $bobCash = $this->cashWallet($bob, '700.00');
        $bobWin = $this->winWallet($bob, '0.00');

        $this->service->transfer($alice, TransferDirection::CashToWin, '100.00');
        $this->service->transfer($bob, TransferDirection::CashToWin, '50.00');

        $this->assertSame('899.00', $this->balance($aliceCash));
        $this->assertSame('100.00', $this->balance($aliceWin));
        $this->assertSame('649.50', $this->balance($bobCash));
        $this->assertSame('50.00', $this->balance($bobWin));

        // Each transfer row names only its own player's wallets.
        foreach (BalanceTransfer::query()->get() as $transfer) {
            $source = Wallet::query()->find($transfer->source_wallet_id);
            $destination = Wallet::query()->find($transfer->destination_wallet_id);

            $this->assertSame((int) $transfer->user_id, (int) $source->user_id);
            $this->assertSame((int) $transfer->user_id, (int) $destination->user_id);
        }
    }

    #[Test]
    public function the_engine_type_is_recorded_in_transaction_metadata(): void
    {
        $user = $this->player();
        $this->cashWallet($user, '1000.00');
        $this->winWallet($user);

        $transfer = $this->service->transfer($user, TransferDirection::CashToWin, '100.00')->transfer;

        $debit = FinancialTransaction::query()->find($transfer->debit_transaction_id);
        $fee = FinancialTransaction::query()->find($transfer->fee_transaction_id);

        $this->assertNotNull($debit);
        $this->assertNotNull($fee);

        // The persisted `type` column collapses fee -> adjustment, so the finer
        // engine vocabulary lives in metadata. Asserting it keeps that
        // documented limitation honest.
        $this->assertSame(
            FinancialTransactionType::Transfer->value,
            (string) ($debit->metadata['engine_type'] ?? ''),
        );
        $this->assertSame(
            FinancialTransactionType::Fee->value,
            (string) ($fee->metadata['engine_type'] ?? ''),
        );
    }

    #[Test]
    public function the_clearing_account_constant_is_seeded_in_the_chart_of_accounts(): void
    {
        $this->assertNotNull(
            DB::table('ledger_accounts')
                ->where('code', WalletService::ACCOUNT_WALLET_TRANSFER_CLEARING)
                ->first(),
            'The transfer clearing account must be seeded or every transfer fails to post.',
        );
    }
}
