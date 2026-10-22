<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PayoutTransferData;
use App\Enums\Currency;
use App\Enums\PrizePayoutMethod;
use App\Exceptions\PayoutTransferException;
use App\Models\Payment;
use App\Services\Payment\PayoutTransferService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class PayoutTransferIdentityTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_open_records_one_payment_without_wallet_or_external_invocation(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $first = $service->transfer($f['data']);
        $this->assertSame($first, $service->transfer($f['data']));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('processing', $f['row']->fresh()->status->value);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertLessThanOrEqual(64, strlen($first['payment_reference']));
    }

    public static function mutations(): array
    {
        return array_map(fn ($key) => [$key], ['beneficiaryUserId', 'currency', 'method', 'amount', 'batchKey', 'idempotencyKey', 'beneficiary']);
    }

    #[DataProvider('mutations')]
    public function test_changed_identity_cannot_hide_behind_a_replay_key(string $field): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $d = $f['data'];
        $args = ['batchKey' => $d->batchKey, 'payoutReference' => $d->payoutReference, 'beneficiaryUserId' => $d->beneficiaryUserId,
            'method' => $d->method, 'amount' => $d->amount, 'currency' => $d->currency, 'beneficiary' => $d->beneficiary, 'idempotencyKey' => $d->idempotencyKey];
        $args[$field] = match ($field) {
            'beneficiaryUserId' => $f['maker']->id, 'currency' => Currency::USD, 'method' => PrizePayoutMethod::Cheque,
            'amount' => '401.00', 'beneficiary' => ['account_number' => 'OTHER'], default => 'CHANGED',
        };
        $this->expectException(PayoutTransferException::class);
        app(PayoutTransferService::class)->transfer(new PayoutTransferData(...$args));
    }

    public function test_disabled_safety_mode_blocks_open(): void
    {
        $f = $this->externalFixture();
        config(['finance.prize_payout.safety_mode' => 'DISABLED']);
        $this->expectException(PayoutTransferException::class);
        app(PayoutTransferService::class)->transfer($f['data']);
    }

    public function test_pending_approval_cannot_open(): void
    {
        $f = $this->externalFixture(false);
        $this->expectException(PayoutTransferException::class);
        app(PayoutTransferService::class)->transfer($f['data']);
    }

    public function test_malformed_historical_stamp_is_not_treated_as_absent(): void
    {
        $f = $this->externalFixture();
        $metadata = $f['row']->metadata;
        $metadata['transfer'] = 'corrupt';
        $f['row']->metadata = $metadata;
        $f['row']->save();
        $this->expectException(PayoutTransferException::class);
        app(PayoutTransferService::class)->transfer($f['data']);
    }
}
