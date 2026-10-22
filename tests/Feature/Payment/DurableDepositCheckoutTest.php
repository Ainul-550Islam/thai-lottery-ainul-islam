<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositService;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

final class DurableDepositCheckoutTest extends PaymentTestCase
{
    private function fixture(): array
    {
        Http::preventStrayRequests();

        return $this->createPlayer('0.00');
    }

    private function runCheckout(array $f, array $options = [], ?string $key = 'synthetic-durable-checkout-request'): array
    {
        return app(PaymentInitiationService::class)->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, $key, $options);
    }

    private function fakeSuccess(?\Closure $before = null): void
    {
        Http::fake(['api.stripe.com/*' => function ($request) use ($before) {
            if ($before) {
                $before($request);
            }

            return Http::response(['id' => 'SYNTHETIC-CHECKOUT-RESPONSE', 'url' => 'https://example.invalid/checkout', 'private_test_secret' => 'SYNTHETIC-NOT-A-LIVE-SECRET'], 200);
        }]);
    }

    private function refusal(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('An unsafe checkout action was permitted.');
        } catch (FinancialException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    private function rows(): array
    {
        return DB::table(DepositCheckoutService::TABLE)->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
    }

    public function test_claim_is_committed_before_provider_call_with_server_identity_and_no_open_sql_transaction(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess(function ($request): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $a = DB::table(DepositCheckoutService::TABLE)->sole();
            $this->assertSame('dispatching', $a->state);
            $this->assertSame(1, (int) $a->attempt_count);
            $this->assertSame([$a->dispatch_key], $request->header('Idempotency-Key'));
            $this->assertSame($a->uuid, $request['metadata']['checkout_attempt']);
        });
        $first = $this->runCheckout($f);
        $this->assertSame('projected', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $before = $this->rows();
        $again = $this->runCheckout($f);
        $this->assertSame($first['payment']->id, $again['payment']->id);
        $this->assertSame($first['gateway_response']->toArray(), $again['gateway_response']->toArray());
        $this->assertSame($before, $this->rows());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }

    public static function uncertainResponses(): array
    {
        return [['timeout'], ['failure'], ['missing_reference']];
    }

    #[DataProvider('uncertainResponses')]
    public function test_unknown_acceptance_retains_intent_and_never_auto_resends_even_after_time_passes(string $kind): void
    {
        $f = $this->fixture();
        $calls = 0;
        Http::fake(['api.stripe.com/*' => function () use (&$calls, $kind) {
            $calls++;
            if ($kind === 'timeout') {
                throw new \RuntimeException('SYNTHETIC ACK LOST AFTER POSSIBLE ACCEPTANCE');
            }

            return Http::response($kind === 'failure' ? ['error' => ['message' => 'SYNTHETIC uncertain rejection']] : ['url' => 'https://example.invalid/checkout'], $kind === 'failure' ? 500 : 200);
        }]);
        $this->refusal(fn () => $this->runCheckout($f), 'checkout_outcome_unknown');
        $this->assertSame('unknown', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->assertDatabaseCount('deposits', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->travel(10)->days();
        $before = $this->rows();
        $this->refusal(fn () => $this->runCheckout($f), 'checkout_outcome_unknown');
        $this->refusal(fn () => app(DepositCheckoutService::class)->recover(Deposit::query()->sole()), 'checkout_outcome_unknown');
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_late_projection_failure_keeps_encrypted_response_and_sql_only_retry_projects_once(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'payments')) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC LATE PROJECTION FAILURE');
            }
        });
        try {
            $this->runCheckout($f);
            $this->fail('Fault did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC LATE PROJECTION FAILURE', $e->getMessage());
        }
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $this->assertSame('response_received', $a->state);
        $this->assertStringNotContainsString('SYNTHETIC-NOT-A-LIVE-SECRET', $a->response_cipher);
        $this->assertStringContainsString('SYNTHETIC-NOT-A-LIVE-SECRET', Crypt::decryptString($a->response_cipher));
        $this->assertNull(Payment::query()->sole()->gateway_reference);
        $result = app(DepositCheckoutService::class)->recover(Deposit::query()->sole());
        $this->assertSame('SYNTHETIC-CHECKOUT-RESPONSE', $result['payment']->gateway_reference);
        $this->assertSame($a->response_cipher, DB::table(DepositCheckoutService::TABLE)->sole()->response_cipher);
        $this->runCheckout($f);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    private function interruptAfterCommit(string $verb): void
    {
        $armed = true;
        DB::listen(function ($query) use (&$armed, $verb): void {
            if ($armed && str_starts_with(strtolower($query->sql), $verb) && str_contains($query->sql, DepositCheckoutService::TABLE)) {
                $armed = false;
                DB::afterCommit(fn () => throw new \RuntimeException('SYNTHETIC PROCESS STOP AFTER COMMIT'));
            }
        });
    }

    public function test_prepared_intent_can_resume_only_after_current_admission_and_recover_never_dispatches_it(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $this->interruptAfterCommit('insert into');
        try {
            $this->runCheckout($f);
            $this->fail('Committed prepare interruption did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC PROCESS STOP AFTER COMMIT', $e->getMessage());
        }
        $this->assertSame('prepared', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->refusal(fn () => app(DepositCheckoutService::class)->recover(Deposit::query()->sole()), 'checkout_outcome_unknown');
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']);
        $this->refusal(fn () => $this->runCheckout($f), 'deposit_player_unavailable');
        Http::assertNothingSent();
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'active']);
        $this->runCheckout($f);
        Http::assertSentCount(1);
    }

    public function test_committed_claim_with_no_network_acknowledgement_is_never_rearmed_by_retry(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $this->interruptAfterCommit('update');
        try {
            $this->runCheckout($f);
            $this->fail('Committed dispatch interruption did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC PROCESS STOP AFTER COMMIT', $e->getMessage());
        }
        $this->assertSame('dispatching', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->refusal(fn () => $this->runCheckout($f), 'checkout_outcome_unknown');
        Http::assertNothingSent();
    }

    public static function callerTransactions(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('callerTransactions')]
    public function test_framework_and_raw_pdo_caller_transactions_are_refused_before_any_remote_effect(bool $raw): void
    {
        $f = $this->fixture();
        Http::fake();
        if ($raw) {
            DB::connection()->getPdo()->beginTransaction();
        } else {
            DB::beginTransaction();
        }
        try {
            $this->refusal(fn () => $this->runCheckout($f), 'checkout_requires_top_level');
            $this->assertDatabaseCount('deposits', 0);
            Http::assertNothingSent();
        } finally {
            if ($raw) {
                DB::connection()->getPdo()->rollBack();
            } else {
                DB::rollBack();
            }
        }
    }

    public function test_configuration_drift_after_response_custody_cannot_switch_accounts_or_resend(): void
    {
        $f = $this->fixture();
        $secret = config('payment.gateways.stripe.secret');
        $this->fakeSuccess(fn () => config(['payment.gateways.stripe.secret' => 'SYNTHETIC-DIFFERENT-ACCOUNT']));
        $this->refusal(fn () => $this->runCheckout($f), 'checkout_original_conflict');
        $this->assertSame('response_received', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        config(['payment.gateways.stripe.secret' => $secret]);
        $this->runCheckout($f);
        Http::assertSentCount(1);
    }

    public function test_changed_original_options_refuse_but_observational_ip_changes_replay_without_writes(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $this->runCheckout($f, ['metadata' => ['purpose' => 'original'], 'ip' => '192.0.2.1']);
        $before = $this->rows();
        $this->runCheckout($f, ['ip' => '192.0.2.2', 'user_agent' => 'new agent', 'metadata' => ['purpose' => 'original']]);
        $this->assertSame($before, $this->rows());
        $this->refusal(fn () => $this->runCheckout($f, ['metadata' => ['purpose' => 'different']]), 'checkout_request_conflict');
        Http::assertSentCount(1);
    }

    public static function reservedOptions(): array
    {
        return [['checkout_dispatch_key'], ['checkout_attempt'], ['account_scope']];
    }

    #[DataProvider('reservedOptions')]
    public function test_dispatch_identity_and_account_selectors_cannot_come_from_customer_options(string $field): void
    {
        $f = $this->fixture();
        Http::fake();
        $this->refusal(fn () => $this->runCheckout($f, [$field => 'CLIENT-OVERRIDE']), 'checkout_request_conflict');
        $this->assertDatabaseCount('deposits', 0);
        Http::assertNothingSent();
    }

    public function test_independent_attempt_write_during_original_replay_has_no_obsolete_user_snapshot_abort(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Independent committed response-custody/current-read interleave requires the dedicated InnoDB store.');
        }
        $f = $this->fixture();
        $this->fakeSuccess();
        $out = $this->runCheckout($f);
        config(['database.connections.checkout_snapshot_probe' => config('database.connections.'.config('database.default'))]);
        $peer = DB::connection('checkout_snapshot_probe');
        $reads = 0;
        $interleaved = false;
        $stamp = now()->addSecond()->format('Y-m-d H:i:s');
        DB::listen(function ($query) use (&$reads, &$interleaved, $peer, $stamp): void {
            if ($query->connectionName === config('database.default') && str_contains(strtolower($query->sql), 'from `users`')) {
                $reads++;
                if ($reads === 2) {
                    // This independent writer follows response custody's attempt-only
                    // locking shape. The old ordinary User read opened a stale RR
                    // snapshot immediately before this committed update (MariaDB1020).
                    $this->assertSame(0, $peer->transactionLevel());
                    $this->assertFalse($peer->getPdo()->inTransaction());
                    $peer->table(DepositCheckoutService::TABLE)->update(['updated_at' => $stamp]);
                    $interleaved = true;
                }
            }
        });
        $error = null;
        try {
            app(DepositCheckoutService::class)->recover($out['deposit']);
        } catch (\Throwable $e) {
            $error = $e::class;
        } finally {
            DB::purge('checkout_snapshot_probe');
        }
        $this->assertTrue($interleaved, 'The independent committed attempt update must really execute.');
        $this->assertNull($error, 'Original replay must not abort due to an obsolete User snapshot.');
        $this->assertSame($stamp, DB::table(DepositCheckoutService::TABLE)->sole()->updated_at);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_unavailable_local_encryption_cannot_authorize_a_provider_call(): void
    {
        $f = $this->fixture();
        Http::fake();
        Crypt::shouldReceive('getKey')->once()->andThrow(new \RuntimeException('SYNTHETIC unavailable encryption'));
        $this->refusal(fn () => $this->runCheckout($f), 'checkout_custody_unavailable');
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $this->assertSame('prepared', $a->state);
        $this->assertSame(0, (int) $a->attempt_count);
        Http::assertNothingSent();
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_missing_stable_caller_key_cannot_create_a_remote_checkout(): void
    {
        $f = $this->fixture();
        Http::fake();
        $this->refusal(fn () => $this->runCheckout($f, key: null), 'checkout_request_key_required');
        $this->assertDatabaseCount('deposits', 0);
        Http::assertNothingSent();
    }

    public function test_ban_after_claim_does_not_destroy_response_custody_or_authorize_new_credit(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess(fn () => DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']));
        $out = $this->runCheckout($f);
        $this->assertSame('projected', DB::table(DepositCheckoutService::TABLE)->sole()->state);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        // No Payment capture: response projection is deliberately not credit authority.
        $this->assertSame('pending', $out['payment']->status->value);
    }

    public static function corruptFacts(): array
    {
        return [['terms'], ['cipher'], ['owner_marker']];
    }

    #[DataProvider('corruptFacts')]
    public function test_conflicting_original_facts_cannot_recover_or_repeat_the_provider_call(string $kind): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $this->runCheckout($f);
        if ($kind === 'terms') {
            DB::table('deposits')->update(['amount' => '200.00']);
        } elseif ($kind === 'cipher') {
            DB::table(DepositCheckoutService::TABLE)->update(['response_cipher' => 'SYNTHETIC-CORRUPT-CIPHER']);
        } else {
            DB::table('payments')->update(['metadata' => json_encode([DepositCheckoutService::MARKER => 'WRONG-OWNER'])]);
        }
        try {
            app(DepositCheckoutService::class)->recover(Deposit::query()->sole());
            $this->fail('Conflicting custody was accepted.');
        } catch (FinancialException) {
            $this->assertDatabaseCount('financial_transactions', 0);
        }
        Http::assertSentCount(1);
    }

    public function test_deleted_new_ownership_cannot_fall_back_to_historical_snapshot_enrollment(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $this->runCheckout($f);
        DB::table(DepositCheckoutService::TABLE)->delete();
        $this->refusal(fn () => $this->runCheckout($f), 'payment_initiation_replay_unproven');
        Http::assertSentCount(1);
    }

    public function test_callback_credit_before_checkout_projection_preserves_capture_balance_and_callback_evidence(): void
    {
        $f = $this->fixture();
        $evidence = ['synthetic_verified_callback' => true, 'amount' => '100.00', 'currency' => 'THB'];
        $this->fakeSuccess(function () use ($evidence): void {
            $row = Deposit::query()->sole();
            // Trusted normalized domain fixture; signature ingress has separate tests.
            app(PaymentWebhookService::class)->processWebhookPayload(new WebhookPayload(
                'stripe', 'SYNTHETIC-PROJECTION-RACE-EVENT', WebhookEventType::DepositSuccess,
                'SYNTHETIC-CHECKOUT-RESPONSE', $row->reference_number, '100.00', Currency::THB, true, rawData: $evidence));
        });
        $out = $this->runCheckout($f);
        $this->assertSame('captured', $out['payment']->status->value);
        $this->assertSame($evidence, $out['payment']->gateway_response);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->runCheckout($f);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        Http::assertSentCount(1);
    }

    public static function terminalReleases(): array
    {
        return [['cancel'], ['reject'], ['fail']];
    }

    #[DataProvider('terminalReleases')]
    public function test_uncertain_checkout_reservation_cannot_be_released_by_local_cancel_review_or_timeout(string $operation): void
    {
        $f = $this->fixture();
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $this->refusal(fn () => $this->runCheckout($f), 'checkout_outcome_unknown');
        $row = Deposit::query()->sole();
        $before = $this->rows();
        $this->refusal(function () use ($operation, $row): void {
            if ($operation === 'cancel') {
                app(DepositService::class)->cancel($row);
            } elseif ($operation === 'reject') {
                app(DepositApprovalService::class)->reject($row, 'SYNTHETIC local review is not remote rejection proof');
            } else {
                DB::transaction(function () use ($row): void {
                    app(WalletLockService::class)->lock($row->wallet_id);
                    app(FinancialStateTransitionService::class)->transitionDeposit($row, DepositStatus::Failed);
                });
            }
        }, 'checkout_outcome_unknown');
        $this->assertSame($before, $this->rows());
        $this->assertSame('pending', $row->fresh()->status->value);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_standard_header_key_works_and_conflicting_header_or_body_keys_fail_validation(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['user']);
        $this->fakeSuccess();
        $body = ['amount' => '100.00', 'currency' => 'THB', 'method' => 'stripe'];
        $this->withHeader('Idempotency-Key', 'synthetic-api-header-key-001')->postJson('/api/v1/deposits', $body)->assertCreated();
        $this->postJson('/api/v1/deposits', $body + ['idempotency_key' => 'synthetic-api-body-conflict'])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_unknown_api_response_returns_owned_hold_reference_without_resend_authority(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['user']);
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $body = ['amount' => '100.00', 'currency' => 'THB', 'method' => 'stripe', 'idempotency_key' => 'synthetic-api-unknown-key'];
        $reply = $this->postJson('/api/v1/deposits', $body)->assertConflict();
        $reply->assertJsonPath('error.code', 'checkout_outcome_unknown')->assertJsonPath('error.details.checkout.automatic_resend_allowed', false);
        $this->assertSame(Deposit::query()->sole()->uuid, $reply->json('error.details.deposit_uuid'));
        $this->postJson('/api/v1/deposits', $body)->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_owner_api_status_exposes_no_cipher_fence_key_or_provider_secret_and_requires_a_request_key(): void
    {
        $f = $this->fixture();
        $this->fakeSuccess();
        $out = $this->runCheckout($f);
        Sanctum::actingAs($f['user']);
        $response = $this->getJson('/api/v1/deposits/'.$out['deposit']->uuid)->assertOk();
        $response->assertJsonPath('data.checkout.state', 'projected')->assertJsonPath('data.checkout.automatic_resend_allowed', false);
        foreach (['response_cipher', 'dispatch_key', 'fence', 'configuration_hash', 'SYNTHETIC-NOT-A-LIVE-SECRET'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->postJson('/api/v1/deposits', ['amount' => '100.00', 'currency' => 'THB', 'method' => 'stripe'])->assertUnprocessable();
        Http::assertSentCount(1);
    }
}
