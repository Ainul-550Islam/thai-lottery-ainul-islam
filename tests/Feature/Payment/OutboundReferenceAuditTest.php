<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Payment\OutboundReferenceAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class OutboundReferenceAuditTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_private_reference_audit_is_redacted_and_select_only(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $w['row']->update(['provider_reference' => 'PRIVATE-SYNTHETIC-REFERENCE']);
        $before = $w['row']->fresh()->getRawOriginal();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->assertSame(0, Artisan::call('payments:outbound-reference-audit', ['--domain' => 'withdrawal', '--id' => (string) $w['row']->id]));
        $text = Artisan::output();
        $out = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($out['read_only']);
        $this->assertContains('common_reference_not_indexed', $out['items'][0]['issues']);
        $this->assertFalse($out['items'][0]['automatic_resend_allowed']);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC', $text);
        foreach (['reference_hash', 'identity_key', 'account_number', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertFalse(array_any($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $sql) === 1));
        $this->assertSame($before, $w['row']->fresh()->getRawOriginal());
    }

    public function test_compatible_completed_owner_has_no_missing_index_issue(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        app(WithdrawalCompletionService::class)->complete($w['row'], options: ['provider_reference' => 'SYNTHETIC-AUDIT-COMPLETE']);
        $out = app(OutboundReferenceAuditService::class)->inspect($w['row']->fresh());
        $this->assertTrue($out['common_indexed']);
        $this->assertSame([], $out['issues']);
    }

    public function test_cursor_pages_do_not_repeat_the_oldest_rows(): void
    {
        $a = $this->approvedReferenceWithdrawal();
        $b = $this->approvedReferenceWithdrawal();
        $this->assertSame(0, Artisan::call('payments:outbound-reference-audit', ['--domain' => 'withdrawal', '--attention' => true, '--limit' => '1']));
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($a['row']->id, $first['items'][0]['obligation_id']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(0, Artisan::call('payments:outbound-reference-audit', ['--domain' => 'withdrawal', '--attention' => true, '--limit' => '1', '--after' => (string) $first['next_after']]));
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($b['row']->id, $second['items'][0]['obligation_id']);
        $this->assertFalse($second['has_more']);
    }

    public function test_ambiguous_or_unbounded_audit_arguments_are_rejected(): void
    {
        foreach ([[], ['--domain' => 'anything', '--attention' => true], ['--domain' => 'payout', '--attention' => true, '--limit' => '201'],
            ['--domain' => 'payout', '--id' => '1', '--attention' => true], ['--domain' => 'withdrawal', '--id' => '-1']] as $args) {
            $this->assertSame(1, Artisan::call('payments:outbound-reference-audit', $args));
        }
    }

    public function test_audit_can_inspect_a_soft_deleted_prize_without_adopting_it(): void
    {
        $f = $this->externalFixture();
        $f['row']->delete();
        $this->assertSame(0, Artisan::call('payments:outbound-reference-audit', ['--domain' => 'payout', '--id' => (string) $f['row']->id]));
        $out = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($out['items'][0]['soft_deleted']);
        $this->assertDatabaseCount('outbound_provider_references', 0);
    }

    public function test_attention_scan_reports_missing_original_facts_without_failing_the_entire_page(): void
    {
        $f = $this->unrequestedPayout();
        $this->assertSame(0, Artisan::call('payments:outbound-reference-audit', ['--domain' => 'payout', '--attention' => true]));
        $out = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $item = collect($out['items'])->firstWhere('obligation_id', $f['row']->id);
        $this->assertContains('invalid_original_account_or_method', $item['issues']);
        $this->assertDatabaseCount('outbound_provider_references', 0);
    }
}
