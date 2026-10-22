<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetAmendmentData;
use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetAmendmentStatus;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseValidationException;
use App\Exceptions\RiskException;
use App\Models\AuditLog;
use App\Models\BetAmendment;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Betting\BetAmendmentPermit;
use App\Services\Betting\BetAmendmentReplacement;
use App\Services\Betting\BetAmendmentService;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseOwnership;
use App\Services\Betting\BetPurchaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Betting\Concerns\BuildsOwnedAmendment;
use Tests\Feature\Finance\FinancialMasterTestCase;

final class OwnedBetAmendmentTest extends FinancialMasterTestCase
{
    use BuildsOwnedAmendment;

    public static function currencies(): array
    {
        return ['THB-unpaid' => [Currency::THB, false], 'BDT-unpaid' => [Currency::BDT, false], 'USD-unpaid' => [Currency::USD, false],
            'THB-paid' => [Currency::THB, true], 'BDT-paid' => [Currency::BDT, true], 'USD-paid' => [Currency::USD, true]];
    }

    #[DataProvider('currencies')]
    public function test_real_refund_and_replacement_have_one_sealed_owner_and_readonly_replay(Currency $currency, bool $paid): void
    {
        $f = $this->amendmentFixture($currency, $paid);
        $result = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $this->assertSame(BetAmendmentStatus::Applied, $result->status);
        $this->assertSame('88.00', $f['wallet']->fresh()->balance);
        $this->assertSame(BetStatus::Cancelled, $f['bet']->fresh()->status);
        $this->assertSame('12.00', $result->chargedAmount);
        $this->assertSame('10.00', $result->refundedAmount);
        $this->assertSame(1, DB::table('bet_amendment_ownerships')->count());
        $this->assertSame('12.00', NumberLimit::where('draw_id', $f['draw']->id)->where('number', '008')->firstOrFail()->current_amount);
        $this->assertSame('0.00', NumberLimit::where('draw_id', $f['draw']->id)->where('number', '007')->firstOrFail()->current_amount);
        $before = $this->amendmentState();
        $replay = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $this->assertSame($result->replacementBet->id, $replay->replacementBet->id);
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_same_number_increased_stake_validates_against_our_released_reservation(): void
    {
        $f = $this->amendmentFixture();
        NumberLimit::where('draw_id', $f['draw']->id)->where('number', '007')->update(['max_amount' => '15.00']);
        $result = app(BetAmendmentService::class)->amend($this->amendmentCommand($f, number: '007'));
        $this->assertSame(BetAmendmentStatus::Applied, $result->status);
        $this->assertSame('12.00', NumberLimit::where('draw_id', $f['draw']->id)->where('number', '007')->firstOrFail()->current_amount);
    }

    public function test_canonical_proposal_validation_rolls_back_the_whole_source_refund(): void
    {
        $f = $this->amendmentFixture(Currency::THB, true);
        NumberLimit::where('draw_id', $f['draw']->id)->where('number', '008')->update(['max_amount' => '11.00']);
        $before = $this->amendmentState();
        try {
            app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
            $this->fail('Risk refusal expected.');
        } catch (BetDomainException|RiskException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_lost_acknowledgement_is_recovered_from_the_atomic_purchase_receipt(): void
    {
        $f = $this->amendmentFixture();
        $real = app(BetPurchaseService::class);
        $mock = Mockery::mock(BetAmendmentReplacement::class);
        $mock->shouldReceive('purchaseAmendment')->once()->andReturnUsing(function ($data, $permit) use ($real): never {
            $real->purchaseAmendment($data, $permit);
            throw new RuntimeException('Synthetic acknowledgement loss after commit.');
        });
        $this->app->instance(BetAmendmentReplacement::class, $mock);
        $result = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $this->assertSame(BetAmendmentStatus::Applied, $result->status);
        $before = $this->amendmentState();
        $this->assertSame($result->replacementBet->id, app(BetAmendmentService::class)->amend($this->amendmentCommand($f))->replacementBet->id);
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_unknown_outcome_is_permanent_no_resend_and_ordinary_purchase_cannot_bypass_the_missing_permit(): void
    {
        $f = $this->amendmentFixture();
        $mock = Mockery::mock(BetAmendmentReplacement::class);
        $mock->shouldReceive('purchaseAmendment')->once()->andThrow(new RuntimeException('Unknown transport result.'));
        $this->app->instance(BetAmendmentReplacement::class, $mock);
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $o = DB::table('bet_amendment_ownerships')->first();
        $this->assertSame('attempting', $o->state);
        $this->assertSame(BetAmendmentStatus::Pending, BetAmendment::first()->status);
        $this->app->forgetInstance(BetAmendmentReplacement::class);
        $before = $this->amendmentState();
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        $this->refused(fn () => app(BetPurchaseService::class)->purchaseFromRequest($f['user']->id,
            ['draw_id' => $f['draw']->id, 'market' => $o->market, 'number' => $o->new_number, 'stake' => $o->new_stake,
                'idempotency_key' => $o->replacement_client_key, 'permit' => ['ownerId' => $o->id, 'termsHash' => $o->terms_hash]]));
        $this->refused(fn () => DB::transaction(fn () => BetAmendmentPermit::forFreshClaim(BetAmendment::firstOrFail())));
        $this->assertSame(0, app(BetAmendmentService::class)->sweepExpired());
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_late_purchase_audit_failure_rolls_back_only_replacement_and_never_retries_it(): void
    {
        $f = $this->amendmentFixture();
        AuditLog::creating(static function (AuditLog $log): void {
            if ($log->auditable_type === BetAmendment::class) {
                throw new RuntimeException('Synthetic late amendment audit failure.');
            }
        });
        try {
            $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(1, DB::table('bets')->count());
        $this->assertSame(3, DB::table('financial_transactions')->count());
        $this->assertSame('attempting', DB::table('bet_amendment_ownerships')->value('state'));
        $this->assertSame('0.00', NumberLimit::where('draw_id', $f['draw']->id)->where('number', '008')->firstOrFail()->current_amount);
        $before = $this->amendmentState();
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_known_pre_mutation_validation_decline_is_a_proven_failed_refunded_result(): void
    {
        $f = $this->amendmentFixture();
        $mock = Mockery::mock(BetAmendmentReplacement::class);
        $mock->shouldReceive('purchaseAmendment')->once()->andThrow(BetPurchaseValidationException::withCode('bet_draw_closed', 'Synthetic validated refusal.'));
        $this->app->instance(BetAmendmentReplacement::class, $mock);
        $result = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $this->assertSame(BetAmendmentStatus::Failed, $result->status);
        $this->assertNull($result->replacementBet);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $before = $this->amendmentState();
        $this->assertSame(BetAmendmentStatus::Failed, app(BetAmendmentService::class)->amend($this->amendmentCommand($f))->status);
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_real_insufficient_funds_keeps_the_refund_and_has_no_replacement(): void
    {
        $f = $this->amendmentFixture();
        $result = app(BetAmendmentService::class)->amend($this->amendmentCommand($f, stake: '101.00'));
        $this->assertSame(BetAmendmentStatus::Failed, $result->status);
        $this->assertSame('bet_purchase_insufficient_balance', $result->failureReason);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(1, DB::table('bets')->count());
    }

    public function test_actor_scope_payload_key_and_source_conflicts_do_not_move_any_money(): void
    {
        $f = $this->amendmentFixture();
        app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $other = User::factory()->create();
        $before = $this->amendmentState();
        $this->refused(fn () => app(BetAmendmentService::class)->amend(new BetAmendmentData($other->id, (string) $f['bet']->id, '008', '12.00', 'owned-amendment-request-one')));
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f, stake: '13.00')));
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f, key: 'competing-amendment-request')));
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_full_length_keys_for_same_user_draw_and_identical_prefix_do_not_alias(): void
    {
        $f = $this->amendmentFixture();
        $first = app(BetAmendmentService::class)->amend($this->amendmentCommand($f, str_repeat('x', 127).'a'));
        $second = app(BetPurchaseService::class)->purchase(new BetPurchaseData($f['user']->id, $f['draw']->id, '3d_direct', '007', '10.00', 'second-original-funded-purchase'));
        $f['bet'] = $second->bet;
        $next = app(BetAmendmentService::class)->amend($this->amendmentCommand($f, str_repeat('x', 127).'b'));
        $this->assertNotSame($first->replacementBet->id, $next->replacementBet->id);
        $this->assertSame('76.00', $f['wallet']->fresh()->balance);
        $this->assertSame(2, DB::table('bet_amendment_ownerships')->distinct()->count('replacement_financial_key'));
    }

    public function test_applied_replay_does_not_revalidate_a_closed_draw_or_current_creation_policy(): void
    {
        $f = $this->amendmentFixture();
        $r = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        Draw::whereKey($f['draw']->id)->update(['betting_close_at' => now()->subDay()]);
        config(['lottery.amendment.enabled' => false]);
        $before = $this->amendmentState();
        $this->assertSame($r->replacementBet->id, app(BetAmendmentService::class)->amend($this->amendmentCommand($f))->replacementBet->id);
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_winning_fields_are_readonly_funding_facts_not_new_cancellation_permission(): void
    {
        $f = $this->amendmentFixture();
        $r = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        DB::table('bet_items')->where('bet_id', $r->replacementBet->id)->update(['is_winner' => true, 'actual_payout' => '50.00']);
        $r->replacementBet->forceFill(['status' => BetStatus::Won, 'actual_payout' => '50.00'])->save();
        $before = $this->amendmentState();
        $this->assertSame(BetAmendmentStatus::Applied, app(BetAmendmentService::class)->amend($this->amendmentCommand($f))->status);
        $this->refused(fn () => DB::transaction(fn () => app(BetPurchaseOwnership::class)->locked($r->replacementBet->fresh(), $f['wallet']->fresh())));
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_http_domain_refusal_and_applied_replay_use_valid_exception_methods(): void
    {
        $f = $this->amendmentFixture();
        $this->actingAs($f['user'], 'sanctum');
        $url = '/api/v1/bets/'.$f['bet']->id.'/amend';
        $payload = ['number' => '008', 'stake' => '12.00', 'client_key' => 'owned-http-amendment-key'];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'applied');
        $before = $this->amendmentState();
        $payload['stake'] = '13.00';
        $this->postJson($url, $payload)->assertStatus(422)->assertJsonPath('error.code', 'bet_amendment_request_conflict');
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_later_real_replacement_cancellation_does_not_erase_its_funded_amendment_fact(): void
    {
        $f = $this->amendmentFixture();
        $r = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        app(BetCancellationService::class)->cancel(new BetCancellationData(
            $f['user']->id, (string) $r->replacementBet->id, BetCancellationReason::PlayerRequest));
        $before = $this->amendmentState();
        $this->assertSame(BetAmendmentStatus::Applied, app(BetAmendmentService::class)->amend($this->amendmentCommand($f))->status);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_typed_decline_after_actual_commit_cannot_turn_paid_receipt_into_failed(): void
    {
        $f = $this->amendmentFixture();
        $real = app(BetPurchaseService::class);
        $mock = Mockery::mock(BetAmendmentReplacement::class);
        $mock->shouldReceive('purchaseAmendment')->once()->andReturnUsing(function ($data, $permit) use ($real): never {
            $real->purchaseAmendment($data, $permit);
            throw BetPurchaseException::withCode('bet_purchase_insufficient_balance', 'Synthetic misleading post-commit error.');
        });
        $this->app->instance(BetAmendmentReplacement::class, $mock);
        $this->assertSame(BetAmendmentStatus::Applied, app(BetAmendmentService::class)->amend($this->amendmentCommand($f))->status);
        $this->assertSame('88.00', $f['wallet']->fresh()->balance);
        $this->assertNull(DB::table('bet_amendment_ownerships')->value('failure_code'));
    }

    public function test_same_raw_request_key_is_scoped_to_each_authenticated_owner(): void
    {
        $one = $this->amendmentFixture();
        $two = $this->amendmentFixture();
        $a = app(BetAmendmentService::class)->amend($this->amendmentCommand($one));
        $b = app(BetAmendmentService::class)->amend($this->amendmentCommand($two));
        $this->assertNotSame($a->amendment->id, $b->amendment->id);
        $this->assertSame($two['user']->id, $b->amendment->user_id);
        $this->assertSame(2, DB::table('bet_amendment_ownerships')->count());
    }

    public function test_unbound_historical_applied_label_is_never_enrolled_or_replayed(): void
    {
        $f = $this->amendmentFixture();
        BetAmendment::create(['uuid' => (string) Str::uuid(), 'user_id' => $f['user']->id,
            'bet_id' => $f['bet']->id, 'status' => BetAmendmentStatus::Applied, 'currency' => 'THB',
            'old_number' => '007', 'old_stake' => '10.00', 'new_number' => '008', 'new_stake' => '12.00',
            'refunded_amount' => '10.00', 'charged_amount' => '12.00', 'idempotency_key' => 'owned-amendment-request-one']);
        $before = $this->amendmentState();
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        $this->assertSame($before, $this->amendmentState());
        $this->assertSame(0, DB::table('bet_amendment_ownerships')->count());
    }

    public function test_owned_attempt_is_not_expired_even_if_the_report_clock_is_changed(): void
    {
        $f = $this->amendmentFixture();
        $mock = Mockery::mock(BetAmendmentReplacement::class);
        $mock->shouldReceive('purchaseAmendment')->once()->andThrow(new RuntimeException('Unknown result.'));
        $this->app->instance(BetAmendmentReplacement::class, $mock);
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        DB::table('bet_amendments')->update(['expires_at' => now()->subYear()]);
        $before = $this->amendmentState();
        $this->assertSame(0, app(BetAmendmentService::class)->sweepExpired());
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        $this->assertSame($before, $this->amendmentState());
    }

    public function test_ambient_root_and_populated_schema_erasure_are_refused_without_mutation(): void
    {
        $f = $this->amendmentFixture();
        $before = $this->amendmentState();
        $this->refused(fn () => DB::transaction(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f))));
        $this->assertSame($before, $this->amendmentState());
        app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $before = $this->amendmentState();
        $migration = require base_path('database/migrations/2026_10_17_000100_create_bet_amendment_ownerships_table.php');
        try {
            $migration->down();
            $this->fail('Non-expiring ownership must survive rollback attempts.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Refusing to erase', $error->getMessage());
        }
        $this->assertSame($before, $this->amendmentState());
    }

    public static function corruptions(): array
    {
        return array_map(fn ($f) => [$f], ['terms_hash', 'source_hash', 'request_hash', 'replacement_hash', 'replacement_financial_key',
            'new_stake', 'refund_transaction_id', 'replacement_ownership_id', 'report_charge', 'report_replacement', 'funded_debit', 'replacement_number']);
    }

    #[DataProvider('corruptions')]
    public function test_terminal_report_or_receipt_corruption_is_not_adopted_or_repaired(string $field): void
    {
        $f = $this->amendmentFixture();
        $r = app(BetAmendmentService::class)->amend($this->amendmentCommand($f));
        $o = DB::table('bet_amendment_ownerships')->first();
        match ($field) {
            'report_charge' => DB::table('bet_amendments')->where('id', $o->amendment_id)->update(['charged_amount' => '13.00']),
            'report_replacement' => DB::table('bet_amendments')->where('id', $o->amendment_id)->update(['replacement_bet_id' => $f['bet']->id]),
            'funded_debit' => DB::table('financial_transactions')->where('id', DB::table('bet_purchase_ownerships')->where('id', $o->replacement_ownership_id)->value('original_transaction_id'))->update(['amount' => '13.00']),
            'replacement_number' => DB::table('bet_items')->where('bet_id', $r->replacementBet->id)->update(['number' => '009']),
            'refund_transaction_id' => DB::table('bet_amendment_ownerships')->where('id', $o->id)->update([$field => DB::table('bet_purchase_ownerships')->where('id', $o->purchase_ownership_id)->value('original_transaction_id')]),
            'replacement_ownership_id' => DB::table('bet_amendment_ownerships')->where('id', $o->id)->update([$field => $o->purchase_ownership_id]),
            'new_stake' => DB::table('bet_amendment_ownerships')->where('id', $o->id)->update([$field => '13.00']),
            'replacement_financial_key' => DB::table('bet_amendment_ownerships')->where('id', $o->id)->update([$field => 'changed-key']),
            default => DB::table('bet_amendment_ownerships')->where('id', $o->id)->update([$field => str_repeat('0', 64)]),
        };
        $before = $this->amendmentState();
        $this->refused(fn () => app(BetAmendmentService::class)->amend($this->amendmentCommand($f)));
        $this->assertSame($before, $this->amendmentState());
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Original-owned refusal expected.');
        } catch (BetDomainException $error) {
            $this->assertNotEmpty($error->errorCode());
        }
    }
}
