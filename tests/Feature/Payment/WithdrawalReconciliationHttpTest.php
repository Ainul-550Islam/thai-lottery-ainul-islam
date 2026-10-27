<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\UserStatus;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalReconciliation;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

final class WithdrawalReconciliationHttpTest extends PaymentTestCase
{
    private function fixture(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        $player = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['account_name' => 'SYNTHETIC ONLY', 'account_number' => 'NOT-PAYABLE']]);
        app(WithdrawalApprovalService::class)->approve($row);
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')->willReturn(GatewayWithdrawalResponse::uncertain());
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        app(WithdrawalDisbursementService::class)->disburse($row);

        return [$maker, $checker, $row->fresh(), ['withdrawal_id' => $row->id, 'internal_reference' => $row->reference_number,
            'provider' => 'bank_transfer', 'provider_reference' => 'HTTP-SYNTHETIC-'.$row->id, 'currency' => 'THB',
            'gross_amount' => '400.00', 'net_amount' => (string) $row->net_amount, 'outcome' => 'completed',
            'observed_at' => now()->toIso8601String(), 'evidence' => UploadedFile::fake()->createWithContent('proof.txt', 'HTTP SYNTHETIC PROVIDER FINAL EVIDENCE')]];
    }

    public function test_browser_upload_private_download_and_independent_decision_work(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $this->actingAs($maker)->get(route('admin.withdrawal-reconciliation.index'))->assertOk()->assertSee('Submit final provider evidence');
        $response = $this->actingAs($maker)->post(route('admin.withdrawal-reconciliation.store'), $payload);
        $case = WithdrawalReconciliation::query()->sole();
        $response->assertRedirect(route('admin.withdrawal-reconciliation.show', $case));
        $this->actingAs($checker)->get(route('admin.withdrawal-reconciliation.show', $case))->assertOk()->assertSee('Independently obtain and verify');
        $this->getJson(route('admin.withdrawal-reconciliation.destination', $case))->assertOk()
            ->assertJsonPath('payout_details.account_number', 'NOT-PAYABLE')
            ->assertHeader('Cache-Control', 'no-store, private');
        $download = $this->actingAs($checker)->get(route('admin.withdrawal-reconciliation.evidence', $case));
        $download->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('HTTP SYNTHETIC PROVIDER FINAL EVIDENCE', $download->getContent());
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->actingAs($checker)->post(route('admin.withdrawal-reconciliation.decide', $case),
            ['decision' => 'apply', 'note' => 'Independently verified original provider evidence.', 'evidence_verified' => '1'])
            ->assertRedirect(route('admin.withdrawal-reconciliation.show', $case));
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
    }

    public function test_json_resources_are_whitelisted_and_do_not_leak_claim_or_private_paths(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $response = $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertCreated();
        $id = $response->json('data.id');
        $this->assertFalse($response->json('data.automatic_resend_allowed'));
        $read = $this->actingAs($checker)->getJson(route('admin.withdrawal-reconciliation.show', $id))->assertOk();
        foreach (['snapshot_hash', 'request_key', 'payout_details', 'outbound_dispatch', 'storage/app/private', 'fingerprint', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $read->getContent());
        }
        $this->actingAs($maker)->getJson(route('admin.withdrawal-reconciliation.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_missing_independent_attestation_is_validation_failure(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $id = $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertCreated()->json('data.id');
        $this->actingAs($checker)->postJson(route('admin.withdrawal-reconciliation.decide', $id),
            ['decision' => 'apply', 'note' => 'Sufficiently long independent note.'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['evidence_verified']]]]);
        $this->assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
    }

    public function test_maker_self_review_returns_conflict_and_preserves_hold(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $id = $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertCreated()->json('data.id');
        $this->postJson(route('admin.withdrawal-reconciliation.decide', $id), ['decision' => 'apply',
            'note' => 'I claim to verify my own evidence submission.', 'evidence_verified' => true])->assertConflict()->assertJsonPath('reason', 'maker_cannot_check');
        $this->assertSame('400.00', (string) $row->wallet->fresh()->locked_balance);
    }

    public function test_player_and_auditor_cannot_use_money_mutations(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $owner = $row->user;
        $this->actingAs($owner)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertForbidden();
        $checker->syncRoles(['auditor']);
        $this->actingAs($checker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertForbidden();
        $this->assertSame(0, WithdrawalReconciliation::query()->count());
    }

    public function test_direct_permission_is_required_even_for_active_admin(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $role = Role::findByName('admin', 'web');
        $role->revokePermissionTo('request withdrawal reconciliations');
        $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertForbidden();
    }

    public function test_suspended_super_admin_cannot_bypass_controller_permission_boundary(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $maker->assignRole('super-admin');
        $maker->status = UserStatus::Suspended;
        $maker->save();
        $this->actingAs($maker)->getJson(route('admin.withdrawal-reconciliation.index'))->assertForbidden();
    }

    public function test_bad_money_and_pending_outcome_are_not_coerced_to_financial_proof(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), array_replace($payload, ['outcome' => 'pending']))->assertUnprocessable();
        $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), array_replace($payload, ['gross_amount' => '4e2']))->assertConflict();
        $this->assertSame(0, WithdrawalReconciliation::query()->count());
    }

    public function test_proof_file_is_mandatory_and_active_content_is_refused(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $bad = $payload;
        unset($bad['evidence']);
        $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $bad)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['evidence']]]]);
        $bad['evidence'] = UploadedFile::fake()->createWithContent('proof.html', '<html><script>alert(1)</script></html>');
        $this->postJson(route('admin.withdrawal-reconciliation.store'), $bad)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['evidence']]]]);
    }

    public function test_unauthenticated_evidence_download_is_not_public(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $this->getJson(route('admin.withdrawal-reconciliation.evidence', 1))->assertUnauthorized();
    }

    public function test_auditor_can_read_only_when_explicitly_granted_and_never_apply(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $id = $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertCreated()->json('data.id');
        $checker->syncRoles(['auditor']);
        $this->actingAs($checker)->getJson(route('admin.withdrawal-reconciliation.show', $id))->assertForbidden();
        $checker->givePermissionTo('view withdrawal reconciliations');
        $this->actingAs($checker)->getJson(route('admin.withdrawal-reconciliation.show', $id))->assertOk();
        $this->get(route('admin.withdrawal-reconciliation.evidence', $id))->assertOk();
        $this->postJson(route('admin.withdrawal-reconciliation.decide', $id), ['decision' => 'apply',
            'note' => 'Read-only auditors must never operate money.', 'evidence_verified' => true])->assertForbidden();
    }

    public function test_request_permission_without_view_authority_cannot_leak_case_details(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        Role::findByName('admin', 'web')->revokePermissionTo('view withdrawal reconciliations');
        $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertForbidden();
    }

    public function test_private_destination_route_does_not_bypass_independence_or_auditor_limits(): void
    {
        [$maker, $checker, $row, $payload] = $this->fixture();
        $id = $this->actingAs($maker)->postJson(route('admin.withdrawal-reconciliation.store'), $payload)->assertCreated()->json('data.id');
        $this->getJson(route('admin.withdrawal-reconciliation.destination', $id))->assertConflict();
        $checker->syncRoles(['auditor']);
        $checker->givePermissionTo('view withdrawal reconciliations');
        $this->actingAs($checker)->getJson(route('admin.withdrawal-reconciliation.destination', $id))->assertForbidden();
    }
}
