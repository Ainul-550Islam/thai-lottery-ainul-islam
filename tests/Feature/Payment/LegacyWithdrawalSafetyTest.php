<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\FinancialException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Withdrawal\WithdrawalProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The legacy request table has neither owned holds nor provider payout proof. */
final class LegacyWithdrawalSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_intake_cannot_create_an_unowned_or_fractionally_truncated_hold(): void
    {
        [$user, $wallet] = $this->player();
        $before = $this->snapshot($wallet);
        foreach (['100.00', '100.0199'] as $amount) {
            $this->assertLegacyRefused(fn () => app(WithdrawalProcessingService::class)->requestWithdrawal(
                (string) $user->id, $amount, 'BANK_TRANSFER', 'TEST', 'NOT-PAYABLE', currency: 'THB',
            ));
            self::assertSame($before, $this->snapshot($wallet));
        }
    }

    public function test_legacy_disbursement_cannot_debit_or_complete_without_approved_provider_evidence(): void
    {
        [$user, $wallet] = $this->player();
        DB::table('wallets')->where('id', $wallet->id)->update(['locked_balance' => '100.00']);
        foreach (['PENDING_APPROVAL', 'APPROVED'] as $status) {
            $request = $this->legacyRequest($user, $status);
            if ($status === 'APPROVED') {
                $request->update(['approved_by_operator_id' => (string) Str::uuid(), 'approved_at' => now()]);
            }
            $before = $this->snapshot($wallet, $request);
            $this->assertLegacyRefused(fn () => app(WithdrawalProcessingService::class)->disburseWithdrawal(
                $request->id, (string) Str::uuid(),
            ));
            self::assertSame($before, $this->snapshot($wallet, $request));
        }
    }

    public function test_legacy_rejection_cannot_release_an_unowned_aggregate_wallet_hold(): void
    {
        [$user, $wallet] = $this->player();
        // This locked amount may belong to a different canonical obligation;
        // the legacy request row has no hold-ownership reference.
        DB::table('wallets')->where('id', $wallet->id)->update(['locked_balance' => '100.00']);
        $request = $this->legacyRequest($user, 'PENDING_APPROVAL');
        $before = $this->snapshot($wallet, $request);
        $this->assertLegacyRefused(fn () => app(WithdrawalProcessingService::class)->rejectWithdrawal(
            $request->id, 'unverified-operator', 'test',
        ));
        self::assertSame($before, $this->snapshot($wallet, $request));
    }

    private function assertLegacyRefused(callable $operation): void
    {
        $error = null;
        try {
            $operation();
        } catch (FinancialException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(FinancialException::class, $error, 'An unverified legacy withdrawal transition ran.');
        self::assertSame('legacy_withdrawal_reconciliation_required', $error->errorCode());
    }

    private function player(): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'type' => 'main', 'currency' => 'THB']);
        $wallet->forceFill(['status' => 'active', 'balance' => '1000.00'])->save();

        return [$user, $wallet];
    }

    private function legacyRequest(User $user, string $status): WithdrawalRequest
    {
        return WithdrawalRequest::create([
            'id' => (string) Str::uuid(), 'user_id' => (string) $user->id,
            'withdrawal_number' => 'WTH-LEGACY-'.Str::random(10), 'payout_channel' => 'BANK_TRANSFER',
            'account_name' => 'TEST', 'account_number' => 'NOT-PAYABLE', 'amount' => '100.0000',
            'fee_amount' => '0.0000', 'net_disbursement_amount' => '100.0000', 'currency' => 'THB',
            'status' => $status, 'idempotency_key' => 'legacy-test-'.Str::uuid(),
        ]);
    }

    private function snapshot(Wallet $wallet, ?WithdrawalRequest $request = null): array
    {
        $wallet->refresh();
        return [
            'balance' => (string) $wallet->balance,
            'locked_balance' => (string) $wallet->locked_balance,
            'wallet_version' => $wallet->version,
            'request' => $request?->fresh()?->getAttributes(),
            'request_count' => WithdrawalRequest::query()->count(),
            'financial_transaction_count' => DB::table('financial_transactions')->count(),
            'ledger_transaction_count' => DB::table('ledger_transactions')->count(),
            'ledger_entry_count' => DB::table('ledger_entries')->count(),
        ];
    }
}
