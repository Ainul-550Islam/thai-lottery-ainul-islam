<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesPlayerPrizeClaim;

final class PlayerPrizeClaimHttpTest extends PaymentTestCase
{
    use CreatesPlayerPrizeClaim;

    private function decision(string $value = 'approved'): array
    {
        return ['decision' => $value, 'original_facts_verified' => true, 'note' => 'Synthetic independent original owned-winning-bet review.'];
    }

    public function test_staff_review_console_and_verdict_are_usable_but_do_not_move_money(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->actingAs($f['reviewer'])->get(route('admin.player-prize-claims.index'))->assertOk()->assertSee('Player prize claim review');
        $this->get(route('admin.player-prize-claims.show', $f['row']))->assertOk()->assertSee('Original bet');
        $response = $this->postJson(route('admin.player-prize-claims.decide', $f['row']), $this->decision())
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['facts_hash', 'approved_by', 'reason_hash', 'beneficiary'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->postJson(route('admin.player-prize-claims.decide', $f['row']), $this->decision('rejected'))->assertConflict();
    }

    public function test_invalid_sensitive_input_is_not_flashed_and_client_cannot_override_original_facts(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->actingAs($f['reviewer']);
        $url = route('admin.player-prize-claims.decide', $f['row']);
        $this->from(route('admin.player-prize-claims.show', $f['row']))->post($url, ['decision' => 'approved', 'note' => 'PRIVATE INVALID'])
            ->assertRedirect()->assertSessionMissing('_old_input');
        $this->postJson($url, $this->decision() + ['amount' => '999.00'])->assertUnprocessable();
        $payload = $this->decision();
        $payload['original_facts_verified'] = false;
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame('under_review', $f['row']->fresh()->metadata['claim']['status']);
    }

    public function test_player_and_revoked_staff_cannot_review(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->actingAs($f['owner']['user'])->postJson(route('admin.player-prize-claims.decide', $f['row']), $this->decision())->assertForbidden();
        $f['reviewer']->syncRoles([]);
        $this->actingAs($f['reviewer'])->getJson(route('admin.player-prize-claims.index'))->assertForbidden();
        $this->assertSame('under_review', $f['row']->fresh()->metadata['claim']['status']);
    }

    public function test_destination_only_records_are_not_player_claims_and_foreign_direct_pointer_does_not_leak(): void
    {
        $f = $this->playerClaimFixture();
        $foreign = $this->playerClaimFixture();
        $this->submitPlayerClaim($foreign);
        Sanctum::actingAs($f['owner']['user']);
        $this->getJson(route('api.v1.prize-claims.index'))->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson(route('api.v1.prize-claims.show', $f['bet']->id))->assertNotFound();
        $f['bet']->payout_id = $foreign['row']->id;
        $f['bet']->save();
        $response = $this->getJson(route('api.v1.prize-claims.show', $f['bet']->id))->assertNotFound();
        $this->assertStringNotContainsString($foreign['row']->reference_number, $response->getContent());
    }

    public function test_player_claim_http_replay_and_expired_window_projection_are_consistent(): void
    {
        $f = $this->playerClaimFixture();
        Sanctum::actingAs($f['owner']['user']);
        $this->postJson(route('api.v1.prize-claims.store'), ['bet_id' => $f['bet']->id])->assertCreated()
            ->assertJsonPath('data.claim.status', 'under_review')->assertJsonPath('data.claim.window_open', true);
        $this->postJson(route('api.v1.prize-claims.store'), ['bet_id' => $f['bet']->id])->assertOk()->assertJsonPath('data.claim.no_op', true);
        $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        $this->getJson(route('api.v1.prize-claims.show', $f['bet']->id))->assertOk()->assertJsonPath('data.claim.window_open', false);
        $this->getJson(route('api.v1.prize-claims.index'))->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_financial_http_intake_reports_controlled_refusal_while_player_claim_is_under_review(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->actingAs($f['maker'])->postJson(route('admin.payout-approvals.store'), ['payout_id' => $f['row']->id,
            'method' => 'wallet', 'note' => 'Synthetic attempt to bypass player assertion review.'])->assertConflict();
        $this->assertArrayNotHasKey('approval', $f['row']->fresh()->metadata);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_arbitrary_paper_ticket_secrets_are_not_accepted_or_flashed_as_verified_possession(): void
    {
        $f = $this->playerClaimFixture();
        Sanctum::actingAs($f['owner']['user']);
        $payload = ['bet_id' => $f['bet']->id, 'ticket_number' => 'UNVERIFIED PAPER', 'verification_code' => 'PRIVATE SECRET'];
        $this->postJson(route('api.v1.prize-claims.store'), $payload)->assertUnprocessable();
        $this->post(route('api.v1.prize-claims.store'), $payload)->assertRedirect()->assertSessionMissing('_old_input');
        $this->assertArrayNotHasKey('status', $f['row']->fresh()->metadata['claim']);
    }
}
