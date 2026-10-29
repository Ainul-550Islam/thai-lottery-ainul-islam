<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentProcessingResult;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\PaymentWebhookException;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises the isolated compatibility parser through the real wallet and
 * ledger boundary. The public Nagad ingress stays closed: this locally computed
 * HMAC is synthetic and does not establish provider authentication.
 */
final class NagadWebhookExactJsonTest extends PaymentTestCase
{
    public static function ambiguousBodies(): array
    {
        return [
            'sub-cent value rounded to the local deposit' => ['"amount":400.00000000000000001'],
            'scientific numeric notation' => ['"amount":4e2'],
            'excess fractional digits erased by JSON float conversion' => ['"amount":400.000'],
            'duplicate amounts' => ['"amount":400.01,"amount":400.00'],
            'escaped duplicate amount' => ['"amount":400.01,"am\\u006funt":400.00'],
            'duplicate currency' => ['"currency":"USD","amount":400.00'],
        ];
    }

    #[DataProvider('ambiguousBodies')]
    public function test_ambiguous_signed_json_never_credits_in_synthetic_prototype(string $amountFields): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Nagad);
        $body = $this->body($deposit->reference_number, $amountFields);
        $request = $this->signedRequest($body);
        self::assertTrue($this->gatewayManager()->driver('nagad')->verifyWebhookSignature($request));

        $result = $this->runSyntheticPrototype($request);

        self::assertFalse($result->success, 'Ambiguous signed money evidence cannot settle a deposit.');
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertSame(0, Payment::query()->count());
    }

    public function test_valid_signed_exact_numeric_amount_credits_once_in_synthetic_prototype(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Nagad);
        $request = $this->signedRequest($this->body($deposit->reference_number, '"amount":400.00'));
        self::assertTrue($this->gatewayManager()->driver('nagad')->verifyWebhookSignature($request));

        $result = $this->runSyntheticPrototype($request);

        self::assertTrue($result->success);
        self::assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(1, FinancialTransaction::query()->count());
        self::assertSame(2, LedgerEntry::query()->count());
        $replay = $this->runSyntheticPrototype($request);
        self::assertTrue($replay->success);
        self::assertTrue($replay->replayed);
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(1, FinancialTransaction::query()->count());
        self::assertSame(2, LedgerEntry::query()->count());
    }

    public function test_http_route_refuses_duplicate_keys_without_marking_them_as_accepted(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Nagad);
        $body = $this->body($deposit->reference_number, '"amount":400.01,"amount":400.00');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_NAGAD_SIGNATURE' => hash_hmac('sha256', $body, self::NAGAD_WEBHOOK_SECRET),
        ];

        // A refused body must not enter the accepted-replay cache; repeating
        // the same ambiguous bytes is refused again, not acknowledged as paid.
        for ($i = 0; $i < 2; $i++) {
            $response = $this->call('POST', '/api/v1/payments/webhook/nagad', [], [], [], $server, $body);
            $response->assertStatus(422)->assertJsonPath('success', false);
        }
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertSame(0, Payment::query()->count());
    }

    public function test_unsigned_body_does_not_credit(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Nagad);
        $request = $this->signedRequest($this->body($deposit->reference_number, '"amount":400.00'));
        $request->headers->set('X-Nagad-Signature', 'invalid');

        self::assertFalse($this->webhookService()->handleWebhookRequest('nagad', $request)->success);
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
    }

    /** Exercise parsing and settlement in isolation, not the closed public ingress. */
    private function runSyntheticPrototype(Request $request): PaymentProcessingResult
    {
        $driver = $this->gatewayManager()->driver('nagad');
        if (! $driver->verifyWebhookSignature($request)) {
            return PaymentProcessingResult::failed('nagad', 'Invalid synthetic signature.');
        }
        try {
            $payload = $driver->parseWebhook($request);
        } catch (PaymentWebhookException) {
            return PaymentProcessingResult::failed('nagad', 'Webhook evidence is malformed or ambiguous.');
        }

        return $this->webhookService()->processWebhookPayload($payload);
    }

    private function body(string $reference, string $amountFields): string
    {
        return '{"order_id":'.json_encode($reference, JSON_THROW_ON_ERROR)
            .',"payment_ref_id":"nagad-test-reference","status":"SUCCESS",'
            .$amountFields.',"currency":"BDT"}';
    }

    private function signedRequest(string $body): Request
    {
        $request = Request::create('/webhook/nagad', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set('X-Nagad-Signature', hash_hmac('sha256', $body, self::NAGAD_WEBHOOK_SECRET));

        return $request;
    }
}
