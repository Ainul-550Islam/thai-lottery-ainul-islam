<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Prize\PrizeDisbursementData;
use App\Enums\PrizeDisbursementStatus;
use App\Exceptions\PrizeDisbursementException;
use App\Jobs\ExecutePayoutTransferJob;
use App\Jobs\GeneratePayoutBatchJob;
use App\Jobs\ProcessPayoutBatchJob;
use App\Models\PrizeDisbursement;
use App\Models\Wallet;
use App\Services\Finance\ApprovedWalletPayoutService;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchService;
use App\Services\Finance\WalletService;
use App\Services\Payment\PayoutTransferService;
use App\Services\Prize\PrizeDisbursementService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class ApprovedWalletPayoutTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_wallet_execution_credits_original_wallet_once_and_completes_approval(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $service = app(ApprovedWalletPayoutService::class);
        $this->assertSame('paid', $service->execute($f['row']->id)['result']);
        $this->assertSame('replayed', $service->execute($f['row']->id)['result']);
        $this->assertSame('1400.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertSame('completed', $f['row']->fresh()->metadata['approval']['status']);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_wallet_worker_never_credits_an_external_payout(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $out = (new ProcessPayoutBatchJob($batch->id, [$f['row']->id]))->handle(app(WalletService::class));
        $this->assertSame(0, $out['paid']);
        $this->assertSame('pending', $f['row']->fresh()->status->value);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_revoked_maker_is_held_not_permanently_failed_by_worker(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $f['maker']->syncRoles([]);
        $out = (new ProcessPayoutBatchJob($batch->id, [$f['row']->id]))->handle(app(WalletService::class));
        $this->assertSame(1, $out['held']);
        $this->assertSame('pending', $f['row']->fresh()->status->value);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_wallet_job_finalizes_batch_from_actual_outcomes(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $job = new ProcessPayoutBatchJob($batch->id, [$f['row']->id]);
        $this->assertSame(1, $job->handle(app(WalletService::class))['paid']);
        $this->assertSame('completed', $batch->fresh()->status->value);
        $this->assertSame(1, $job->handle(app(WalletService::class))['replayed']);
        $this->assertSame(1, $batch->fresh()->paid_count);
    }

    public function test_external_job_opens_record_without_wallet_credit(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $out = (new ExecutePayoutTransferJob($batch->batch_key))->handle(app(PayoutTransferService::class));
        $this->assertSame(1, $out['opened']);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generator_separates_wallet_and_bank_batches_and_queues_no_money_immediately(): void
    {
        $a = $this->approvedIntake();
        $b = $this->approvedIntake('bank_transfer');
        $out = (new GeneratePayoutBatchJob(200))->handle();
        $this->assertSame(2, $out['dispatched']);
        $this->assertDatabaseCount('payout_batches', 2);
        $this->assertDatabaseCount('payout_batch_members', 2);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function walletCurrencies(): array
    {
        return [['USD'], ['BDT']];
    }

    #[DataProvider('walletCurrencies')]
    public function test_currency_specific_original_wallet_is_credited_without_fx(string $currency): void
    {
        $f = $this->unrequestedPayout();
        $wallet = Wallet::factory()->create(['user_id' => $f['owner']['user']->id, 'currency' => $currency, 'balance' => '1000.00']);
        $f['row']->update(['wallet_id' => $wallet->id, 'currency' => $currency]);
        $intake = app(PayoutApprovalIntakeService::class);
        $intake->request($f['maker'], $f['row']->id, 'wallet', null, 'Synthetic original currency wallet request.');
        $intake->decide($f['checker'], $f['row']->id, 'approved', true, 'Independent synthetic currency wallet review.');
        $batch = $intake->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        (new ProcessPayoutBatchJob($batch->id, [$f['row']->id]))->handle(app(WalletService::class));
        $this->assertSame('1400.00', (string) $wallet->fresh()->balance);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertSame($currency, $f['row']->fresh()->financialTransaction->currency->value);
        $this->assertSame('completed', $batch->fresh()->status->value);
    }

    public function test_legacy_reservation_cannot_take_a_managed_approval(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $service = app(PrizeDisbursementService::class);
        $data = new PrizeDisbursementData($f['row']->id, null, '400.00', 'THB', $service::fingerprintFor($f['row']));
        $this->expectException(PrizeDisbursementException::class);
        $this->expectExceptionMessage('managed_payout_route_required');
        $service->reserve($data);
    }

    public static function legacyMutations(): array
    {
        return [['disburse'], ['reverse'], ['pronounceFailed']];
    }

    #[DataProvider('legacyMutations')]
    public function test_legacy_status_only_journal_cannot_mutate_managed_payout(string $action): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $legacy = PrizeDisbursement::query()->create(['payout_id' => $f['row']->id, 'amount' => '400.00', 'currency' => 'THB', 'status' => PrizeDisbursementStatus::Reserved]);
        try {
            app(PrizeDisbursementService::class)->{$action}($legacy, 'Synthetic blocked legacy mutation.');
            $this->fail('A legacy journal must not mutate a managed route.');
        } catch (PrizeDisbursementException $e) {
            $this->assertStringContainsString('managed_payout_route_required', $e->getMessage());
        }
        $this->assertSame('pending', $f['row']->fresh()->status->value);
        $this->assertSame(PrizeDisbursementStatus::Reserved, $legacy->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
    }
}
