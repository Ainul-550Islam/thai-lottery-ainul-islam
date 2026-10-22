<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Models\User;
use App\Services\Payment\DepositReviewAccess;
use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\WithdrawalProviderAccountService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripeDepositReviewFixture;

final class StripeDepositReviewTest extends PaymentTestCase
{
    use StripeDepositReviewFixture;

    private const NOTE = 'Reviewed processor evidence; escalation ticket SYNTHETIC-123 remains open.';

    private function inspect(array $f): ?array
    {
        return app(StripeDepositReviewService::class)->inspect($f['deposit'], $f['actor']);
    }

    private function ack(array $f, array $case, string $note = self::NOTE): array
    {
        return app(StripeDepositReviewService::class)->acknowledge($f['deposit'], $f['actor'], $case['case_uuid'], $case['version'], $case['evidence_hash'], $note);
    }

    public function test_real_signed_http_ingress_case_ui_ack_and_exact_replay_never_mutate_money(): void
    {
        $f = $this->reviewFixture();
        $paper = $this->moneyPaper();
        [$body, $signature] = $this->signedBody($this->followupEvent());
        $url = '/api/v1/payments/stripe-followups';
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body)
            ->assertOk()->assertJson(['recorded' => true, 'replayed' => false, 'financial_application' => false]);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body)
            ->assertOk()->assertJson(['replayed' => true]);
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 1);
        $this->actingAs($f['actor']);
        $specs = collect(DepositDecisionActions::specs());
        $inspect = $specs->firstWhere('name', 'inspectStripeFollowups');
        $ack = $specs->firstWhere('name', 'acknowledgeStripeFollowups');
        $this->assertTrue(($inspect['visible'])($f['deposit']->fresh()));
        ($inspect['action'])($f['deposit']->fresh());
        Notification::assertNotified('Stripe follow-up inspection — no money changed');
        $case = $this->inspect($f);
        $this->assertSame('open', $case['state']);
        $this->assertSame(1, $case['version']);
        $this->assertTrue(($ack['visible'])($f['deposit']->fresh()));
        ($ack['action'])($f['deposit']->fresh(), $case + ['note' => self::NOTE]);
        Notification::assertNotified('Current evidence acknowledged. This is not refund execution, risk clearance or financial resolution.');
        $this->assertSame('acknowledged', $this->inspect($f)['state']);
        $this->assertTrue($this->ack($f, $case)['replayed']);
        $details = app(StripeDepositReviewService::class)->details($f['deposit'], $f['actor']);
        $this->assertSame(2500, $details['events'][0]['amount_minor']);
        $this->assertSame(self::NOTE, $details['acknowledgements'][0]['note']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode($details));
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 1);
        $this->assertDatabaseCount('payment_webhooks', 0);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
        foreach ([StripeDepositFollowupInbox::TABLE, StripeDepositReviewService::OBSERVATIONS, StripeDepositReviewService::ACKNOWLEDGEMENTS] as $table) {
            $this->assertStringNotContainsString(self::NOTE, DB::table($table)->get()->toJson());
            $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-FOLLOWUP', DB::table($table)->get()->toJson());
        }
    }

    public static function kinds(): array
    {
        return array_map(fn ($type) => [$type], StripeDepositFollowupInbox::TYPES);
    }

    #[DataProvider('kinds')]
    public function test_each_supported_signed_refund_and_dispute_kind_is_durable_review_not_reversal(string $type): void
    {
        $f = $this->reviewFixture();
        $paper = $this->moneyPaper();
        $this->receiveFollowup($this->followupEvent($type));
        $case = $this->inspect($f);
        $this->assertSame(1, $case['observation_count']);
        $this->assertSame('open', $case['state']);
        $this->assertFalse($this->ack($f, $case)['replayed']);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertNothingSent();
    }

    public static function badSignatures(): array
    {
        return [['old'], ['future'], ['missing'], ['wrong_body'], ['wrong_secret'], ['duplicate_timestamp'], ['invalid_v1'], ['only_v0']];
    }

    #[DataProvider('badSignatures')]
    public function test_raw_signature_timestamp_and_bytes_are_required_before_any_inbox_write(string $kind): void
    {
        // Keep the signed future-301s refusal outside tolerance despite scheduler delay.
        $this->freezeTime();
        $this->reviewFixture();
        [$body, $sig] = $this->signedBody($this->followupEvent(), now()->timestamp + ($kind === 'old' ? -301 : ($kind === 'future' ? 301 : 0)));
        $sig = match ($kind) {
            'missing' => null, 'wrong_secret' => 't='.now()->timestamp.',v1='.str_repeat('0', 64),
            'duplicate_timestamp' => $sig.',t='.now()->timestamp,
            'invalid_v1' => 't='.now()->timestamp.',v1=NOTHEX', 'only_v0' => 't='.now()->timestamp.',v0='.str_repeat('0', 64), default => $sig,
        };
        if ($kind === 'wrong_body') {
            $body .= ' ';
        }
        $this->reviewRefuses(fn () => app(StripeDepositFollowupInbox::class)->receive($body, $sig), 'stripe_followup_signature');
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 0);
    }

    public static function malformedEvents(): array
    {
        return [['livemode', true], ['created', 1.0], ['created', PHP_INT_MAX], ['id', 'evt_BAD'],
            ['type', 'payment_intent.succeeded'], ['account', 'acct_CONNECT'], ['object', 'charge'], ['data.object', 'not-an-object']];
    }

    #[DataProvider('malformedEvents')]
    public function test_valid_signature_does_not_approve_unsupported_or_malformed_event(string $field, mixed $value): void
    {
        $this->reviewFixture();
        $event = $this->followupEvent();
        data_set($event, $field, $value);
        $this->reviewRefuses(fn () => $this->receiveFollowup($event), 'stripe_followup_unproven');
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 0);
    }

    public function test_second_valid_rotation_signature_is_accepted_but_event_id_changed_bytes_conflict(): void
    {
        $this->reviewFixture();
        [$body, $signature] = $this->signedBody($this->followupEvent());
        $this->assertFalse(app(StripeDepositFollowupInbox::class)->receive($body, $signature.',v1='.str_repeat('0', 64))['replayed']);
        $old = DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        $event = $this->followupEvent();
        $event['data']['object']['amount'] = 5000;
        $this->reviewRefuses(fn () => $this->receiveFollowup($event), 'stripe_followup_conflict');
        $this->assertSame($old->response_cipher, DB::table(StripeDepositFollowupInbox::TABLE)->sole()->response_cipher);
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 1);
    }

    public function test_new_risk_commits_new_open_version_but_refuses_old_screen_without_acknowledging_it(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        $this->ack($f, $case);
        $old = DB::table(StripeDepositReviewService::ACKNOWLEDGEMENTS)->sole();
        $this->receiveFollowup($this->followupEvent('charge.dispute.created', 'evt_SYNTHETICREVIEW002'));
        $this->reviewRefuses(fn () => $this->ack($f, $case), 'stripe_review_stale');
        $next = $this->inspect($f);
        $this->assertSame(2, $next['version']);
        $this->assertSame(2, $next['observation_count']);
        $this->assertSame('open', $next['state']);
        $this->assertNotSame($case['evidence_hash'], $next['evidence_hash']);
        $this->assertSame($old->note_cipher, DB::table(StripeDepositReviewService::ACKNOWLEDGEMENTS)->sole()->note_cipher);
        $this->ack($f, $next);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 2);
        $this->assertSame('acknowledged', $this->inspect($f)['state']);
    }

    public function test_late_arrival_and_closed_dispute_append_history_never_erase_earlier_risk(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup($this->followupEvent('charge.dispute.created'));
        $first = $this->inspect($f);
        $this->ack($f, $first);
        $event = $this->followupEvent('charge.dispute.closed', 'evt_SYNTHETICREVIEWCLOSED');
        $event['created'] -= 86400;
        $event['data']['object']['status'] = 'won';
        $this->receiveFollowup($event);
        $next = $this->inspect($f);
        $this->assertSame('open', $next['state']);
        $this->assertSame(2, $next['observation_count']);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 2);
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 1);
    }

    public function test_unrelated_account_and_charge_do_not_create_or_clear_original_case(): void
    {
        $f = $this->reviewFixture();
        $event = $this->followupEvent();
        $event['data']['object']['charge'] = 'ch_UNRELATED123456';
        $event['data']['object']['payment_intent'] = 'pi_UNRELATED123456';
        $this->receiveFollowup($event);
        $this->assertNull($this->inspect($f));
        config(['payment.deposit_reference_accounts.stripe' => 'other-approved-label']);
        $this->receiveFollowup($this->followupEvent('refund.created', 'evt_SYNTHETICOTHERACCOUNT'));
        config(['payment.deposit_reference_accounts.stripe' => WithdrawalProviderAccountService::LEGACY]);
        $this->assertNull($this->inspect($f));
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
    }

    public static function contradictoryFacts(): array
    {
        return [['charge', 'ch_OTHER123456789'], ['charge', ['id' => 'ch_SYNTHETICREVIEW123']], ['currency', 'usd'], ['livemode', true], ['amount_refunded', '2500']];
    }

    #[DataProvider('contradictoryFacts')]
    public function test_linked_but_contradictory_facts_remain_durable_inbox_not_review_authority(string $field, mixed $value): void
    {
        $f = $this->reviewFixture();
        $event = $this->followupEvent();
        $event['data']['object'][$field] = $value;
        $this->receiveFollowup($event);
        $this->reviewRefuses(fn () => $this->inspect($f));
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 1);
    }

    public static function custodyDamage(): array
    {
        return [['inbox_hash'], ['inbox_cipher'], ['observation_hash'], ['observation_cipher'], ['case_count'], ['case_version'], ['missing_observation'], ['ack_cipher']];
    }

    #[DataProvider('custodyDamage')]
    public function test_required_custody_corruption_cannot_be_silently_replaced(string $kind): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        $this->ack($f, $case);
        if (str_starts_with($kind, 'inbox_')) {
            DB::table(StripeDepositFollowupInbox::TABLE)->update([$kind === 'inbox_hash' ? 'response_hash' : 'response_cipher' => $kind === 'inbox_hash' ? str_repeat('0', 64) : 'broken']);
        } elseif (str_starts_with($kind, 'observation_')) {
            DB::table(StripeDepositReviewService::OBSERVATIONS)->update([$kind === 'observation_hash' ? 'response_hash' : 'response_cipher' => $kind === 'observation_hash' ? str_repeat('0', 64) : 'broken']);
        } elseif ($kind === 'case_count') {
            DB::table(StripeDepositReviewService::CASES)->update(['observation_count' => 0]);
        } elseif ($kind === 'case_version') {
            DB::table(StripeDepositReviewService::CASES)->update(['version' => 4294967295]);
        } elseif ($kind === 'missing_observation') {
            DB::table(StripeDepositReviewService::OBSERVATIONS)->delete();
        } else {
            DB::table(StripeDepositReviewService::ACKNOWLEDGEMENTS)->update(['note_cipher' => 'broken']);
        }
        $this->reviewRefuses(fn () => $this->inspect($f), str_starts_with($kind, 'inbox_') ? 'stripe_followup_unproven' : 'stripe_review_unproven');
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 1);
    }

    public static function staffChanges(): array
    {
        return [['none'], ['banned'], ['deleted'], ['no_role'], ['no_permission']];
    }

    #[DataProvider('staffChanges')]
    public function test_fresh_staff_and_separate_permission_are_required_for_acknowledgement(string $kind): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        if ($kind === 'none') {
            $f['actor'] = null;
        } elseif ($kind === 'no_role') {
            $f['actor']->removeRole('admin');
        } elseif ($kind === 'no_permission') {
            $f['actor']->revokePermissionTo(DepositReviewAccess::REVIEW);
        } else {
            DB::table('users')->where('id', $f['actor']->id)->update($kind === 'banned' ? ['status' => 'banned'] : ['deleted_at' => now()]);
        }
        try {
            $this->ack($f, $case);
            $this->fail('Stale staff was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
            $this->assertSame('open', DB::table(StripeDepositReviewService::CASES)->sole()->state);
        }
    }

    public function test_permission_is_not_automatically_given_to_normal_admin_and_feature_defaults_off(): void
    {
        $f = $this->reviewFixture();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertFalse(app(DepositReviewAccess::class)->can($admin));
        config(['payment.deposit_review.stripe.enabled' => false]);
        $this->reviewRefuses(fn () => $this->inspect($f), 'stripe_review_disabled');
        $this->reviewRefuses(fn () => $this->receiveFollowup(), 'stripe_review_disabled');
        $this->assertFalse((bool) (require base_path('config/payment.php'))['deposit_review']['stripe']['enabled']);
    }

    public function test_current_actor_is_rechecked_after_history_read_and_all_new_case_writes_roll_back(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $armed = true;
        DB::listen(function ($query) use ($f, &$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, StripeDepositFollowupInbox::TABLE)) {
                $armed = false;
                DB::table('users')->where('id', $f['actor']->id)->update(['status' => 'banned']);
            }
        });
        try {
            $this->inspect($f);
            $this->fail('Actor revocation inside review was ignored.');
        } catch (AuthorizationException) {
            $this->assertFalse($armed);
            $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
            $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 0);
        }
    }

    public function test_ack_note_is_immutable_and_different_actor_or_note_is_not_a_successful_replay(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        $this->ack($f, $case);
        $old = DB::table(StripeDepositReviewService::ACKNOWLEDGEMENTS)->sole();
        $this->reviewRefuses(fn () => $this->ack($f, $case, 'Different findings cannot replace the original note.'), 'stripe_review_conflict');
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $actor->givePermissionTo(DepositReviewAccess::REVIEW);
        $f['actor'] = $actor;
        $this->reviewRefuses(fn () => $this->ack($f, $case), 'stripe_review_conflict');
        $this->assertSame($old->note_cipher, DB::table(StripeDepositReviewService::ACKNOWLEDGEMENTS)->sole()->note_cipher);
    }

    public function test_ambient_transaction_and_bad_form_tokens_never_acknowledge(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        $this->reviewRefuses(fn () => $this->ack($f, $case, 'too short'), 'stripe_review_input');
        $this->reviewRefuses(fn () => $this->ack($f, $case, str_repeat('x', 2001)), 'stripe_review_input');
        $bad = $case;
        $bad['evidence_hash'] = str_repeat('0', 64);
        $this->reviewRefuses(fn () => $this->ack($f, $bad), 'stripe_review_stale');
        DB::beginTransaction();
        try {
            $this->reviewRefuses(fn () => $this->inspect($f), 'checkout_requires_top_level');
            $this->reviewRefuses(fn () => $this->ack($f, $case), 'checkout_requires_top_level');
            $this->reviewRefuses(fn () => $this->receiveFollowup(), 'checkout_requires_top_level');
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 0);
    }

    public function test_full_scan_saturation_never_certifies_a_partial_case_or_replays_an_ack(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        $this->ack($f, $case);
        $row = (array) DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        unset($row['id']);
        $batch = [];
        for ($i = 1; $i <= StripeDepositReviewService::SCAN_LIMIT; $i++) {
            $batch[] = array_replace($row, ['event_key' => hash('sha256', 'synthetic-saturation-'.$i)]);
            if (count($batch) === 100) {
                DB::table(StripeDepositFollowupInbox::TABLE)->insert($batch);
                $batch = [];
            }
        }
        $this->reviewRefuses(fn () => $this->ack($f, $case), 'stripe_review_scan_incomplete');
        $this->assertDatabaseCount(StripeDepositReviewService::ACKNOWLEDGEMENTS, 1);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 1);
    }

    public function test_case_saturation_never_commits_only_the_first_supported_observations(): void
    {
        $f = $this->reviewFixture();
        for ($i = 0; $i <= StripeDepositReviewService::CASE_LIMIT; $i++) {
            $this->receiveFollowup($this->followupEvent('refund.created', 'evt_SYNTHETICMANY'.str_pad((string) $i, 8, '0', STR_PAD_LEFT)));
        }
        $this->reviewRefuses(fn () => $this->inspect($f), 'stripe_review_case_incomplete');
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 0);
    }

    public function test_injected_observation_failure_rolls_back_case_but_retains_independently_committed_inbox(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $paper = $this->moneyPaper();
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, StripeDepositReviewService::OBSERVATIONS)) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC review write failure');
            }
        });
        try {
            $this->inspect($f);
            $this->fail('Injected failure did not run.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC review write failure', $e->getMessage());
        }
        $this->assertDatabaseCount(StripeDepositReviewService::CASES, 0);
        $this->assertDatabaseCount(StripeDepositReviewService::OBSERVATIONS, 0);
        $this->assertDatabaseCount(StripeDepositFollowupInbox::TABLE, 1);
        $this->assertSame(1, $this->inspect($f)['observation_count']);
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_lower_id_receipt_published_after_inspection_is_not_skipped_by_a_high_water_cursor(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $delayed = (array) DB::table(StripeDepositFollowupInbox::TABLE)->sole();
        DB::table(StripeDepositFollowupInbox::TABLE)->where('id', $delayed['id'])->delete();
        $this->receiveFollowup($this->followupEvent('refund.updated', 'evt_SYNTHETICHIGHERID'));
        $case = $this->inspect($f);
        $this->ack($f, $case);
        // Controlled late-publication fixture; not an actual process-crash or uncommitted-writer certification.
        DB::table(StripeDepositFollowupInbox::TABLE)->insert($delayed);
        $next = $this->inspect($f);
        $this->assertSame(2, $next['version']);
        $this->assertSame(2, $next['observation_count']);
        $this->assertSame('open', $next['state']);
        $this->assertSame(1, count(app(StripeDepositReviewService::class)->details($f['deposit'], $f['actor'])['acknowledgements']));
        $this->ack($f, $next);
        $this->assertSame('acknowledged', $this->inspect($f)['state']);
    }

    public function test_operator_history_escapes_note_markup_and_never_renders_private_provider_fields(): void
    {
        $f = $this->reviewFixture();
        $this->receiveFollowup();
        $case = $this->inspect($f);
        $this->ack($f, $case, 'Escalation <script>alert(1)</script> remains an inert review note.');
        $this->actingAs($f['actor']);
        $spec = collect(DepositDecisionActions::specs())->firstWhere('name', 'inspectStripeFollowups');
        ($spec['action'])($f['deposit']->fresh());
        $messages = session('filament.notifications', []);
        $message = collect($messages)->last(fn ($message) => ($message['title'] ?? null) === 'Stripe follow-up inspection — no money changed');
        $this->assertNotNull($message, json_encode($messages));
        $body = (string) $message['body'];
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-FOLLOWUP', $body);
    }
}
