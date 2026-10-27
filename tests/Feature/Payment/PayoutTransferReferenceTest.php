<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\PayoutTransferException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Services\Payment\PayoutTransferReferenceService;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class PayoutTransferReferenceTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public static function badReferences(): array
    {
        return [[''], [' PADDED '], ["INVALID\nREFERENCE"], [str_repeat('x', 256)]];
    }

    #[DataProvider('badReferences')]
    public function test_invalid_reference_never_records_finality(string $ref): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $this->expectException(PayoutTransferException::class);
        $service->complete($f['data'], $ref, $f['reviewer'], true, 'Independently verified synthetic provider result.');
    }

    public function test_one_provider_reference_cannot_complete_two_distinct_prizes(): void
    {
        $a = $this->externalFixture();
        $b = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        foreach ([$a, $b] as $f) {
            $service->transfer($f['data']);
        }
        $service->complete($a['data'], 'SYNTHETIC-SHARED', $a['reviewer'], true, 'Independently verified owner provider result.');
        try {
            $service->complete($b['data'], 'SYNTHETIC-SHARED', $b['reviewer'], true, 'Foreign obligation must not reuse external transfer.');
            $this->fail('Completed two obligations with one provider result.');
        } catch (PayoutTransferException) {
            $this->assertSame('processing', $b['row']->fresh()->status->value);
            $this->assertDatabaseCount('financial_transactions', 1);
            $this->assertDatabaseCount('payout_transfer_references', 1);
        }
    }

    public function test_deleted_history_still_owns_its_reference(): void
    {
        $a = $this->externalFixture();
        $b = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($a['data']);
        $service->transfer($b['data']);
        $service->complete($a['data'], 'SYNTHETIC-HISTORY', $a['reviewer'], true, 'Independently verified synthetic provider result.');
        Payment::query()->where('payable_id', $a['row']->id)->sole()->delete();
        $a['row']->delete();
        $this->expectException(PayoutTransferException::class);
        $service->complete($b['data'], 'SYNTHETIC-HISTORY', $b['reviewer'], true, 'Soft deletion does not surrender financial ownership.');
    }

    public function test_account_rotation_blocks_old_lane_instead_of_reinterpreting_it(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'DIFFERENT-ACCOUNT']);
        $this->expectException(PayoutTransferException::class);
        $service->complete($f['data'], 'SYNTHETIC-ACCOUNT-DRIFT', $f['reviewer'], true, 'Original account cannot be silently reinterpreted.');
    }

    public function test_populated_ownership_schema_refuses_destructive_rollback(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $service->complete($f['data'], 'SYNTHETIC-RETAIN', $f['reviewer'], true, 'Independently verified synthetic provider result.');
        $migration = require base_path('database/migrations/2026_10_15_000300_create_payout_transfer_references_table.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_server_concurrent_duplicate_confirmation_posts_one_transaction(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $command = ['payout_id' => (int) $f['row']->id, 'actor_id' => (int) $f['reviewer']->id, 'reference' => 'SYNTHETIC-CONCURRENT-OWNER'];
        $out = $this->payoutServerRace([$command, $command], 'payouts', (int) $f['row']->id);
        $this->assertSame(['completed', 'completed'], $out);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_server_distinct_prizes_cannot_share_one_provider_reference(): void
    {
        $this->requirePayoutServerRace();
        $a = $this->externalFixture();
        $b = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($a['data']);
        $service->transfer($b['data']);
        $reference = 'SYNTHETIC-CONCURRENT-SHARED';
        DB::transaction(fn () => app(PayoutTransferReferenceService::class)->bind($a['row']->fresh(),
            Payment::query()->where('payable_id', $a['row']->id)->sole(), $reference));
        $id = (int) DB::table('payout_transfer_references')->sole()->id;
        $commands = array_map(fn ($f) => ['payout_id' => (int) $f['row']->id, 'actor_id' => (int) $f['reviewer']->id, 'reference' => $reference], [$a, $b]);
        $this->assertNotSame($commands[0]['payout_id'], $commands[1]['payout_id']);
        $out = $this->payoutServerRace($commands, 'payout_transfer_references', $id);
        $this->assertSame(['completed', 'refused'], $out);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame('processing', $b['row']->fresh()->status->value);
    }

    public function test_known_global_payment_reference_collision_is_refused_before_booking(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        Payment::query()->create(['reference_number' => 'SYNTHETIC-OTHER-DOMAIN', 'user_id' => $f['owner']['user']->id,
            'payable_type' => Deposit::class, 'payable_id' => 999999, 'method' => 'bank_transfer',
            'gateway' => 'bank_transfer', 'gateway_reference' => 'SYNTHETIC-GLOBAL-COLLISION',
            'currency' => 'THB', 'amount' => '400.00', 'fee' => '0.00']);
        try {
            $service->complete($f['data'], 'SYNTHETIC-GLOBAL-COLLISION', $f['reviewer'], true, 'Foreign Payment storage identity must not be reused.');
            $this->fail('Ignored global Payment storage constraint.');
        } catch (PayoutTransferException) {
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount('payout_transfer_references', 0);
        }
    }
}
