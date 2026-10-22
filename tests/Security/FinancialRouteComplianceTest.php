<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FinancialRouteComplianceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function playerFinancialMutationRoutes(): array
    {
        return [
            'player.bets.purchase' => 'auth',
            'player.bets.purchase.alias' => 'auth',
            'player.deposit.store' => 'auth',
            'player.withdraw.store' => 'auth',
            'api.v1.lotto.bets.place' => 'auth:sanctum',
            'api.v1.player.dashboard.wagers.cancel' => 'auth:sanctum',
            'api.v1.wallet.deposit' => 'auth:sanctum',
            'api.v1.wallet.withdraw' => 'auth:sanctum',
            'api.v1.deposit.promptpay.generate' => 'auth:sanctum',
            'api.v1.deposit.bank-transfer.intent' => 'auth:sanctum',
            'api.v1.deposit.crypto.address' => 'auth:sanctum',
            'api.v1.deposit.verify-slip' => 'auth:sanctum',
            'api.v1.withdrawal.request' => 'auth:sanctum',
            'api.v1.player.profile.transfer' => 'auth:sanctum',
            'api.v1.player.profile.bank' => 'auth:sanctum',
            'api.v1.player.history.rebet' => 'auth:sanctum',
            'api.v1.player.history.cancel' => 'auth:sanctum',
            'api.v1.bets.purchase' => 'auth:sanctum',
            'api.v1.bets.purchase-bulk' => 'auth:sanctum',
            'api.v1.bets.permutations.purchase' => 'auth:sanctum',
            'api.v1.bets.cancel' => 'auth:sanctum',
            'api.v1.bets.amend' => 'auth:sanctum',
            'api.v1.deposits.store' => 'auth:sanctum',
            'api.v1.payments.intents.create' => 'auth:sanctum',
            'api.v1.payments.intents.cancel' => 'auth:sanctum',
            'api.v1.withdrawals.store' => 'auth:sanctum',
            'api.v1.withdrawals.cancel' => 'auth:sanctum',
            'api.v1.payouts.cancel' => 'auth:sanctum',
            'api.v1.prize-claims.store' => 'auth:sanctum',
            'api.v1.glo.claims.store' => 'auth:sanctum',
            'api.v1.glo.claims.cancel' => 'auth:sanctum',
        ];
    }

    public function test_every_player_financial_mutation_is_authenticated_active_rate_limited_and_compliance_gated(): void
    {
        foreach ($this->playerFinancialMutationRoutes() as $name => $authentication) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Missing financial route: '.$name);

            $middleware = $route->middleware();
            $this->assertContains($authentication, $middleware, $name.' must authenticate the player.');
            $this->assertContains('active', $middleware, $name.' must reject suspended or inactive users.');
            $this->assertContains('adult', $middleware, $name.' must enforce the minimum-age attestation.');
            $this->assertContains('legal.current', $middleware, $name.' must require current Terms and Privacy evidence.');
            $this->assertTrue(
                collect($middleware)->contains(static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'throttle:')),
                $name.' must be rate limited.',
            );
        }
    }

    public function test_sensitive_kyc_document_writes_require_authentication_current_privacy_and_a_dedicated_limit(): void
    {
        foreach ([
            'account.verification.submit' => 'auth',
            'api.v1.kyc.upload' => 'auth:sanctum',
        ] as $name => $authentication) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Missing KYC document-write route: '.$name);

            $middleware = $route->middleware();
            $this->assertContains($authentication, $middleware, $name.' must authenticate the document owner.');
            $this->assertContains('legal.current', $middleware, $name.' must require current Privacy acknowledgement.');
            $this->assertContains('throttle:account-verification', $middleware, $name.' must use the dedicated KYC upload limit.');
        }
    }

    public function test_legal_and_age_evidence_writes_are_authenticated_and_rate_limited(): void
    {
        foreach ([
            'legal.consent.store' => 'auth',
            'account.age-verification.store' => 'auth',
            'api.v1.legal.consents.store' => 'auth:sanctum',
            'api.v1.legal.age-attestation.store' => 'auth:sanctum',
        ] as $name => $authentication) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Missing compliance-evidence route: '.$name);

            $middleware = $route->middleware();
            $this->assertContains($authentication, $middleware, $name.' must authenticate the evidence owner.');
            $this->assertContains('throttle:account-verification', $middleware, $name.' must rate-limit immutable evidence writes.');
        }
    }

    public function test_suspended_accounts_cannot_start_web_or_api_deposits(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->post(route('player.deposit.store'), [])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.deposits.store'), [])
            ->assertForbidden();

        $this->assertSame(0, Deposit::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }
}
