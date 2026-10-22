<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\AgentStatus;
use App\Enums\BetType;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\FinancialTransactionType;
use App\Enums\KycDocumentType;
use App\Enums\LimitStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserStatus;
use App\Enums\WebhookEventType;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloTicket;
use App\Models\KycDocument;
use App\Models\NumberLimit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BulkBetService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Security\KycVerificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Disposable, server-authored fixtures for the legacy readiness suites.
 *
 * Tests use the actual typed services and independent review. They do not make
 * sensitive model attributes client-fillable, invent production aliases, relax
 * a money guard, configure a live provider or enable automation. Truncation lets
 * the services own their real transaction boundaries instead of hiding them in
 * an outer RefreshDatabase transaction.
 */
abstract class CanonicalReadinessTestCase extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function readinessPlayer(): User
    {
        return User::factory()->create(['status' => UserStatus::Active]);
    }

    protected function readinessFundedWallet(User $user, string $amount, Currency $currency = Currency::THB): Wallet
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreateWallet($user, $currency);
        $wallets->credit(
            $wallet,
            Money::of($amount, $currency),
            FinancialTransactionType::Deposit,
            'readiness-funding-'.Str::uuid(),
            ['description' => 'Disposable readiness-test funding'],
        );

        return $wallet->refresh();
    }

    /**
     * Provision the same explicit open window and operator-owned number limits
     * required by the purchase pipeline. Missing limits are never unlimited.
     *
     * @param  list<BulkBetSelectionData>  $selections
     */
    protected function readinessOpenDraw(array $selections = []): Draw
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->addHours(2),
        ]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        foreach ($selections as $selection) {
            $definition = config('lottery.markets.'.$selection->marketKey);

            if (! is_array($definition)) {
                throw new LogicException('A readiness fixture must name a canonical configured market.');
            }

            $limit = new NumberLimit;
            $limit->draw_id = $draw->id;
            $limit->bet_type = BetType::from((string) $definition['bet_type']);
            $limit->number = str_pad($selection->number, (int) $definition['digits'], '0', STR_PAD_LEFT);
            $limit->max_amount = '100000.00';
            $limit->current_amount = '0.00';
            $limit->maximum_payout_exposure = null;
            $limit->current_payout_exposure = '0.00';
            $limit->status = LimitStatus::Active;
            $limit->save();
        }

        return $draw;
    }

    protected function readinessPendingDeposit(Wallet $wallet, string $amount, PaymentMethod $method = PaymentMethod::Stripe): Deposit
    {
        return app(DepositService::class)->request(
            $wallet,
            Money::of($amount, $wallet->currency),
            $method,
            'readiness-deposit-'.Str::uuid(),
        );
    }

    protected function readinessDepositWebhook(Deposit $deposit, string $gateway, ?string $eventId = null): WebhookPayload
    {
        return new WebhookPayload(
            gateway: $gateway,
            eventId: $eventId ?? 'readiness-event-'.Str::uuid(),
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'readiness-provider-'.Str::uuid(),
            internalReference: $deposit->reference_number,
            amount: (string) $deposit->amount,
            currency: $deposit->currency,
            isSuccess: true,
            rawData: ['currency' => $deposit->currency->value, 'amount' => (string) $deposit->amount],
        );
    }

    /**
     * Real private upload + separate reviewer, never client-assigned KYC state.
     */
    protected function readinessVerify(User $user, ?User $reviewer = null): KycDocument
    {
        $reviewer ??= $this->readinessPlayer();
        $user->date_of_birth = now()->subYears(35)->toDateString();
        $user->save();
        $service = app(KycVerificationService::class);
        $document = $service->submitDocument(
            $user,
            KycDocumentType::NationalId,
            UploadedFile::fake()->image('identity.png', 30, 30),
            'READINESS-TEST-'.Str::upper(Str::random(10)),
            '127.0.0.1',
        );
        $service->reviewDocument($document, $reviewer, true);
        $user->refresh();

        return $document->refresh();
    }

    /**
     * Published-result fixture matching the already-supported claim service.
     * Claim money and status still have to be derived by submit/review/approve.
     *
     * @return array{user: User, reviewer: User, operator: User, draw: Draw, ticket: GloTicket}
     */
    protected function readinessWinner(string $number = '123456'): array
    {
        $this->seed(RolePermissionSeeder::class);
        $reviewer = $this->readinessPlayer();
        $reviewer->syncRoles(['admin']);
        $operator = $this->readinessPlayer();
        $operator->syncRoles(['auditor']);
        $operator->givePermissionTo('execute glo prize payments');
        $user = $this->readinessPlayer();
        $user->syncRoles(['player']);
        $this->readinessVerify($user, $reviewer);
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subDays(2),
            'result_published_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        DrawResult::create([
            'draw_id' => $draw->id,
            'first_prize' => $number,
            'second_prize' => ['121212'],
            'third_prize' => ['131313'],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 1,
            'total_payout' => '6000000.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDay(),
            'metadata' => [
                'fixture_label' => 'DISPOSABLE_READINESS_TEST_ONLY',
                'glo' => [],
            ],
        ]);
        $ticket = GloTicket::create([
            'draw_id' => $draw->id,
            'product' => 'l6',
            'ticket_number' => $number,
            'set_series' => null,
            'owner_user_id' => $user->id,
            'ticket_reference' => GloTicket::buildReference((int) $draw->id, 'l6', $number),
            'metadata' => ['fixture_label' => 'DISPOSABLE_READINESS_TEST_ONLY'],
        ]);

        return compact('user', 'reviewer', 'operator', 'draw', 'ticket');
    }

    /**
     * Only a single, already-accrued agent row in the disposable database.
     * No referral chain, commission accrual hook or scheduler is enabled.
     *
     * @return array{agent: Agent, wallet: Wallet, draw: Draw, commission: AgentCommission}
     */
    protected function readinessAccruedCommission(string $amount = '50.00'): array
    {
        $user = $this->readinessPlayer();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $agent = Agent::create([
            'user_id' => $user->id,
            'agent_code' => 'READINESS-'.Str::upper(Str::random(10)),
            'status' => AgentStatus::Active,
            'currency' => Currency::THB,
            'commission_rate' => '0.0500',
        ]);
        $stake = bcmul($amount, '20', 2);
        $player = $this->readinessPlayer();
        $this->readinessFundedWallet($player, $stake);
        // A configured low-multiplier market stays within the existing risk
        // ceiling even for the larger reconciliation fixture. No cap is raised.
        $selections = [new BulkBetSelectionData('run_top', '1', $stake)];
        $draw = $this->readinessOpenDraw($selections);
        $purchase = app(BulkBetService::class)->purchase(
            (int) $player->id,
            (int) $draw->id,
            $selections,
            'READINESS-COMMISSION-BET-'.Str::uuid(),
        );

        if ($purchase['purchased'] !== 1) {
            throw new LogicException('Commission fixture purchase was refused: '.json_encode($purchase, JSON_THROW_ON_ERROR));
        }

        $bet = Bet::query()->findOrFail($purchase['items'][0]['bet_id']);
        $commission = AgentCommission::create([
            'agent_id' => $agent->id,
            'draw_id' => $draw->id,
            'bet_id' => $bet->id,
            'stake_amount' => bcmul($amount, '20', 2),
            'commission_amount' => $amount,
            'currency' => Currency::THB,
            'status' => CommissionStatus::Accrued,
            'reference_number' => 'READINESS-COMM-'.Str::uuid(),
        ]);

        return compact('agent', 'wallet', 'draw', 'commission');
    }
}
