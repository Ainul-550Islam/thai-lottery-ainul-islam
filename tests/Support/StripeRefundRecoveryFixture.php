<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Payment\StripeRefundRecoveryAccess;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait StripeRefundRecoveryFixture
{
    use StripeDepositReviewFixture;

    protected function refundFixture(): array
    {
        $f = $this->reviewFixture();
        $this->onboardWagerPlayer($f['user']);
        config(['payment.refund_recovery.stripe.enabled' => true]);
        $f['actor']->givePermissionTo(StripeRefundRecoveryAccess::RECOVER);
        $event = $this->followupEvent();
        $event['data']['object']['amount'] = 10000;
        $event['data']['object']['status'] = 'succeeded';
        $event['data']['object']['created'] = now()->timestamp;
        $f['refund'] = $event['data']['object'];
        $f['inbox'] = $this->receiveFollowup($event)['inbox_id'];
        $f['proof'] = ['refund' => $f['refund'],
            'charge' => ['object' => 'charge', 'id' => 'ch_SYNTHETICREVIEW123', 'payment_intent' => 'pi_SYNTHETICREVIEW123',
                'status' => 'succeeded', 'paid' => true, 'captured' => true, 'refunded' => true, 'disputed' => false,
                'currency' => 'thb', 'livemode' => false, 'amount' => 10000, 'amount_captured' => 10000, 'amount_refunded' => 10000],
            'intent' => ['object' => 'payment_intent', 'id' => 'pi_SYNTHETICREVIEW123', 'latest_charge' => 'ch_SYNTHETICREVIEW123',
                'status' => 'succeeded', 'amount_capturable' => 0, 'currency' => 'thb', 'livemode' => false, 'amount' => 10000, 'amount_received' => 10000],
            'list' => ['object' => 'list', 'data' => [$f['refund']], 'has_more' => false]];
        $this->fakeRefundProof($f['proof']);

        return $f;
    }

    /**
     * Make the fixture player admissible for the money endpoints.
     *
     * The refund-wager contract is asserted through `/api/v1/bets/purchase`, which sits
     * behind `adult` and `legal.current`. A bare factory user would be refused for its age
     * attestation long before the refund guard is consulted, so the fixture onboards the
     * player exactly as a real signup would.
     */
    protected function onboardWagerPlayer(\App\Models\User $user): void
    {
        if ($user->date_of_birth === null) {
            $user->forceFill(['date_of_birth' => now()->subYears(30)->toDateString()])->save();
        }

        \App\Models\AgeVerification::query()->create([
            'user_id' => $user->getKey(),
            'status' => 'eligible',
            'minimum_age' => (int) config('legal.minimum_age', 18),
            'method' => 'self_declaration',
            'attested_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'source' => 'test',
        ]);

        $documents = collect(app(\App\Services\Legal\ConsentService::class)->currentDocuments())->keyBy('document_type');
        $terms = $documents->get('terms', []);
        $privacy = $documents->get('privacy', []);

        app(\App\Services\Legal\ConsentService::class)->acceptCurrentDocuments(
            $user,
            \Illuminate\Http\Request::create('/test', 'POST', [
                'terms' => true,
                'privacy_notice' => true,
                'terms_version' => $terms['version'] ?? '',
                'terms_hash' => $terms['content_hash'] ?? '',
                'privacy_version' => $privacy['version'] ?? '',
                'privacy_hash' => $privacy['content_hash'] ?? '',
            ], server: ['REMOTE_ADDR' => '127.0.0.1']),
            'api',
        );

        $user->refresh();
    }

    protected function fakeRefundProof(array $proof, ?\Closure $hook = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => function ($r) use ($proof, $hook) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame('GET', $r->method());
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer '.self::STRIPE_SECRET));
            if (parse_url($r->url(), PHP_URL_PATH) === '/v1/refunds') {
                $this->assertSame('ch_SYNTHETICREVIEW123', $r['charge']);
                $this->assertSame(2, $r['limit']);
            }
            $this->assertStringStartsWith('https://api.stripe.com/v1/', $r->url());
            if ($hook) {
                $hook($r);
            }
            $path = parse_url($r->url(), PHP_URL_PATH);
            $key = str_contains($path, '/refunds/') ? 'refund' : (str_contains($path, '/charges/') ? 'charge' : (str_contains($path, '/payment_intents/') ? 'intent' : 'list'));

            return Http::response($proof[$key]);
        }]);
    }

    protected function recoverRefund(array $f): array
    {
        return app(StripeRefundRecoveryService::class)->recover($f['deposit'], $f['actor'], $f['inbox']);
    }
}
