<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Services\Payment\PayoutTransferService;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesExternalPayout;

final class PayoutTransferHttpTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_private_operator_console_and_decision_execute_real_domain_accounting(): void
    {
        $f = $this->externalFixture();
        $this->actingAs($f['reviewer']);
        $this->postJson(route('admin.payout-transfers.open', $f['row']))->assertOk()->assertJsonPath('data.lane_status', 'open');
        $this->get(route('admin.payout-transfers.show', $f['row']))->assertOk()->assertSee('Independent transfer review');
        $this->postJson(route('admin.payout-transfers.decide', $f['row']), ['outcome' => 'completed',
            'provider_reference' => 'SYNTHETIC-HTTP-RESULT', 'note' => 'Independently reviewed synthetic provider statement.', 'evidence_verified' => true, 'evidence_id' => $f['evidence_id']])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_list_and_show_are_redacted_and_private(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->actingAs($f['reviewer']);
        foreach (['index', 'show'] as $action) {
            $r = $this->getJson(route('admin.payout-transfers.'.$action, $action === 'show' ? $f['row'] : []))->assertOk();
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            foreach (['NOT-PAYABLE', 'beneficiary', 'facts_hash', 'idempotency_key', 'SYNTHETIC PERSON'] as $private) {
                $this->assertStringNotContainsString($private, $r->getContent());
            }
        }
    }

    public function test_destination_is_separate_authorized_and_audited(): void
    {
        $f = $this->externalFixture();
        $this->actingAs($f['reviewer'])->getJson(route('admin.payout-transfers.destination', $f['row']))->assertOk()
            ->assertJsonPath('destination.account_number', 'NOT-PAYABLE');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $f['row']->id,
            'description' => 'Authorized operator inspected original external prize destination.']);
    }

    public function test_player_and_guest_cannot_use_operator_write_routes(): void
    {
        $f = $this->externalFixture();
        $url = route('admin.payout-transfers.open', $f['row']);
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($f['owner']['user'])->postJson($url)->assertForbidden();
    }

    public function test_unverified_or_short_review_does_not_change_money(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->actingAs($f['reviewer'])->postJson(route('admin.payout-transfers.decide', $f['row']), [
            'outcome' => 'completed', 'provider_reference' => 'SYNTHETIC', 'note' => 'short', 'evidence_verified' => false])->assertUnprocessable();
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_gate_super_admin_bypass_does_not_replace_explicit_duty(): void
    {
        $f = $this->externalFixture();
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        Role::findByName('super-admin')->revokePermissionTo('review payout transfers');
        $this->actingAs($user)->postJson(route('admin.payout-transfers.open', $f['row']))->assertForbidden();
    }
}
