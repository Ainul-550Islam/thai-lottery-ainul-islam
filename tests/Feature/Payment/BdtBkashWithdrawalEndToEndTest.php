<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Payment\ManualDisbursementQueueService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * A 2000 taka bKash withdrawal from the player's request to the moment the money
 * leaves the wallet. Money may leave only after: staff approval, a payout made
 * outside this system, evidence of it, and an independent second reviewer.
 * No step ever contacts bKash.
 */
final class BdtBkashWithdrawalEndToEndTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.withdrawal.enabled' => true,
            'payment.withdrawal.allowed_methods' => ['bkash', 'nagad'],
            'finance.withdrawal.min' => '1000.00',
            'payment.withdrawal.min' => '1000.00',
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.bkash.mode' => 'manual',
            'payment.gateways.bkash.merchant_number' => '01822222222',
        ]);
    }

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create(['status' => UserStatus::Active]);
        $staff->assignRole('admin');

        return $staff;
    }

    /** @return array<string, int|string> */
    private function money(array $player): array
    {
        $wallet = $player['wallet']->fresh();

        return [
            'balance' => (string) $wallet->balance,
            'locked' => (string) $wallet->locked_balance,
            'withdrawal_entries' => DB::table('financial_transactions')->where('type', 'withdrawal')->count(),
        ];
    }

    public function test_2000_taka_leaves_the_wallet_only_after_approval_evidence_and_an_independent_checker(): void
    {
        $player = $this->createAdmittedPlayer('5000.00', Currency::BDT);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/withdrawals', [
            'amount' => '2000.00',
            'currency' => 'BDT',
            'method' => 'bkash',
            'payout_details' => ['account_number' => '01712345678'],
            'idempotency_key' => 'bd-bkash-e2e-withdrawal-2000',
        ])->assertCreated();

        $row = Withdrawal::query()->firstOrFail();
        self::assertSame(WithdrawalStatus::Pending, $row->status);
        self::assertSame(['balance' => '5000.00', 'locked' => '2000.00', 'withdrawal_entries' => 0], $this->money($player));

        // 1. A request that nobody approved can never be dispatched for payout.
        $refused = false;
        try {
            app(WithdrawalDisbursementService::class)->disburse($row->fresh());
        } catch (\Throwable) {
            $refused = true;
        }
        self::assertTrue($refused, 'A pending withdrawal must not be dispatched.');
        self::assertSame(WithdrawalStatus::Pending, $row->fresh()->status);
        self::assertSame(0, Payment::query()->where('payable_type', Withdrawal::class)->count());
        self::assertSame(0, DB::table('manual_disbursement_tasks')->where('withdrawal_id', $row->id)->count());
        self::assertSame(['balance' => '5000.00', 'locked' => '2000.00', 'withdrawal_entries' => 0], $this->money($player));

        // 2. Staff approve; the money is still not gone.
        app(WithdrawalApprovalService::class)->approve($row->fresh());
        self::assertSame(WithdrawalStatus::Approved, $row->fresh()->status);
        self::assertSame(['balance' => '5000.00', 'locked' => '2000.00', 'withdrawal_entries' => 0], $this->money($player));

        // 3. "Start payout dispatch" only queues it for a person; nothing is sent to bKash.
        $dispatch = app(WithdrawalDisbursementService::class)->disburse($row->fresh());
        self::assertTrue($dispatch->isPending);
        self::assertNull($dispatch->providerReference);
        self::assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        self::assertSame(1, DB::table('manual_disbursement_tasks')->where('withdrawal_id', $row->id)->count());
        self::assertSame(['balance' => '5000.00', 'locked' => '2000.00', 'withdrawal_entries' => 0], $this->money($player));
        Http::assertNothingSent();

        // 4. Staff pay from the bKash app, then a maker records the evidence.
        $maker = $this->staff();
        $checker = $this->staff();
        $task = DB::table('manual_disbursement_tasks')->where('withdrawal_id', $row->id)->sole();
        app(ManualDisbursementQueueService::class)->claim((int) $task->id, (int) $maker->id, 'bd-bkash-e2e-claim-0001');
        $row = $row->fresh();
        $facts = WithdrawalReconciliationFacts::fromArray([
            'internal_reference' => $row->reference_number,
            'provider' => 'bkash',
            'provider_reference' => 'BKASH9H8X7G6F5D',
            'currency' => 'BDT',
            'gross_amount' => '2000.00',
            'net_amount' => (string) $row->net_amount,
            'outcome' => 'completed',
            'observed_at' => now()->addSecond()->toIso8601String(),
        ]);
        $case = app(WithdrawalReconciliationService::class)->request(
            $maker,
            (int) $row->id,
            $facts,
            UploadedFile::fake()->createWithContent('bkash-statement.txt', 'Synthetic test evidence, not a genuine bKash statement.'),
        );
        self::assertSame(['balance' => '5000.00', 'locked' => '2000.00', 'withdrawal_entries' => 0], $this->money($player));

        // 5. The person who recorded the evidence cannot approve their own case.
        $selfApproved = false;
        try {
            app(WithdrawalReconciliationService::class)->decide($maker, $case, 'apply', 'Approving my own evidence.', true);
            $selfApproved = true;
        } catch (\Throwable) {
            // expected
        }
        self::assertFalse($selfApproved, 'A maker must not be able to approve their own case.');
        self::assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        self::assertSame(['balance' => '5000.00', 'locked' => '2000.00', 'withdrawal_entries' => 0], $this->money($player));

        // 6. An independent checker applies it: now, and only now, the 2000 taka leaves.
        $applied = app(WithdrawalReconciliationService::class)->decide(
            $checker,
            $case,
            'apply',
            'An independent checker reviewed this synthetic evidence.',
            true,
        );
        self::assertSame('applied', $applied->status);
        self::assertNotSame($applied->maker_id, $applied->checker_id);
        self::assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        self::assertSame(['balance' => '3000.00', 'locked' => '0.00', 'withdrawal_entries' => 1], $this->money($player));
        Http::assertNothingSent();
    }
}
