<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PayoutInstruction;
use App\DTOs\Payment\PayoutOutcome;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\ThaiBankPayoutState;
use App\Http\Requests\Payment\DepositRequest;
use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payout\ThaiBank\ThaiBankPayoutAdapterInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The Thai bank payout rail is ROUTED, never chosen.
 *
 * WHY THIS SUITE EXISTS
 * App\Enums\PaymentMethod deliberately has no `thai_bank` case, so no request
 * body, no query string and no header can name the automated payout rail.
 * What decides a payout's rail is server-side configuration plus the stored
 * destination, and the decision is persisted on the withdrawal BEFORE the
 * external call so the row that was paid is the row that is reconciled.
 *
 * Every test below is about that boundary. A player still asks for
 * `bank_transfer`; the system may decide an automated bank transfer pays it,
 * or may keep it with the operator lane. A refusal to route is never an error
 * and never a silent upgrade.
 */
final class ThaiBankRoutingTest extends PaymentTestCase
{
    private const SCB_ACCOUNT = '1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // A configured bank adapter, bound as an instance so the provider's own
        // binding (which throws when the lane is enabled but unconfigured) is
        // never consulted. This adapter makes no network call: the routing
        // decision only asks whether it is configured.
        $this->app->instance(ThaiBankPayoutAdapterInterface::class, new class implements ThaiBankPayoutAdapterInterface
        {
            public function bank(): string
            {
                return 'scb';
            }

            public function configured(): bool
            {
                return true;
            }

            public function initiateTransfer(PayoutInstruction $instruction): PayoutOutcome
            {
                return PayoutOutcome::accepted('test-provider-reference');
            }

            public function queryStatus(string $reference): PayoutOutcome
            {
                return PayoutOutcome::settled($reference);
            }

            public function verifyCallback(string $rawBody, array $headers): bool
            {
                return true;
            }

            public function parseCallback(string $rawBody): array
            {
                return ['reference' => 'test', 'state' => ThaiBankPayoutState::Settled->value, 'providerReference' => null];
            }
        });
    }

    public function test_the_thai_bank_key_resolves_to_the_payout_driver(): void
    {
        $manager = $this->gatewayManager();

        $this->assertTrue($manager->hasDriver('thai_bank'));

        $driver = $manager->driver('thai_bank');
        $this->assertInstanceOf(ThaiBankPayoutGateway::class, $driver);
        $this->assertTrue($driver->supportsWithdrawal());
        $this->assertFalse($driver->supportsDeposit(), 'The payout rail must never accept a deposit.');
        $this->assertSame('thai_bank', $driver->name());
    }

    public function test_the_internal_key_cannot_be_named_by_a_client(): void
    {
        $methods = array_column(PaymentMethod::cases(), 'value');

        $this->assertNotContains('thai_bank', $methods, 'A PaymentMethod case for the payout rail would let a client select it.');

        $rules = (new DepositRequest())->rules();

        $refused = Validator::make([
            'amount' => '100.00',
            'method' => 'thai_bank',
            'idempotency_key' => 'thai-bank-route-refusal',
        ], $rules);

        $this->assertTrue($refused->fails());
        $this->assertArrayHasKey('method', $refused->errors()->toArray());

        // The positive control: the method the player actually chooses is
        // accepted by the same rule set.
        $allowed = Validator::make([
            'amount' => '100.00',
            'method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'thai-bank-route-allowed',
        ], $rules);

        $this->assertFalse($allowed->errors()->has('method'));
    }

    public function test_a_valid_thai_destination_routes_to_the_automated_lane(): void
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => true,
        ]);

        $withdrawal = $this->withdrawal();

        $this->assertSame('thai_bank', $this->disbursement()->resolveGatewayKey($withdrawal));
    }

    public function test_a_disabled_lane_keeps_the_withdrawal_on_the_operator_rail(): void
    {
        config([
            'thai_payout.enabled' => false,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => true,
        ]);

        $withdrawal = $this->withdrawal();

        $this->assertSame(PaymentMethod::BankTransfer->value, $this->disbursement()->resolveGatewayKey($withdrawal));
    }

    public function test_a_routing_preference_of_manual_keeps_the_operator_rail(): void
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => false,
        ]);

        $withdrawal = $this->withdrawal();

        $this->assertSame(PaymentMethod::BankTransfer->value, $this->disbursement()->resolveGatewayKey($withdrawal));
    }

    public function test_an_amount_outside_the_configured_bounds_keeps_the_operator_rail(): void
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => true,
            'payment.withdrawal_routing.min_amount' => '1000.00',
        ]);

        $withdrawal = $this->withdrawal();

        $this->assertSame(PaymentMethod::BankTransfer->value, $this->disbursement()->resolveGatewayKey($withdrawal));
    }

    public function test_an_unpayable_destination_keeps_the_operator_rail(): void
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => true,
        ]);

        // A bank code that is not a Thai bank, and an account number of the
        // wrong length: the destination validator refuses, so the operator
        // lane keeps it. No transfer was submitted either way.
        $withdrawal = $this->withdrawal([
            'bank_name' => 'Not A Thai Bank',
            'account_number' => '12345',
            'account_name' => 'Player',
        ]);

        $this->assertSame(PaymentMethod::BankTransfer->value, $this->disbursement()->resolveGatewayKey($withdrawal));
    }

    public function test_a_persisted_key_is_never_re_routed(): void
    {
        config([
            'thai_payout.enabled' => false,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => true,
        ]);

        $withdrawal = $this->withdrawal();
        $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
        $metadata['gateway_key'] = 'thai_bank';
        $withdrawal->metadata = $metadata;
        $withdrawal->save();

        // The lane is off and the amount is out of every bound, yet the key
        // already on the row wins: an operator who disables the lane after the
        // fact must not silently re-route an in-flight payout, and a retry must
        // never pay through a second driver.
        $this->assertSame('thai_bank', $this->disbursement()->resolveGatewayKey($withdrawal));
    }

    public function test_the_internal_key_is_not_what_the_method_scoped_lanes_expect(): void
    {
        $withdrawal = $this->withdrawal();

        // `provider` stays method-scoped: writing 'thai_bank' there would make
        // the reference index, the integrity guard and the completion lane
        // refuse a payout that had already been sent.
        $this->assertSame(PaymentMethod::BankTransfer, $withdrawal->method);
        $this->assertNotSame('thai_bank', $withdrawal->provider);
    }

    public function test_a_validation_refusal_is_a_named_exception(): void
    {
        $rules = (new DepositRequest())->rules();

        try {
            Validator::make([
                'amount' => '100.00',
                'method' => 'thai_bank',
                'idempotency_key' => 'thai-bank-route-exception',
            ], $rules)->validate();

            $this->fail('The internal payout key was accepted as a deposit method.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('method', $exception->errors());
        }
    }

    private function disbursement(): WithdrawalDisbursementService
    {
        return app(WithdrawalDisbursementService::class);
    }

    /**
     * A THB bank-transfer withdrawal with a payable SCB destination.
     *
     * The row is built directly rather than through WithdrawalService, because
     * what is under test is the ROUTING DECISION — which reads the stored
     * method, amount, destination and persisted key, and nothing else. Driving
     * the whole request/approval lifecycle here would make these tests depend
     * on the KYC gate, the integrity guard and the wallet ledger for a
     * question that has nothing to do with any of them.
     *
     * @param  array<string, string>  $destination
     */
    private function withdrawal(array $destination = []): Withdrawal
    {
        $player = $this->createPlayer('1000.00', Currency::THB);

        $row = new Withdrawal;
        $row->fill([
            'reference_number' => 'WD-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $player['user']->id,
            'wallet_id' => $player['wallet']->id,
            'method' => PaymentMethod::BankTransfer,
            'provider' => PaymentMethod::BankTransfer->value,
            'currency' => Currency::THB,
            'amount' => '400.00',
            'fee' => '0.00',
            'net_amount' => '400.00',
            'payout_details' => array_merge([
                'bank_name' => 'Siam Commercial Bank',
                'bank_code' => '014',
                'account_number' => self::SCB_ACCOUNT,
                'account_name' => 'Player One',
            ], $destination),
            'metadata' => [],
        ]);
        $row->status = WithdrawalStatus::Pending;
        $row->save();

        return $row;
    }
}
