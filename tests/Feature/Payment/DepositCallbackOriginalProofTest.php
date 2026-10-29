<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\PaymentReconciliationException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Payment\FinancialProviderReferenceService;
use App\Services\Payment\PaymentCallbackService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** These normalized DTOs are synthetic domain fixtures, not authenticated provider acceptance. */
final class DepositCallbackOriginalProofTest extends PaymentTestCase
{
    private function fixture(): array
    {
        $owner = $this->createPlayer('100.00');
        $row = app(DepositService::class)->request($owner['wallet'], Money::of('500.00', Currency::THB), PaymentMethod::Stripe,
            options: ['fee' => '10.00']);
        $payment = Payment::query()->create(['reference_number' => 'PAY-SYNTHETIC-'.bin2hex(random_bytes(8)),
            'user_id' => $owner['user']->id, 'payable_type' => Deposit::class, 'payable_id' => $row->id,
            'method' => PaymentMethod::Stripe, 'status' => PaymentStatus::Pending, 'currency' => Currency::THB,
            'amount' => '500.00', 'fee' => '10.00', 'gateway' => 'stripe',
            'gateway_reference' => 'SYNTHETIC-ORIGINAL-'.bin2hex(random_bytes(8)), 'metadata' => []]);

        return $owner + ['row' => $row, 'payment' => $payment];
    }

    private function originalCallback(Payment $payment, PaymentTransactionStatus $status = PaymentTransactionStatus::Succeeded, string $amount = '500.00'): PaymentCallbackData
    {
        return PaymentCallbackData::fromInput('stripe', $payment->gateway_reference, $amount, 'THB', $status, null, null, hash('sha256', 'SYNTHETIC-ONLY'));
    }

    public function test_callback_uses_original_gross_fee_and_canonical_net_credit_then_replays_without_writes(): void
    {
        $f = $this->fixture();
        $service = app(PaymentCallbackService::class);
        $this->assertFalse($service->apply($this->originalCallback($f['payment']))['replayed']);
        $this->assertSame('590.00', $f['wallet']->fresh()->balance);
        $this->assertSame('confirmed', $f['row']->fresh()->status->value);
        $this->assertSame(PaymentStatus::Captured, $f['payment']->fresh()->status);
        $before = DB::table(FinancialProviderReferenceService::TABLE)->sole();
        $this->assertTrue($service->apply($this->originalCallback($f['payment']))['replayed']);
        $this->assertEquals($before, DB::table(FinancialProviderReferenceService::TABLE)->sole());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertFalse($service->browserReturnProjection($f['payment']->reference_number, null, null, null)['found']);
        $this->assertTrue($service->browserReturnProjection($f['payment']->reference_number, null, null, $f['user']->id)['paid']);
        $this->actingAs($f['user'])->get('/payment/success?payment='.$f['payment']->reference_number)
            ->assertOk()->assertSee('Confirmed')->assertSee('The platform records this payment as complete.');
        $this->assertSame('590.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public static function refusedPaymentFacts(): array
    {
        return array_map(fn ($s) => [$s], ['fee', 'gross', 'deleted', 'duplicate', 'captured', 'failed', 'disputed', 'partially_refunded', 'reversed', 'net_instead_of_gross']);
    }

    #[DataProvider('refusedPaymentFacts')]
    public function test_contradictory_or_terminal_original_payment_cannot_be_reinterpreted_as_a_new_credit(string $kind): void
    {
        $f = $this->fixture();
        $p = $f['payment'];
        if ($kind === 'fee') {
            $p->fee = '0.00';
        } elseif ($kind === 'gross') {
            $p->amount = '490.00';
        } elseif ($kind === 'deleted') {
            $p->delete();
        } elseif ($kind === 'duplicate') {
            $duplicate = $p->replicate();
            $duplicate->reference_number = 'PAY-SYNTHETIC-DUPLICATE';
            $duplicate->gateway_reference = 'SYNTHETIC-OTHER-REFERENCE';
            try {
                $duplicate->save();
                $this->fail('The one-payable constraint must reject a duplicate original Payment.');
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $this->assertDatabaseCount('payments', 1);
                $this->assertSame(PaymentStatus::Pending, $p->fresh()->status);
                $this->assertSame('100.00', $f['wallet']->fresh()->balance);
                $this->assertDatabaseCount('financial_transactions', 0);
                $this->assertDatabaseCount('ledger_entries', 0);
                $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);

                return;
            }
        } elseif (in_array($kind, ['captured', 'failed', 'disputed', 'partially_refunded'], true)) {
            $p->status = PaymentStatus::from($kind);
        }
        $p->save();
        $callback = $this->originalCallback($p, $kind === 'reversed' ? PaymentTransactionStatus::Reversed : PaymentTransactionStatus::Succeeded,
            $kind === 'net_instead_of_gross' ? '490.00' : '500.00');
        try {
            app(PaymentCallbackService::class)->apply($callback);
            $this->fail('Ambiguous or contradictory original evidence must be held.');
        } catch (PaymentReconciliationException|FinancialException) {
            $this->assertSame('100.00', $f['wallet']->fresh()->balance);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        }
    }

    public function test_captured_marker_without_actual_credit_is_not_paid_and_browser_cannot_heal_it(): void
    {
        $f = $this->fixture();
        $f['payment']->update(['status' => PaymentStatus::Captured]);
        $out = app(PaymentCallbackService::class)->browserReturnProjection($f['payment']->reference_number, null, null, $f['user']->id);
        $this->assertFalse($out['paid']);
        $this->assertSame('pending', $out['state']);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
    }

    public function test_valid_looking_rewritten_request_terms_cannot_override_server_original_snapshot(): void
    {
        $f = $this->fixture();
        $f['row']->update(['amount' => '900.00', 'fee' => '0.00', 'net_amount' => '900.00']);
        $f['payment']->update(['amount' => '900.00', 'fee' => '0.00']);
        $this->expectException(FinancialException::class);
        try {
            app(PaymentCallbackService::class)->apply($this->originalCallback($f['payment']->fresh(), amount: '900.00'));
        } finally {
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        }
    }
}
