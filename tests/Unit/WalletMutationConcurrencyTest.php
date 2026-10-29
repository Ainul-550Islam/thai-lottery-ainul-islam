<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\FinancialException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WalletMutationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private WalletMutationService $walletService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletService = app(WalletMutationService::class);
    }

    public function test_debit_decreases_balance_and_increments_version_within_transaction(): void
    {
        $userId = Str::uuid()->toString();

        $user = User::factory()->create();
        $userId = (string) $user->getKey();
        $wallet = Wallet::create([
            'user_id' => $user->getKey(),
            'type' => 'main',
            'currency' => 'THB',
        ]);
        $wallet->forceFill(['status' => 'active'])->save();

        // Direct DB update for test initial balance setup
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '1000.0000', 'version' => 1]);

        $updatedWallet = DB::transaction(function () use ($userId) {
            return $this->walletService->debit(
                userId: $userId,
                amount: '350.5000',
                referenceType: 'TEST_DEBIT',
                referenceId: 'REF-001',
                currency: 'THB'
            );
        });

        $this->assertSame(0, bccomp('649.5000', (string) $updatedWallet->balance, 4));
        $this->assertEquals(2, $updatedWallet->version);
    }

    public function test_debit_fails_and_rolls_back_when_funds_insufficient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient funds');

        $userId = Str::uuid()->toString();

        $user = User::factory()->create();
        $userId = (string) $user->getKey();
        $wallet = Wallet::create([
            'user_id' => $user->getKey(),
            'type' => 'main',
            'currency' => 'THB',
        ]);
        $wallet->forceFill(['status' => 'active'])->save();

        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '100.0000', 'version' => 1]);

        DB::transaction(function () use ($userId) {
            $this->walletService->debit(
                userId: $userId,
                amount: '150.0000', // Exceeds 100.0000
                referenceType: 'TEST_OVERDRAFT',
                referenceId: 'REF-FAIL',
                currency: 'THB'
            );
        });
    }

    public function test_legacy_credit_cannot_bypass_the_configured_wallet_cap(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'type' => 'main', 'currency' => 'THB']);
        $wallet->forceFill(['status' => 'active', 'balance' => '100.00'])->save();
        config(['finance.wallet.max_balance' => '100.50']);
        $before = DB::table('ledger_transactions')->count();

        try {
            DB::transaction(fn () => $this->walletService->credit(
                (string) $user->id, '0.51', 'TEST_CAP', 'CAP-01', 'THB',
            ));
            $this->fail('A credit exceeding the configured cap must be refused.');
        } catch (FinancialException $exception) {
            $this->assertSame('wallet_maximum_balance_exceeded', $exception->errorCode());
        }

        $this->assertSame('100.00', (string) $wallet->fresh()->balance);
        $this->assertSame('100.50', config('finance.wallet.max_balance'));
        $this->assertSame($before, DB::table('ledger_transactions')->count());
    }

    public function test_fractional_minor_units_cannot_diverge_the_wallet_and_ledger(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'type' => 'main', 'currency' => 'THB']);
        $wallet->forceFill(['status' => 'active', 'balance' => '100.00'])->save();
        $before = DB::table('ledger_transactions')->count();

        foreach (['credit', 'debit'] as $method) {
            try {
                DB::transaction(fn () => $this->walletService->{$method}(
                    (string) $user->id, '0.0199', 'TEST_PRECISION', 'PRECISION-'.$method, 'THB',
                ));
                $this->fail('A nonzero fractional minor unit must be refused.');
            } catch (FinancialException $exception) {
                $this->assertSame('money_precision_loss', $exception->errorCode());
            }
            $this->assertSame('100.00', (string) $wallet->fresh()->balance);
            $this->assertSame($before, DB::table('ledger_transactions')->count());
        }
    }

    public function test_mutations_outside_transaction_throw_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Wallet operations must run inside an active database transaction');

        $userId = Str::uuid()->toString();

        $this->walletService->debit(
            userId: $userId,
            amount: '50.0000',
            referenceType: 'NO_TX',
            referenceId: 'REF-00',
            currency: 'THB'
        );
    }
}
