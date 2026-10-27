<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\FinancialException;
use App\Services\Payment\OutboundProviderReferenceService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class OutboundReferenceRegistryTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_identity_is_provider_account_and_exact_reference_scoped_with_manual_alias(): void
    {
        $s = app(OutboundProviderReferenceService::class);
        $a = $s->identity('bank_transfer', 'account-a', 'CaseSensitive');
        $this->assertSame($a, $s->identity('manual', 'account-a', 'CaseSensitive'));
        $this->assertNotSame($a, $s->identity('bank_transfer', 'account-b', 'CaseSensitive'));
        $this->assertNotSame($a, $s->identity('stripe', 'account-a', 'CaseSensitive'));
        $this->assertNotSame($a, $s->identity('bank_transfer', 'account-a', 'casesensitive'));
    }

    public static function invalidIdentity(): array
    {
        return [['bank_transfer', '', 'ref'], ['bank_transfer', 'bad scope', 'ref'], ['missing-provider', 'scope', 'ref'],
            ['bank_transfer', 'scope', ''], ['bank_transfer', 'scope', ' padded '], ['bank_transfer', 'scope', "line\nbreak"], ['bank_transfer', 'scope', str_repeat('x', 256)]];
    }

    #[DataProvider('invalidIdentity')]
    public function test_invalid_namespace_or_reference_fails_closed(string $provider, string $scope, string $ref): void
    {
        $this->expectException(FinancialException::class);
        app(OutboundProviderReferenceService::class)->identity($provider, $scope, $ref);
    }

    public function test_bare_common_binding_without_verified_local_binding_is_refused(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(OutboundProviderReferenceService::class)->bind($w['row'], 'bank_transfer', 'legacy-primary', 'SYNTHETIC-BARE', null));
    }

    public function test_binding_requires_transaction(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $this->expectException(FinancialException::class);
        app(OutboundProviderReferenceService::class)->bind($w['row'], 'bank_transfer', 'legacy-primary', 'SYNTHETIC-NO-TRANSACTION', null);
    }

    public function test_reference_cannot_be_replaced_for_same_owner(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $s = app(WithdrawalProviderReferenceService::class);
        DB::transaction(fn () => $s->bind($w['row'], 'bank_transfer', 'SYNTHETIC-FIRST'));
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => $s->bind($w['row'], 'bank_transfer', 'SYNTHETIC-SECOND'));
    }

    public function test_schema_rejects_an_ownerless_binding(): void
    {
        $this->expectException(QueryException::class);
        DB::table('outbound_provider_references')->insert(['identity_key' => str_repeat('a', 64), 'provider' => 'bank_transfer',
            'account_scope' => 'legacy-primary', 'reference_hash' => str_repeat('b', 64), 'created_at' => now()]);
    }

    public function test_schema_rejects_a_two_domain_owner(): void
    {
        $f = $this->externalFixture();
        $w = $this->approvedReferenceWithdrawal();
        $this->expectException(QueryException::class);
        DB::table('outbound_provider_references')->insert(['identity_key' => str_repeat('a', 64), 'provider' => 'bank_transfer',
            'withdrawal_id' => $w['row']->id, 'payout_id' => $f['row']->id,
            'account_scope' => 'legacy-primary', 'reference_hash' => str_repeat('b', 64), 'created_at' => now()]);
    }

    public function test_populated_common_registry_cannot_be_rolled_back(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'bank_transfer', 'SYNTHETIC-RETAIN'));
        $migration = require base_path('database/migrations/2026_10_15_000500_create_outbound_provider_references_table.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_common_registry_never_stores_plaintext_provider_reference(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'bank_transfer', 'PRIVATE-SYNTHETIC-REFERENCE'));
        $text = json_encode(DB::table('outbound_provider_references')->first(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC', $text);
        $this->assertSame(hash('sha256', 'PRIVATE-SYNTHETIC-REFERENCE'), DB::table('outbound_provider_references')->value('reference_hash'));
    }
}
