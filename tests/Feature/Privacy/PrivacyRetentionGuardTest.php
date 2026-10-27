<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\PrivacyRequestStatus;
use App\Enums\WithdrawalStatus;
use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\Privacy\PrivacyRetentionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "May this be erased?" - and the answer is a reason, never an action.
 *
 * The guard is a READER. These tests assert two things: that it names every
 * obligation that applies, and that it writes nothing at all while doing so. A
 * test that only checked the first would pass on an implementation that quietly
 * deleted a ledger row to make an erasure possible.
 */
final class PrivacyRetentionGuardTest extends TestCase
{
    use RefreshDatabase;

    private PrivacyRetentionGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('privacy_requests.retention.financial_records_days', 2555);
        config()->set('privacy_requests.retention.aml_records_days', 2555);

        $this->guard = app(PrivacyRetentionGuard::class);
    }

    private function userWithWallet(): array
    {
        $user = User::factory()->create();

        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $user->getKey(),
            'type' => 'cash',
            'status' => 'active',
            'currency' => 'THB',
            'balance' => '0.00',
            'locked_balance' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $walletId];
    }

    private function request(User $user, array $overrides = []): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create(array_merge([
            'user_id' => (int) $user->getKey(),
            'email_encrypted' => (string) $user->email,
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'status' => PrivacyRequestStatus::Received->value,
            'verified_at' => Carbon::now(),
            'received_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(30),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function codes(array $report): array
    {
        return array_column($report['reasons'], 'code');
    }

    public function test_01_a_clean_account_with_no_obligations_is_not_blocked(): void
    {
        $user = User::factory()->create();
        $report = $this->guard->evaluate($this->request($user));

        $this->assertFalse($report['blocked']);
        $this->assertSame([], $report['reasons']);
        $this->assertSame('privacy_requests.retention.clear', $this->guard->summaryKey($report));
    }

    public function test_02_recent_financial_records_block_erasure(): void
    {
        [$user, $walletId] = $this->userWithWallet();

        DB::table('financial_transactions')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'TXN-RET-1',
            'user_id' => $user->getKey(),
            'wallet_id' => $walletId,
            'type' => 'deposit',
            'status' => 'completed',
            'currency' => 'THB',
            'amount' => '100.00',
            'fee' => '0.00',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->guard->evaluate($this->request($user));

        $this->assertTrue($report['blocked']);
        $this->assertContains(PrivacyRetentionGuard::REASON_FINANCIAL, $this->codes($report));
        $this->assertSame('privacy_requests.retention.blocked', $this->guard->summaryKey($report));
    }

    public function test_03_records_older_than_the_window_do_not_block(): void
    {
        [$user, $walletId] = $this->userWithWallet();

        DB::table('financial_transactions')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'TXN-OLD-1',
            'user_id' => $user->getKey(),
            'wallet_id' => $walletId,
            'type' => 'deposit',
            'status' => 'completed',
            'currency' => 'THB',
            'amount' => '100.00',
            'fee' => '0.00',
            'processed_at' => Carbon::now()->subDays(3000),
            'created_at' => Carbon::now()->subDays(3000),
            'updated_at' => Carbon::now()->subDays(3000),
        ]);

        $report = $this->guard->evaluate($this->request($user));

        $this->assertNotContains(PrivacyRetentionGuard::REASON_FINANCIAL, $this->codes($report));
    }

    public function test_04_an_open_withdrawal_blocks_erasure(): void
    {
        [$user, $walletId] = $this->userWithWallet();

        DB::table('withdrawals')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'WDR-OPEN-1',
            'user_id' => $user->getKey(),
            'wallet_id' => $walletId,
            'method' => 'manual',
            'status' => WithdrawalStatus::Pending->value,
            'currency' => 'THB',
            'amount' => '200.00',
            'fee' => '0.00',
            'net_amount' => '200.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains(
            PrivacyRetentionGuard::REASON_OPEN_WITHDRAWAL,
            $this->codes($this->guard->evaluate($this->request($user))),
        );
    }

    public function test_05_an_open_compliance_case_blocks_erasure(): void
    {
        $user = User::factory()->create();

        DB::table('compliance_cases')->insert([
            'case_key' => 'CASE-'.bin2hex(random_bytes(6)),
            'subject_user_id' => $user->getKey(),
            'case_type' => 'dispute',
            'risk_level' => 'medium',
            'evidence_fingerprint' => 'fp-'.bin2hex(random_bytes(8)),
            'status' => 'investigating',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains(
            PrivacyRetentionGuard::REASON_OPEN_DISPUTE,
            $this->codes($this->guard->evaluate($this->request($user))),
        );
    }

    public function test_06_an_active_self_exclusion_blocks_erasure(): void
    {
        $user = User::factory()->create();

        DB::table('responsible_gaming_limits')->insert([
            'user_id' => $user->getKey(),
            'self_excluded_until' => Carbon::now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains(
            PrivacyRetentionGuard::REASON_SELF_EXCLUSION,
            $this->codes($this->guard->evaluate($this->request($user))),
        );
    }

    public function test_07_a_financial_hold_blocks_erasure(): void
    {
        [$user, $walletId] = $this->userWithWallet();

        DB::table('financial_holds')->insert([
            'hold_key' => 'HOLD-'.bin2hex(random_bytes(6)),
            'wallet_id' => $walletId,
            'amount' => '50.00',
            'currency' => 'THB',
            'reason' => 'chargeback',
            'source_reference' => 'HOLD-SRC-1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains(
            PrivacyRetentionGuard::REASON_LEGAL_HOLD,
            $this->codes($this->guard->evaluate($this->request($user))),
        );
    }

    public function test_08_a_legal_hold_recorded_on_the_request_itself_blocks_erasure(): void
    {
        $user = User::factory()->create();

        $report = $this->guard->evaluate($this->request($user, ['legal_hold_reason' => 'Litigation hold 2026-11']));

        $this->assertContains(PrivacyRetentionGuard::REASON_LEGAL_HOLD_ON_REQUEST, $this->codes($report));
    }

    public function test_09_an_unresolvable_request_reports_unknown_user_instead_of_guessing(): void
    {
        $report = $this->guard->evaluate(DataSubjectRequest::factory()->create([
            'user_id' => null,
            'email_encrypted' => 'ghost@example.test',
            'email_hash' => DataSubjectRequest::hashEmail('ghost@example.test'),
        ]));

        $this->assertTrue($report['blocked']);
        $this->assertSame([PrivacyRetentionGuard::REASON_UNKNOWN_USER], $this->codes($report));
        $this->assertNull($report['user_id']);
    }

    public function test_10_every_reason_is_a_translated_sentence(): void
    {
        $user = User::factory()->create();

        foreach ([
            PrivacyRetentionGuard::REASON_FINANCIAL,
            PrivacyRetentionGuard::REASON_AML,
            PrivacyRetentionGuard::REASON_OPEN_WITHDRAWAL,
            PrivacyRetentionGuard::REASON_OPEN_DISPUTE,
            PrivacyRetentionGuard::REASON_SELF_EXCLUSION,
            PrivacyRetentionGuard::REASON_LEGAL_HOLD,
            PrivacyRetentionGuard::REASON_LEGAL_HOLD_ON_REQUEST,
            PrivacyRetentionGuard::REASON_UNKNOWN_USER,
        ] as $code) {
            $key = 'privacy_requests.retention.'.$code;

            foreach (['en', 'th', 'bn'] as $locale) {
                $line = (string) trans($key, [], $locale);

                $this->assertNotSame($key, $line, "The reason [{$code}] has no {$locale} sentence.");
            }
        }

        $this->assertNotNull($user);
    }

    public function test_11_evaluating_writes_nothing_at_all(): void
    {
        [$user] = $this->userWithWallet();
        $request = $this->request($user);

        $before = [
            'users' => DB::table('users')->count(),
            'wallets' => DB::table('wallets')->count(),
            'requests' => DB::table('data_subject_requests')->count(),
            'events' => DB::table('data_subject_request_events')->count(),
        ];

        $this->guard->evaluate($request);

        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['wallets'], DB::table('wallets')->count());
        $this->assertSame($before['requests'], DB::table('data_subject_requests')->count());
        $this->assertSame($before['events'], DB::table('data_subject_request_events')->count());
    }
}
