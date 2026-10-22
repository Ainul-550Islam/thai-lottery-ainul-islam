<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\Models\KycDocument;
use App\Models\ResponsibleGamingLimit;
use App\Services\Wallet\Conversion\ConversionMemberEligibility;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionMemberEligibilityFixture;

final class ConversionMemberEligibilityTest extends ConversionTestCase
{
    use ConversionMemberEligibilityFixture;

    public static function blockedProfiles(): array
    {
        return [['suspended', 'account_suspended'], ['canonical-inserted', 'self_exclusion_active'],
            ['legacy-inserted', 'self_exclusion_active'], ['cooloff-inserted', 'self_exclusion_active'],
            ['kyc-revoked', 'kyc_not_verified'], ['kyc-deleted', 'kyc_not_verified'],
            ['kyc-expired', 'kyc_not_verified'], ['phone-revoked', 'kyc_not_verified']];
    }

    #[DataProvider('blockedProfiles')]
    public function test_current_ineligibility_blocks_both_quote_and_new_money_without_consuming_original_quote(string $profile, string $code): void
    {
        config(['conversions.gates.require_verified_mobile' => true]);
        $f = $this->memberFixture();
        $request = $this->slotRequest($f);
        $quote = $this->service()->quote($request);
        $before = $this->slotPaper();
        $this->changeMember($f, $profile);
        $this->slotRefuses(fn () => $this->service()->quote($request), $code);
        $this->slotRefuses(fn () => $this->service()->convert($request->withQuoteReference($quote->reference)), $code);
        $this->assertSame($before, $this->slotPaper());
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public static function inactiveStatuses(): array
    {
        return [['inactive'], ['banned'], ['pending_verification'], ['malformed_status']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_raw_account_status_must_be_exactly_active_even_with_relaxed_optional_gates(string $status): void
    {
        $f = $this->memberFixture();
        config(['conversions.gates.require_kyc' => false, 'conversions.gates.block_if_self_excluded' => false]);
        DB::table('users')->where('id', $f['user']->id)->update(['status' => $status]);
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'account_suspended');
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public static function expiryProfiles(): array
    {
        return [['future', true], ['equal', false], ['exact-second', false], ['past', false], ['missing', true], ['malformed', false], ['overflowed', false]];
    }

    #[DataProvider('expiryProfiles')]
    public function test_only_a_live_or_non_expiring_verified_document_can_grant_new_conversion(string $profile, bool $allowed): void
    {
        Carbon::setTestNow(Carbon::parse('2031-06-07 12:00:00.500000', config('app.timezone')));
        try {
            if ($profile === 'exact-second') {
                Carbon::setTestNow(now()->startOfSecond());
            }
            $f = $this->memberFixture();
            $raw = match ($profile) {
                'future' => now()->addSecond()->format('Y-m-d H:i:s'),
                'equal', 'exact-second' => now()->format('Y-m-d H:i:s'),
                'past' => now()->subSecond()->format('Y-m-d H:i:s'),
                'malformed' => 'not-a-date',
                'overflowed' => '2031-02-30 12:00:00',
                default => null,
            };
            try {
                DB::table('kyc_documents')->where('user_id', $f['user']->id)->update(['expires_at' => $raw]);
            } catch (QueryException $e) {
                // Strict native SQL rejects impossible dates at the storage boundary.
                $this->assertContains($profile, ['malformed', 'overflowed']);
                $this->assertContains((int) ($e->errorInfo[1] ?? 0), [1292, 1525]);
                $this->assertSame(0, $this->memberFinancialRows());

                return;
            }
            $before = $this->slotPaper();
            if ($allowed) {
                $request = $this->slotRequest($f);
                $quote = $this->service()->quote($request);
                $result = $this->service()->convert($request->withQuoteReference($quote->reference));
                $this->assertFalse($result->replayed);
                $this->assertSame(1, $this->memberFinancialRows());
                $this->assertTrue($this->ledgerIsBalanced()['balanced']);
            } else {
                $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'kyc_not_verified');
                $this->assertSame($before, $this->slotPaper());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function nonVerifiedStates(): array
    {
        return [['unverified'], ['pending'], ['under_review'], ['rejected'], ['expired'], ['malformed_status']];
    }

    #[DataProvider('nonVerifiedStates')]
    public function test_no_non_verified_document_status_grants_identity(string $status): void
    {
        $f = $this->memberFixture();
        DB::table('kyc_documents')->where('user_id', $f['user']->id)->update(['status' => $status]);
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'kyc_not_verified');
    }

    public function test_healthy_document_beyond_first_page_is_authoritative_and_soft_deleted_verified_evidence_is_not(): void
    {
        $f = $this->memberFixture();
        $original = KycDocument::query()->where('user_id', $f['user']->id)->firstOrFail();
        $template = $original->getAttributes();
        unset($template['id']);
        $original->delete();
        $rows = [];
        for ($i = 0; $i < 251; $i++) {
            $rows[] = array_merge($template, ['document_number' => 'PENDING-'.$i, 'status' => 'pending']);
        }
        DB::table('kyc_documents')->insert($rows);
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'kyc_not_verified');
        DB::table('kyc_documents')->insert(array_merge($template, ['document_number' => 'LIVE-LAST',
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s')]));
        $queryRows = [];
        DB::listen(function ($event) use (&$queryRows): void {
            if (str_contains($event->sql, 'kyc_documents') && str_starts_with(strtolower($event->sql), 'select')) {
                $queryRows[] = strtolower($event->sql);
            }
        });
        $this->service()->quote($this->slotRequest($f));
        $this->assertCount(2, $queryRows);
        foreach ($queryRows as $sql) {
            $this->assertStringContainsString('limit 250', $sql);
            $this->assertStringNotContainsString('select *', $sql);
        }
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public function test_a_stale_principal_and_loaded_relations_do_not_grant_member_eligibility(): void
    {
        $f = $this->memberFixture();
        $f['user']->load('kycDocuments', 'responsibleGamingLimit');
        $this->assertSame('verified', $f['user']->kycStatus()->value);
        $this->changeMember($f, 'kyc-revoked');
        $this->assertSame('verified', $f['user']->kycDocuments->first()->status->value);
        $this->slotRefuses(fn () => app(ConversionMemberEligibility::class)->assertAllowed($f['user']->id), 'kyc_not_verified');
        $decision = app(ConversionPolicyContract::class)->evaluate($this->slotRequest($f));
        $this->assertFalse($decision->allowed);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public function test_soft_deleted_safety_row_cannot_cancel_a_live_self_exclusion_or_cooling_off(): void
    {
        $f = $this->memberFixture();
        $row = ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id, 'cool_off_until' => now()->addDay()]);
        $row->delete();
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'self_exclusion_active');
        $row->forceFill(['cool_off_until' => now()->subSecond()])->save();
        $quote = $this->service()->quote($this->slotRequest($f));
        $this->assertNotEmpty($quote->reference);
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public function test_malformed_legacy_safety_timestamp_never_silently_clears_protection(): void
    {
        $f = $this->memberFixture();
        try {
            ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id]);
            DB::table('responsible_gaming_limits')->where('user_id', $f['user']->id)->update(['self_excluded_until' => 'unknown']);
        } catch (QueryException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [1292, 1525]);

            return;
        }
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'self_exclusion_active');
    }

    public static function transactionProfiles(): array
    {
        return [['none'], ['raw-pdo-only'], ['stale-framework-counter']];
    }

    #[DataProvider('transactionProfiles')]
    public function test_explicit_execution_requires_both_real_and_framework_transaction(string $profile): void
    {
        $f = $this->memberFixture();
        $before = $this->slotPaper();
        if ($profile === 'raw-pdo-only') {
            DB::connection()->getPdo()->beginTransaction();
        } elseif ($profile === 'stale-framework-counter') {
            DB::beginTransaction();
            DB::connection()->getPdo()->commit();
        }
        try {
            $this->slotRefuses(fn () => app(ConversionMemberEligibility::class)->assertAllowedForExecution($f['user']->id), 'transaction_required');
            if ($profile === 'stale-framework-counter') {
                $this->slotRefuses(fn () => app(ConversionMemberEligibility::class)->assertAllowed($f['user']->id), 'transaction_required');
            }
        } finally {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->rollBack();
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($before, $this->slotPaper());
    }

    public function test_optional_configuration_relaxes_only_its_named_gate_and_never_account_status(): void
    {
        $f = $this->memberFixture();
        $this->changeMember($f, 'exact-replay');
        config(['conversions.gates.require_kyc' => false, 'conversions.gates.block_if_self_excluded' => false,
            'conversions.gates.require_verified_mobile' => false]);
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'account_suspended');
        $this->changeMember($f, 'positive-restored');
        $request = $this->slotRequest($f);
        $quote = $this->service()->quote($request);
        $result = $this->service()->convert($request->withQuoteReference($quote->reference));
        $this->assertFalse($result->replayed);
        $this->assertSame(1, $this->memberFinancialRows());
    }

    public function test_advisory_is_nonlocking_and_execution_locks_current_member_facts_in_owner_order(): void
    {
        $f = $this->memberFixture();
        $before = $this->slotPaper();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (str_starts_with(strtolower($event->sql), 'select')) {
                $queries[] = strtolower($event->sql);
            }
        });
        app(ConversionMemberEligibility::class)->assertAllowed($f['user']->id);
        $this->assertCount(4, $queries);
        $this->assertSame(0, DB::transactionLevel());
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('for update', $sql);
        }
        $queries = [];
        DB::transaction(fn () => app(ConversionMemberEligibility::class)->assertAllowedForExecution($f['user']->id));
        $this->assertCount(4, $queries);
        foreach (['users', 'self_exclusions', 'responsible_gaming_limits', 'kyc_documents'] as $i => $table) {
            $this->assertStringContainsString($table, $queries[$i]);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $this->assertStringContainsString('for update', $queries[$i]);
            }
        }
        $this->assertSame($before, $this->slotPaper());
    }

    public function test_a_soft_deleted_or_missing_member_cannot_gain_new_authority(): void
    {
        $f = $this->memberFixture();
        $f['user']->delete();
        $this->slotRefuses(fn () => app(ConversionMemberEligibility::class)->assertAllowed($f['user']->id), 'account_suspended');
        $this->slotRefuses(fn () => app(ConversionMemberEligibility::class)->assertAllowed(0), 'account_suspended');
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public function test_null_and_lapsed_legacy_windows_do_not_block_an_eligible_member(): void
    {
        $f = $this->memberFixture();
        ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id,
            'self_excluded_until' => now()->subSecond(), 'cool_off_until' => null]);
        $this->assertNotEmpty($this->service()->quote($this->slotRequest($f))->reference);
        $this->assertSame(0, $this->memberFinancialRows());
    }

    public function test_exact_original_replay_remains_observational_after_all_member_gates_are_revoked(): void
    {
        config(['conversions.gates.require_verified_mobile' => true]);
        $f = $this->memberFixture();
        $request = $this->slotRequest($f);
        $quote = $this->service()->quote($request);
        $request = $request->withQuoteReference($quote->reference);
        $original = $this->service()->convert($request);
        $before = $this->slotPaper();
        $this->changeMember($f, 'exact-replay');
        $replay = $this->service()->convert($request);
        $this->assertTrue($replay->replayed);
        $this->assertSame($original->reference, $replay->reference);
        $this->assertSame($before, $this->slotPaper());
        $this->assertSame(1, $this->memberFinancialRows());
    }
}
