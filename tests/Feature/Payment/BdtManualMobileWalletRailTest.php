<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Operations\ProviderReadinessService;
use App\Services\Payment\ManualWalletDepositSettlementService;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\PaymentGatewayManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Bangladesh launch scenarios on the operator-verified bKash/Nagad rail:
 *  - a 1000 taka deposit through Nagad (pay, submit TrxID, admin verifies, credit)
 *  - a 2000 taka withdrawal through bKash (hold, stays pending until approved)
 * plus the safety properties that keep the legacy API rails closed.
 */
final class BdtManualMobileWalletRailTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureBangladeshManualRail();
    }

    private function configureBangladeshManualRail(): void
    {
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.deposit.enabled' => true,
            'payment.withdrawal.enabled' => true,
            'payment.deposit.allowed_methods' => ['bkash', 'nagad'],
            'payment.withdrawal.allowed_methods' => ['bkash', 'nagad'],
            'finance.withdrawal.min' => '1000.00',
            'payment.withdrawal.min' => '1000.00',
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.bkash.mode' => 'manual',
            'payment.gateways.bkash.merchant_number' => '01822222222',
            'payment.gateways.nagad.enabled' => true,
            'payment.gateways.nagad.mode' => 'manual',
            'payment.gateways.nagad.merchant_number' => '01711111111',
        ]);
    }

    private function startNagadDeposit(array $player, string $key): string
    {
        $response = $this->postJson('/api/v1/deposits', [
            'amount' => '1000.00',
            'method' => 'nagad',
            'currency' => 'BDT',
            'idempotency_key' => $key,
        ]);
        $response->assertStatus(201);

        return (string) $response->json('data.deposit.reference_number');
    }

    public function test_nagad_deposit_of_1000_taka_returns_pay_to_instructions_and_credits_nothing(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposits', [
            'amount' => '1000.00',
            'method' => 'nagad',
            'currency' => 'BDT',
            'idempotency_key' => 'bd-nagad-deposit-1000',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.deposit.amount', '1000.00');
        $response->assertJsonPath('data.deposit.currency', 'BDT');
        $response->assertJsonPath('data.deposit.method', 'nagad');
        $response->assertJsonPath('data.deposit.status', 'pending');
        $response->assertJsonPath('data.checkout.instructions.account_number', '01711111111');
        self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_trxid_is_recorded_and_only_the_administrator_step_credits_the_wallet(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $reference = $this->startNagadDeposit($player, 'bd-nagad-deposit-proof');

        $this->postJson('/api/v1/deposits/'.$reference.'/proof', [
            'sender_number' => '+8801712345678',
            'trx_id' => '9h8x7g6f5d',
        ])->assertOk()
            ->assertJsonPath('data.proof.sender_number', '01712345678')
            ->assertJsonPath('data.proof.trx_id', '9H8X7G6F5D');

        // The claim alone never moves money.
        self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
        $deposit = Deposit::query()->where('reference_number', $reference)->firstOrFail();
        self::assertSame(DepositStatus::Pending, $deposit->status);
        self::assertSame('9H8X7G6F5D', data_get($deposit->metadata, 'manual_proof.trx_id'));

        // Staff verify the TrxID in the merchant app and type it; only then is the wallet credited.
        $outcome = app(ManualWalletDepositSettlementService::class)->settle($deposit, $this->staff(), '9h8x7g6f5d');

        self::assertTrue($outcome['credited']);
        self::assertSame('1100.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);

        // A replay credits nothing twice.
        $again = app(ManualWalletDepositSettlementService::class)->settle($deposit->fresh(), $this->staff(), '9H8X7G6F5D');
        self::assertTrue($again['replayed']);
        self::assertSame('1100.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_settlement_refuses_a_wrong_trxid_a_missing_proof_and_a_non_staff_actor(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $reference = $this->startNagadDeposit($player, 'bd-nagad-deposit-refuse');
        $deposit = Deposit::query()->where('reference_number', $reference)->firstOrFail();
        $service = app(ManualWalletDepositSettlementService::class);

        try {
            $service->settle($deposit, $this->staff(), 'ANYTRXID01');
            self::fail('Settlement without a submitted proof must be refused.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_settlement_no_proof', $exception->errorCode());
        }

        $this->postJson('/api/v1/deposits/'.$reference.'/proof', ['sender_number' => '01712345678', 'trx_id' => 'REALTRX001'])->assertOk();

        try {
            $service->settle($deposit->fresh(), $this->staff(), 'WRONGTRX01');
            self::fail('A TrxID that differs from the submitted one must be refused.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_settlement_trx_mismatch', $exception->errorCode());
        }

        $this->expectException(AuthorizationException::class);
        try {
            $service->settle($deposit->fresh(), $player['user'], 'REALTRX001');
        } finally {
            self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
            self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        }
    }

    public function test_player_page_shows_where_to_pay_and_accepts_the_trxid_form(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $reference = $this->startNagadDeposit($player, 'bd-nagad-web-form-1');
        $status = route('player.deposit.status', ['deposit' => $reference]);

        $this->actingAs($player['user'], 'web')->get($status)
            ->assertOk()
            ->assertSee('01711111111')
            ->assertSee('name="trx_id"', false);

        $this->actingAs($player['user'], 'web')
            ->post(route('player.deposit.proof', ['deposit' => $reference]), ['sender_number' => '01712345678', 'trx_id' => 'WEBTRX0001'])
            ->assertRedirect($status);

        $this->actingAs($player['user'], 'web')->get($status)
            ->assertOk()
            ->assertSee('WEBTRX0001')
            ->assertDontSee('name="trx_id"', false);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_web_form_refuses_a_bad_trxid_without_recording_anything(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $reference = $this->startNagadDeposit($player, 'bd-nagad-web-form-2');

        $this->actingAs($player['user'], 'web')
            ->post(route('player.deposit.proof', ['deposit' => $reference]), ['sender_number' => '01712345678', 'trx_id' => 'x'])
            ->assertRedirect();

        self::assertNull(data_get(Deposit::query()->where('reference_number', $reference)->firstOrFail()->metadata, 'manual_proof'));
    }

    private function staff(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create(['status' => UserStatus::Active]);
        $staff->assignRole('admin');

        return $staff;
    }

    public function test_the_same_trxid_cannot_back_two_deposits(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $first = $this->startNagadDeposit($player, 'bd-nagad-deposit-dup-1');
        $second = $this->startNagadDeposit($player, 'bd-nagad-deposit-dup-2');

        $this->postJson('/api/v1/deposits/'.$first.'/proof', ['sender_number' => '01712345678', 'trx_id' => 'DUPLICATE01'])->assertOk();
        $this->postJson('/api/v1/deposits/'.$second.'/proof', ['sender_number' => '01712345678', 'trx_id' => 'duplicate01'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'manual_proof_trx_used');
    }

    public function test_invalid_sender_number_and_trxid_are_refused(): void
    {
        $player = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $reference = $this->startNagadDeposit($player, 'bd-nagad-deposit-invalid');

        $this->postJson('/api/v1/deposits/'.$reference.'/proof', ['sender_number' => '12345', 'trx_id' => '9H8X7G6F5D'])
            ->assertStatus(422)->assertJsonPath('error.code', 'manual_proof_sender_invalid');
        $this->postJson('/api/v1/deposits/'.$reference.'/proof', ['sender_number' => '01712345678', 'trx_id' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'manual_proof_trx_invalid');
        self::assertNull(data_get(Deposit::query()->where('reference_number', $reference)->firstOrFail()->metadata, 'manual_proof'));
    }

    public function test_a_player_cannot_attach_a_proof_to_another_players_deposit(): void
    {
        $owner = $this->createAdmittedPlayer('100.00', Currency::BDT);
        $other = $this->createAdmittedPlayer('100.00', Currency::BDT);
        Sanctum::actingAs($owner['user']);
        $reference = $this->startNagadDeposit($owner, 'bd-nagad-deposit-owner');

        Sanctum::actingAs($other['user']);
        $this->postJson('/api/v1/deposits/'.$reference.'/proof', ['sender_number' => '01712345678', 'trx_id' => 'STOLEN0001'])
            ->assertStatus(404);
    }

    public function test_bkash_withdrawal_of_2000_taka_holds_the_funds_and_stays_pending(): void
    {
        $player = $this->createAdmittedPlayer('5000.00', Currency::BDT);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/withdrawals', [
            'amount' => '2000.00',
            'currency' => 'BDT',
            'method' => 'bkash',
            'payout_details' => ['account_number' => '01712345678'],
            'idempotency_key' => 'bd-bkash-withdrawal-2000',
        ])->assertCreated();

        $withdrawal = Withdrawal::query()->firstOrFail();
        self::assertSame('pending', $withdrawal->status->value);
        self::assertSame('2000.00', (string) $withdrawal->amount);
        self::assertSame('5000.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('2000.00', (string) $player['wallet']->fresh()->locked_balance);
        // Nothing is sent to any provider and no payout record exists before approval.
        self::assertSame(0, Payment::query()->where('payable_type', Withdrawal::class)->count());
        Http::assertNothingSent();
    }

    public function test_withdrawal_below_the_1000_taka_minimum_is_refused_before_any_hold(): void
    {
        $player = $this->createAdmittedPlayer('5000.00', Currency::BDT);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/withdrawals', [
            'amount' => '500.00',
            'currency' => 'BDT',
            'method' => 'bkash',
            'payout_details' => ['account_number' => '01712345678'],
            'idempotency_key' => 'bd-bkash-withdrawal-500',
        ])->assertStatus(422);

        self::assertSame(0, Withdrawal::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
    }

    public function test_a_mobile_wallet_payout_needs_a_valid_bangladeshi_mobile_number(): void
    {
        $player = $this->createAdmittedPlayer('5000.00', Currency::BDT);
        Sanctum::actingAs($player['user']);

        foreach ([['payout_details' => ['account_number' => 'not-a-phone']], []] as $extra) {
            $this->postJson('/api/v1/withdrawals', array_merge([
                'amount' => '2000.00',
                'currency' => 'BDT',
                'method' => 'bkash',
                'idempotency_key' => 'bd-bkash-bad-number-'.count($extra),
            ], $extra))->assertStatus(422);
        }

        self::assertSame(0, Withdrawal::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
    }

    public function test_manual_mode_opens_only_the_bangladesh_bdt_market(): void
    {
        $gateways = app(PaymentGatewayManager::class);
        foreach (['bkash', 'nagad'] as $gateway) {
            self::assertTrue($gateways->isDepositCapable($gateway), $gateway.' deposit');
            self::assertTrue($gateways->isWithdrawalCapable($gateway), $gateway.' withdrawal');
            self::assertSame([], app(ProviderReadinessService::class)->gateway($gateway)->issues);
        }

        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH']);
        foreach (['bkash', 'nagad'] as $gateway) {
            self::assertFalse($gateways->isDepositCapable($gateway), $gateway.' must stay closed outside BDT/BD');
            self::assertFalse($gateways->isWithdrawalCapable($gateway), $gateway.' must stay closed outside BDT/BD');
        }
    }

    public function test_the_default_api_mode_stays_closed_and_manual_mode_never_trusts_a_callback(): void
    {
        config([
            'payment.gateways.nagad.mode' => 'api',
            'payment.gateways.bkash.mode' => 'api',
        ]);
        $gateways = app(PaymentGatewayManager::class);
        self::assertFalse($gateways->isDepositCapable('nagad'));
        self::assertContains('nagad_callback_authentication_unverified', app(ProviderReadinessService::class)->gateway('nagad')->issues);

        config(['payment.gateways.nagad.mode' => 'manual', 'payment.gateways.nagad.webhook_secret' => 'secret']);
        $body = '{"status":"Success"}';
        $request = Request::create('/api/v1/payments/webhook/nagad', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Signature', hash_hmac('sha256', $body, 'secret'));
        self::assertFalse(app(NagadGateway::class)->verifyWebhookSignature($request));
    }

    public function test_site_currency_follows_the_deployment_setting_and_falls_back_safely(): void
    {
        self::assertSame(Currency::BDT, Currency::site());
        config(['payment.currency.default' => 'XXX']);
        self::assertSame(Currency::THB, Currency::site());
    }

    public function test_provisioning_command_adds_a_site_currency_wallet_without_touching_existing_ones(): void
    {
        $player = $this->createPlayer('10.00', Currency::THB);

        $this->artisan('wallets:provision-site-currency')->assertExitCode(0);

        self::assertTrue(Wallet::query()->where('user_id', $player['user']->id)->where('currency', Currency::BDT->value)->exists());
        self::assertSame('10.00', (string) $player['wallet']->fresh()->balance);

        // Running it again changes nothing.
        $this->artisan('wallets:provision-site-currency')->assertExitCode(0);
        self::assertSame(1, Wallet::query()->where('user_id', $player['user']->id)->where('currency', Currency::BDT->value)->count());
    }
}
