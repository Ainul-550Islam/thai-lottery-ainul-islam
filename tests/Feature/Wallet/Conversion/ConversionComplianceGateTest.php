<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Models\User;

/**
 * Conversions move money between a member's own wallets and can fund betting, so
 * every money-moving action sits behind the same 18+ attestation and current
 * legal-consent gates as deposits and bets. These tests prove the gates refuse
 * before any wallet is touched, and that a fully qualified member is let through.
 */
final class ConversionComplianceGateTest extends ConversionTestCase
{
    public function test_api_quote_refuses_a_member_without_an_age_attestation(): void
    {
        $user = User::factory()->create(['date_of_birth' => '1990-02-03']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/conversions/quote', [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'age_attestation_required');
    }

    public function test_api_execute_refuses_a_member_without_current_legal_consent(): void
    {
        $user = User::factory()->create(['date_of_birth' => '1990-02-03']);
        \App\Models\AgeVerification::query()->create([
            'user_id' => $user->getKey(),
            'status' => 'eligible',
            'minimum_age' => 18,
            'method' => 'self_declaration',
            'attested_at' => now(),
            'source' => 'test',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/conversions', [])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'current_legal_acknowledgement_required');
    }

    public function test_api_reverse_refuses_a_member_without_an_age_attestation(): void
    {
        $user = User::factory()->create(['date_of_birth' => '1990-02-03']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wallet/conversions/CNVTEST000001/reverse', [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'age_attestation_required');
    }

    public function test_web_execute_redirects_an_unattested_member_to_age_verification(): void
    {
        $user = User::factory()->create(['date_of_birth' => '1990-02-03']);

        $this->actingAs($user)
            ->post('/wallet/convert', [])
            ->assertRedirect(route('account.age-verification.form'));
    }

    public function test_web_execute_redirects_an_adult_without_consent_to_the_consent_form(): void
    {
        $user = User::factory()->create(['date_of_birth' => '1990-02-03']);
        \App\Models\AgeVerification::query()->create([
            'user_id' => $user->getKey(),
            'status' => 'eligible',
            'minimum_age' => 18,
            'method' => 'self_declaration',
            'attested_at' => now(),
            'source' => 'test',
        ]);

        $this->actingAs($user)
            ->post('/wallet/convert', [])
            ->assertRedirect(route('legal.consent.form'));
    }

    public function test_the_read_only_conversion_page_stays_open_to_any_active_member(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/wallet/convert')
            ->assertOk();
    }
}
