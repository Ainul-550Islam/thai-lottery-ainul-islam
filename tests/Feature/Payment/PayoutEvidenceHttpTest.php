<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\PayoutTransferService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesExternalPayout;

final class PayoutEvidenceHttpTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_upload_returns_only_redacted_provenance_and_never_finalizes(): void
    {
        $f = $this->externalFixture();
        $out = $this->actingAs($f['maker'])->postJson(route('admin.payout-transfers.evidence.store', $f['row']->id), [
            'evidence' => UploadedFile::fake()->createWithContent('PRIVATE-NAME.txt', 'PRIVATE DOCUMENT BODY'), 'note' => 'Independent original archival rationale.'])->assertCreated();
        $out->assertJsonPath('data.provider_authenticity_verified', false)->assertJsonPath('data.legal_hold', true);
        foreach (['PRIVATE-NAME', 'PRIVATE DOCUMENT', 'blob_key', 'sha256', 'facts_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $out->getContent());
        }
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('payout_transfer_evidence', 2);
        $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
    }

    public function test_download_is_inert_attachment_and_read_is_audited(): void
    {
        $f = $this->externalFixture();
        $before = DB::table('payout_transfer_evidence_reads')->count();
        $out = $this->actingAs($f['reviewer'])->get(route('admin.payout-transfers.evidence.download', [$f['row']->id, $f['evidence_id']]))->assertOk();
        $this->assertStringContainsString('SYNTHETIC FINAL-RESULT', $out->getContent());
        $this->assertSame('application/octet-stream', $out->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $out->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $out->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $out->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
        $this->assertSame($before + 1, DB::table('payout_transfer_evidence_reads')->count());
    }

    public function test_cross_payout_download_is_refused_without_read_audit(): void
    {
        $a = $this->externalFixture();
        $b = $this->externalFixture();
        $before = DB::table('payout_transfer_evidence_reads')->count();
        $this->actingAs($a['reviewer'])->getJson(route('admin.payout-transfers.evidence.download', [$b['row']->id, $a['evidence_id']]))->assertConflict();
        $this->assertSame($before, DB::table('payout_transfer_evidence_reads')->count());
    }

    public function test_guest_and_beneficiary_cannot_download(): void
    {
        $f = $this->externalFixture();
        $url = route('admin.payout-transfers.evidence.download', [$f['row']->id, $f['evidence_id']]);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($f['owner']['user'])->getJson($url)->assertForbidden();
    }

    public function test_super_admin_gate_bypass_does_not_replace_file_permission(): void
    {
        $f = $this->externalFixture();
        $f['reviewer']->syncRoles(['super-admin']);
        Role::findByName('super-admin')->revokePermissionTo('read payout evidence');
        $this->actingAs($f['reviewer'])->getJson(route('admin.payout-transfers.evidence.download', [$f['row']->id, $f['evidence_id']]))->assertForbidden();
    }

    public function test_invalid_upload_does_not_flash_sensitive_input(): void
    {
        $f = $this->externalFixture();
        $this->actingAs($f['maker'])->from(route('admin.payout-transfers.show', $f['row']))
            ->post(route('admin.payout-transfers.evidence.store', $f['row']->id), ['url' => 'https://private.invalid/secret', 'note' => 'PRIVATE-SHORT'])
            ->assertRedirect()->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('payout_transfer_evidence', 1);
    }

    public function test_http_finalization_requires_explicit_selected_archive(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->actingAs($f['reviewer'])->postJson(route('admin.payout-transfers.decide', $f['row']), [
            'outcome' => 'completed', 'provider_reference' => 'SYNTHETIC-MISSING-SELECTOR', 'note' => 'Explicit inspected archive must be selected.', 'evidence_verified' => true])->assertUnprocessable();
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_disallowed_active_content_is_rejected(): void
    {
        $f = $this->externalFixture();
        $this->actingAs($f['maker'])->postJson(route('admin.payout-transfers.evidence.store', $f['row']->id), [
            'evidence' => UploadedFile::fake()->createWithContent('active.html', '<!doctype html><html><script>alert(1)</script></html>'),
            'note' => 'Active content must not be archived.'])->assertConflict();
        $this->assertDatabaseCount('payout_transfer_evidence', 1);
    }
}
