<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\DTOs\BetPurchaseData;
use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\Currency;
use App\Enums\ResponsibleGamingLimitType;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Models\ResponsibleGamingLimit;
use App\Models\ResponsibleGamingLimitVersion;
use App\Services\Betting\BetPurchaseService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\SelfExclusionService;
use App\Services\Security\ResponsibleGamingService;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;

final class ResponsibleGamingLimitLifecycleTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    private function request(array $f, string $amount, string $key, Currency $currency = Currency::THB,
        ?\DateTimeInterface $to = null, ?\DateTimeInterface $from = null): array
    {
        return app(ResponsibleGamingLimitService::class)->pronounce(new ResponsibleGamingLimitData($f['user']->id,
            ResponsibleGamingLimitType::SingleBet, $amount, $currency->value, $from, $to, $key));
    }

    private function rows(): array
    {
        $out = [];
        foreach (['users', 'responsible_gaming_limits', 'responsible_gaming_limit_versions', 'responsible_gaming_limit_ownerships',
            'audit_logs', 'wallets', 'bets', 'number_limits', 'financial_transactions', 'ledger_entries', 'bet_purchase_receipts'] as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
        }

        return $out;
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_fresh_reduction_overrides_pending_increase_and_old_request_replay_cannot_undo_it(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $svc = app(ResponsibleGamingLimitService::class);
        $first = $this->request($f, '20.00', 'initial-limit', $currency)['version'];
        $pending = $this->request($f, '30.00', 'increase-limit', $currency)['version'];
        $lower = $this->request($f, '5.00', 'new-safer-choice', $currency)['version'];
        $this->assertSame('cancelled', $pending->fresh()->limit_status->value);
        $this->assertSame('replaced', $first->fresh()->limit_status->value);
        $this->assertSame('5.00', $svc->bindingLimitFor($f['user']->id, ResponsibleGamingLimitType::SingleBet, $currency->value)->amount);
        $before = $this->rows();
        $replay = $this->request($f, '30.0', 'increase-limit', $currency);
        $this->assertTrue($replay['replayed']);
        $this->assertSame('cancelled', $replay['version']->limit_status->value);
        $this->assertSame($before, $this->rows());
        $this->travel(25)->hours();
        $this->assertSame(0, $svc->activateDuePending());
        $this->assertSame('active', $lower->fresh()->limit_status->value);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    #[DataProvider('currencies')]
    public function test_setter_can_choose_the_same_lower_amount_again_after_an_increase_cycle(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $svc = app(ResponsibleGamingLimitService::class);
        $first = $svc->setSingleBetLimit($f['user']->id, '5.00', $currency->value);
        $pending = $svc->setSingleBetLimit($f['user']->id, '20.00', $currency->value);
        $this->assertSame($pending->id, $svc->setSingleBetLimit($f['user']->id, '20.00', $currency->value)->id);
        $this->travel(25)->hours();
        $this->assertSame(1, $svc->activateDuePending());
        $again = $svc->setSingleBetLimit($f['user']->id, '5.00', $currency->value);
        $this->assertNotSame($first->id, $again->id);
        $this->assertSame('active', $again->limit_status->value);
        $this->assertSame('replaced', $pending->fresh()->limit_status->value);
        $count = DB::table('responsible_gaming_limit_versions')->count();
        $this->assertSame($again->id, $svc->setSingleBetLimit($f['user']->id, '5.00', $currency->value)->id);
        $this->assertSame($count, DB::table('responsible_gaming_limit_versions')->count());
    }

    public static function conflicts(): array
    {
        return [['amount'], ['currency'], ['type'], ['end'], ['start']];
    }

    #[DataProvider('conflicts')]
    public function test_one_explicit_request_identity_cannot_change_its_original_definition(string $change): void
    {
        $f = $this->purchaseFixture();
        $this->request($f, '20.00', 'stable-request');
        $before = $this->rows();
        try {
            app(ResponsibleGamingLimitService::class)->pronounce(new ResponsibleGamingLimitData($f['user']->id,
                $change === 'type' ? ResponsibleGamingLimitType::DailyLoss : ResponsibleGamingLimitType::SingleBet,
                $change === 'amount' ? '15.00' : '20.00', $change === 'currency' ? 'USD' : 'THB',
                $change === 'start' ? now()->addDay() : null, $change === 'end' ? now()->addDays(2) : null, 'stable-request'));
            $this->fail('An explicit request identity changed payload.');
        } catch (ResponsibleGamingLimitException $e) {
            $this->assertSame('RGL_MALFORMED', $e->errorCode());
        }
        $this->assertSame($before, $this->rows());
    }

    public static function barriers(): array
    {
        return [['ban'], ['legacy'], ['cool'], ['new'], ['expired'], ['basis'], ['fork'], ['ownerless']];
    }

    #[DataProvider('barriers')]
    public function test_due_pending_cannot_loosen_through_protection_expiry_changed_basis_or_unknown_history(string $mode): void
    {
        $f = $this->purchaseFixture();
        $svc = app(ResponsibleGamingLimitService::class);
        $active = $this->request($f, '20.00', 'initial-limit')['version'];
        $pending = $this->request($f, '30.00', 'increase-limit', to: $mode === 'expired' ? now()->addHours(26) : null)['version'];
        $this->travel($mode === 'expired' ? 27 : 25)->hours();
        match ($mode) {
            'ban' => $f['user']->forceFill(['status' => 'banned'])->save(),
            'legacy', 'cool' => ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id,
                $mode === 'legacy' ? 'self_excluded_until' : 'cool_off_until' => now()->addDay()]),
            'new' => $this->activateExclusion($f),
            'basis' => DB::table('responsible_gaming_limit_versions')->where('id', $active->id)->update(['amount' => '10.00']),
            'fork' => ResponsibleGamingLimitVersion::query()->create(['user_id' => $f['user']->id,
                'limit_type' => 'single_bet', 'amount' => '40.00', 'currency' => 'THB', 'limit_key' => str_repeat('f', 64),
                'limit_status' => 'pending', 'effective_from' => now()->addDay()]),
            'expired' => null,
            'ownerless' => DB::table('responsible_gaming_limit_ownerships')->where('version_id', $pending->id)->delete(),
        };
        $this->assertSame(0, $svc->activateDuePending());
        $this->assertSame('active', $active->fresh()->limit_status->value);
        $this->assertNotSame('active', $pending->fresh()->limit_status->value);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    private function activateExclusion(array $f): void
    {
        $svc = app(SelfExclusionService::class);
        $svc->activate($svc->request(new SelfExclusionData(userId: $f['user']->id,
            durationDays: 7, reason: 'protection')));
    }

    public function test_future_requested_start_and_expiry_are_respected_without_backdating_cool_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-17 00:00:00', 'UTC'));
        $f = $this->purchaseFixture();
        $this->request($f, '20.00', 'initial-limit');
        $p = $this->request($f, '30.00', 'future-start', to: new DateTimeImmutable('2026-10-22T06:00:00+06:00'),
            from: new DateTimeImmutable('2026-10-20T06:00:00+06:00'))['version'];
        $this->travel(25)->hours();
        $this->assertSame(0, app(ResponsibleGamingLimitService::class)->activateDuePending());
        $this->travelTo(Carbon::parse('2026-10-20 00:00:00', 'UTC'));
        $this->assertSame(1, app(ResponsibleGamingLimitService::class)->activateDuePending());
        $this->assertSame('active', $p->fresh()->limit_status->value);
    }

    public function test_end_before_actual_cool_off_is_refused_without_any_policy_or_summary_mutation(): void
    {
        $f = $this->purchaseFixture();
        $this->request($f, '20.00', 'initial-limit');
        $before = $this->rows();
        try {
            $this->request($f, '30.00', 'too-short-window', to: now()->addHours(12));
            $this->fail('An impossible pending window was accepted.');
        } catch (ResponsibleGamingLimitException $e) {
            $this->assertSame('RGL_MALFORMED', $e->errorCode());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_summary_and_all_submitted_types_are_atomic_and_report_binding_not_pending_amount(): void
    {
        $f = $this->purchaseFixture();
        $bridge = app(ResponsibleGamingService::class);
        $bridge->setLimits($f['user'], dailyDepositLimit: '50.00', singleBetLimit: '20.00');
        $record = $bridge->setLimits($f['user'], singleBetLimit: '30.00');
        $this->assertSame('20.00', $record->single_bet_limit);
        $before = $this->rows();
        try {
            $bridge->setLimits($f['user'], dailyDepositLimit: '40.00', singleBetLimit: '40.00');
            $this->fail('The conflicting increase should refuse the WHOLE summary operation.');
        } catch (ResponsibleGamingLimitException $e) {
            $this->assertSame('RGL_COOLDOWN_VIOLATION', $e->errorCode());
        }
        $this->assertSame($before, $this->rows());
    }

    #[DataProvider('currencies')]
    public function test_summary_bridge_uses_configured_betting_currency_and_reduced_policy_blocks_real_new_debit(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        app(ResponsibleGamingService::class)->setLimits($f['user'], singleBetLimit: '5.00');
        $this->assertSame($currency->value, ResponsibleGamingLimitVersion::query()->sole()->currency);
        $before = $this->rows();
        try {
            app(BetPurchaseService::class)->purchase(new BetPurchaseData($f['user']->id, $f['draw']->id,
                '3d_direct', '007', '10.00', 'after-atomic-reduction'));
            $this->fail('The stored lower choice did not block a real new paid wager.');
        } catch (ResponsibleGamingLimitException $e) {
            $this->assertSame('RGL_INVALID_AMOUNT', $e->errorCode());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_after_sql_audit_failure_rolls_back_summary_versions_and_ownership_but_preserves_caller_work(): void
    {
        $f = $this->purchaseFixture();
        $before = $this->rows();
        $armed = true;
        DB::listen(function (QueryExecuted $q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert into') && str_contains($q->sql, 'audit_logs')) {
                $armed = false;
                throw new RuntimeException('synthetic-after-policy-audit-sql');
            }
        });
        DB::transaction(function () use ($f): void {
            DB::table('users')->where('id', $f['user']->id)->update(['name' => 'Unrelated caller work']);
            try {
                app(ResponsibleGamingService::class)->setLimits($f['user'], singleBetLimit: '5.00');
                $this->fail('The armed SQL failure did not occur.');
            } catch (RuntimeException $e) {
                $this->assertSame('synthetic-after-policy-audit-sql', $e->getMessage());
            }
        });
        $after = $this->rows();
        unset($before['users'], $after['users']);
        $this->assertSame($before, $after);
        $this->assertSame('Unrelated caller work', $f['user']->fresh()->name);
    }

    public function test_ownerless_active_history_is_not_expired_or_enrolled_and_new_choice_can_be_made_after_owned_expiry(): void
    {
        $f = $this->purchaseFixture();
        $svc = app(ResponsibleGamingLimitService::class);
        $v = $this->request($f, '20.00', 'initial-limit', to: now()->addHour())['version'];
        DB::table('responsible_gaming_limit_ownerships')->where('version_id', $v->id)->delete();
        $this->travel(2)->hours();
        $this->assertSame(0, $svc->expireStale());
        $this->assertSame('active', $v->fresh()->limit_status->value);
        $this->assertSame(0, DB::table('responsible_gaming_limit_ownerships')->count());
        $new = $svc->choose(new ResponsibleGamingLimitData($f['user']->id, 'single_bet', '10.00', 'THB', effectiveTo: now()->addHour()))['version'];
        $this->travel(2)->hours();
        $this->assertSame(1, $svc->expireStale());
        $again = $svc->setSingleBetLimit($f['user']->id, '10.00');
        $this->assertNotSame($new->id, $again->id);
        $this->assertSame('active', $again->limit_status->value);
    }

    public function test_authenticated_explicit_request_endpoint_reports_history_and_refuses_payload_reuse(): void
    {
        $f = $this->purchaseFixture();
        $this->actingAs($f['user'], 'sanctum');
        $payload = ['limit_type' => 'single_bet', 'amount' => '20.00', 'currency' => 'THB', 'request_key' => 'authenticated-policy-request'];
        $this->postJson('/api/v1/responsible-gaming/limits/pronounce', $payload)->assertOk()->assertJsonPath('data.replayed', false);
        $before = $this->rows();
        $this->postJson('/api/v1/responsible-gaming/limits/pronounce', $payload)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.binding_amount', '20.00');
        $this->postJson('/api/v1/responsible-gaming/limits/pronounce', [...$payload, 'amount' => '30.00'])->assertStatus(422);
        $this->assertSame($before, $this->rows());
    }

    public function test_modified_owned_active_terms_or_changed_backend_timezone_cannot_grant_new_paid_permission(): void
    {
        $f = $this->purchaseFixture();
        $v = $this->request($f, '20.00', 'owned-policy-terms')['version'];
        DB::table('responsible_gaming_limit_versions')->where('id', $v->id)->update(['amount' => '30.00']);
        $before = $this->rows();
        try {
            DB::transaction(fn () => app(ResponsibleGamingLimitService::class)->getActiveSingleBetLimit($f['user']->id));
            $this->fail('Modified original policy terms granted permission.');
        } catch (ResponsibleGamingLimitException $e) {
            $this->assertSame('RGL_MALFORMED', $e->errorCode());
        }
        $this->assertSame($before, $this->rows());
        config(['app.timezone' => 'Asia/Dhaka']);
        try {
            $this->request($f, '5.00', 'backend-timezone-change');
            $this->fail('Changing backend timezone silently reinterpreted policy instants.');
        } catch (ResponsibleGamingLimitException $e) {
            $this->assertSame('RGL_MALFORMED', $e->errorCode());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_http_summary_refusal_is_a_safe_422_and_not_false_success_or_server_error(): void
    {
        $f = $this->purchaseFixture();
        $this->actingAs($f['user'], 'sanctum');
        $url = '/api/v1/responsible-gaming/limits';
        $this->putJson($url, ['single_bet_limit' => '20.00'])->assertOk();
        $this->putJson($url, ['single_bet_limit' => '30.00'])->assertOk()->assertJsonPath('data.single_bet_limit', '20.00')
            ->assertJsonPath('data.pending_limits.0.amount', '30.00');
        $before = $this->rows();
        $this->putJson($url, ['single_bet_limit' => '40.00'])->assertStatus(422)
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
        $this->assertSame($before, $this->rows());
        $this->putJson($url, ['single_bet_limit' => '5.00'])->assertOk()->assertJsonPath('data.single_bet_limit', '5.00');
    }
}
