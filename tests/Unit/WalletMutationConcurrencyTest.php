<?php

declare(strict_types=1);

namespace Tests\Unit;

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

        $wallet = Wallet::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'type' => 'main',
            'currency' => 'THB',
            'status' => 'active',
        ]);

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

        $this->assertEquals('649.5000', $updatedWallet->balance);
        $this->assertEquals(2, $updatedWallet->version);
    }

    public function test_debit_fails_and_rolls_back_when_funds_insufficient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient funds');

        $userId = Str::uuid()->toString();

        $wallet = Wallet::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'type' => 'main',
            'currency' => 'THB',
            'status' => 'active',
        ]);

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
