<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Finance\PayoutApprovalIntakeService;
use Tests\Support\CreatesExternalPayout;

final class PayoutApprovalIntakeHttpTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    private function payload(array $f): array
    {
        return ['payout_id' => $f['row']->id, 'method' => 'bank_transfer', 'beneficiary' => [
            'bank_name' => 'SYNTHETIC BANK', 'account_name' => 'SYNTHETIC PERSON', 'account_number' => 'PRIVATE-NOT-PAYABLE'],
            'note' => 'Original payout destination supplied for independent review.'];
    }

    public function test_maker_checker_console_derives_financial_identity_and_does_not_pay(): void
    {
        $f = $this->unrequestedPayout();
        $this->actingAs($f['maker'])->get(route('admin.payout-approvals.show', $f['row']))->assertOk()->assertSee('Maker–checker payout workflow');
        $this->postJson(route('admin.payout-approvals.store'), $this->payload($f))->assertOk()->assertJsonPath('data.approval_status', 'pending');
        $this->actingAs($f['checker'])->getJson(route('admin.payout-approvals.destination', $f['row']))->assertOk()->assertJsonPath('destination.account_number', 'PRIVATE-NOT-PAYABLE');
        $this->postJson(route('admin.payout-approvals.decide', $f['row']), ['decision' => 'approved',
            'original_facts_verified' => true, 'note' => 'Independent review of original immutable payout facts.'])->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('pending', $f['row']->fresh()->status->value);
    }

    public function test_maker_cannot_approve_own_http_request(): void
    {
        $f = $this->unrequestedPayout();
        $this->actingAs($f['maker'])->postJson(route('admin.payout-approvals.store'), $this->payload($f))->assertOk();
        $this->postJson(route('admin.payout-approvals.decide', $f['row']), ['decision' => 'approved', 'original_facts_verified' => true,
            'note' => 'Maker attempts to approve the original own request.'])->assertConflict();
        $this->assertSame('pending', $f['row']->fresh()->metadata['approval']['status']);
    }

    public function test_caller_cannot_override_amount_currency_or_owner(): void
    {
        $f = $this->unrequestedPayout();
        $this->actingAs($f['maker']);
        foreach (['amount' => '999.00', 'currency' => 'USD', 'beneficiary_user_id' => $f['reviewer']->id] as $key => $value) {
            $this->postJson(route('admin.payout-approvals.store'), $this->payload($f) + [$key => $value])->assertUnprocessable();
        }
        $this->assertArrayNotHasKey('approval', $f['row']->fresh()->metadata);
    }

    public function test_sensitive_failed_intake_is_not_flashed_to_session(): void
    {
        $f = $this->unrequestedPayout();
        $data = $this->payload($f);
        $data['note'] = 'bad';
        $this->actingAs($f['maker'])->from(route('admin.payout-approvals.show', $f['row']))
            ->post(route('admin.payout-approvals.store'), $data)->assertRedirect()->assertSessionMissing('_old_input');
    }

    public function test_redacted_projection_does_not_echo_private_original_details(): void
    {
        $f = $this->unrequestedPayout();
        $this->actingAs($f['maker'])->postJson(route('admin.payout-approvals.store'), $this->payload($f))->assertOk();
        $response = $this->getJson(route('admin.payout-approvals.show', $f['row']))->assertOk();
        foreach (['PRIVATE-NOT-PAYABLE', 'SYNTHETIC PERSON', 'facts_hash', 'beneficiary', 'request_key'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_unverified_checker_verdict_and_player_requests_are_refused(): void
    {
        $f = $this->unrequestedPayout();
        $this->actingAs($f['maker'])->postJson(route('admin.payout-approvals.store'), $this->payload($f))->assertOk();
        $this->actingAs($f['checker'])->postJson(route('admin.payout-approvals.decide', $f['row']), [
            'decision' => 'approved', 'original_facts_verified' => false, 'note' => 'No independent review was actually performed.'])->assertUnprocessable();
        $this->actingAs($f['owner']['user'])->postJson(route('admin.payout-approvals.store'), $this->payload($f))->assertForbidden();
    }

    public function test_rejection_does_not_mutate_money_or_make_a_payment(): void
    {
        $f = $this->unrequestedPayout();
        $service = app(PayoutApprovalIntakeService::class);
        $service->request($f['maker'], $f['row']->id, 'wallet', null, 'Synthetic wallet request for independent review.');
        $row = $service->decide($f['checker'], $f['row']->id, 'rejected', true, 'Independent rejection of insufficient original facts.');
        $this->assertSame('rejected', $row->metadata['approval']['status']);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
    }
}
