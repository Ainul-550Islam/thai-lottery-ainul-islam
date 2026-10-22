<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Jobs\ExecutePayoutTransferJob;
use App\Models\Payment;
use App\Services\Payment\PayoutTransferService;
use Tests\Support\CreatesExternalPayout;

final class PayoutTransferJobTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_job_opens_only_record_once_and_does_not_send_or_complete(): void
    {
        $f = $this->externalFixture();
        $job = new ExecutePayoutTransferJob($f['data']->batchKey);
        $first = $job->handle(app(PayoutTransferService::class));
        $this->assertSame(1, $first['opened']);
        $this->assertSame(0, $job->handle(app(PayoutTransferService::class))['selected']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('pending', Payment::query()->sole()->status->value);
    }

    public function test_unknown_method_is_refused_instead_of_defaulted_to_bank(): void
    {
        $f = $this->externalFixture();
        $metadata = $f['row']->metadata;
        $metadata['claim']['payout_method'] = 'unapproved-rail';
        $f['row']->metadata = $metadata;
        $f['row']->save();
        $r = (new ExecutePayoutTransferJob($f['data']->batchKey))->handle(app(PayoutTransferService::class));
        $this->assertSame(1, $r['failed']);
        $this->assertSame(0, $r['opened']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_replayed_job_does_not_skip_current_revocation_guard(): void
    {
        $f = $this->externalFixture();
        $f['maker']->syncRoles([]);
        $r = (new ExecutePayoutTransferJob($f['data']->batchKey))->handle(app(PayoutTransferService::class));
        $this->assertSame(1, $r['failed']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('PAYOUT_AUTHORITY_OR_FINANCIAL_GUARD', $r['failures'][0]['code']);
    }
}
