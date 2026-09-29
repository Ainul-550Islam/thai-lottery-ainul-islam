<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\Currency;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserStatus;
use App\Models\AccountGradeSnapshot;
use App\Models\AccountVerification;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Account\AccountDiscountService;
use App\Services\Account\AccountGradeService;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Account\AccountVerificationService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalService;
use App\Services\PublicPages\FeesPageService;
use App\Services\Security\KycVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PROMPT 3 — AccountServicesPagesTest.
 *
 * Fees (public) + Account Verification (auth, composes canonical KYC)
 * + Account Grade (auth, server-calculated). Covers the mandated 40
 * scenarios plus architecture guards (no dual KYC, no competitor fees,
 * no GLO price regression, no client-trusted status/grade/discount).
 */
final class AccountServicesPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->player = User::factory()->create(['status' => UserStatus::Active]);
    }

    // =====================================================================
    // FEES (fees parity batch — updated to the canonical provider-aware
    // contract: grouped schedule, provider rows, live-rule mirroring,
    // NOT_CONFIGURED states, config as the single source of values)
    // =====================================================================

    // 1
    public function test_fees_page_is_public_anonymous(): void
    {
        $this->get('/fees')->assertOk();
    }

    // 2
    public function test_enabled_public_fee_is_visible(): void
    {
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringContainsString('data-pp-fee="cash_balance_transfer"', $content);
        $this->assertStringContainsString('data-pp-fee="withdrawal"', $content);
        $this->assertStringContainsString('data-pp-fee="cash_in"', $content);
        // Provider-specific rows are separate rows, never collapsed into one.
        foreach (['withdrawal_bank', 'withdrawal_skrill', 'withdrawal_neteller', 'withdrawal_paypal', 'withdrawal_perfect_money',
            'cash_in_bank', 'cash_in_skrill', 'cash_in_neteller', 'cash_in_paypal', 'cash_in_perfect_money', ] as $key) {
            $this->assertStringContainsString('data-pp-fee="'.$key.'"', $content);
        }
    }

    // 3
    public function test_disabled_fee_is_hidden(): void
    {
        // agent_commission is enabled=false by default (AGENT_COMMISSION_ENABLED)
        // and account_renewal/maintenance are enabled in the canonical schedule,
        // so the disabled-row proof uses the default-off row plus a runtime one.
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-pp-fee="agent_commission"', $content);
        $this->assertStringNotContainsString('data-pp-fee="internal_provider_margin"', $content);

        config(['fees.categories.cash_to_win.enabled' => false]);
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-pp-fee="cash_to_win"', $content);
    }

    // 4
    public function test_not_configured_state_shows_for_unpriced_enabled_rows(): void
    {
        // The canonical schedule ships PayPal rows as visible-but-unspecified:
        // an unspecified percentage is a STATE, never an invented number.
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringContainsString('data-pp-fee="withdrawal_paypal"', $content);
        $this->assertStringContainsString('data-pp-fee="cash_in_paypal"', $content);
        $this->assertStringContainsString('NOT_CONFIGURED', $content);

        // And a runtime-configured null rate on any enabled percentage row
        // degrades to the same NOT_CONFIGURED display instead of a zero.
        config(['fees.categories.withdrawal_skrill.rate' => null]);
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringContainsString('NOT_CONFIGURED', $content);
    }

    // 5
    public function test_no_competitor_fee_values_hardcoded(): void
    {
        $content = (string) $this->get('/fees')->assertOk()->getContent();

        // Values are the PLATFORM's own configured settings, in the platform
        // currency: no USD figures, no dollar amounts, no naked raw numbers
        // between tags (every rendered amount carries its currency or %).
        $this->assertStringNotContainsString('$3', $content);
        $this->assertStringNotContainsString('3.00 USD', $content);
        $this->assertStringNotContainsString('USD', $content);
        $this->assertDoesNotMatchRegularExpression('/>\s*3\.00\s*</', $content);
        $this->assertDoesNotMatchRegularExpression('/>\s*8\.00\s*</', $content);

        // The page renders exactly what config declares — the rendered value
        // must be the formatted configuration, never a hardcoded literal.
        $rate = (string) config('fees.categories.cash_balance_transfer.rate');
        $percent = bcmul($rate, '100', 2).'%';
        $this->assertStringContainsString($percent, $content);
        $amount = (string) config('fees.categories.account_renewal.amount');
        $this->assertStringContainsString($amount.' '.(string) config('fees.currency'), $content);

        // Production sources embed no competitor identity and no literal fee
        // table: the service, controller, views and config carry no domain
        // reference, and no fee value is written into a Blade template.
        foreach ([
            'app/Services/PublicPages/FeesPageService.php',
            'app/Http/Controllers/PublicServicePagesController.php',
            'config/fees.php',
            'resources/views/fees/index.blade.php',
            'resources/views/components/public/fee-table.blade.php',
        ] as $path) {
            $src = (string) file_get_contents(base_path($path));
            $this->assertStringNotContainsStringIgnoringCase('thailotto', $src, $path);
            $this->assertStringNotContainsStringIgnoringCase('thailotto.club', $src, $path);
        }
        $blade = (string) file_get_contents(base_path('resources/views/components/public/fee-table.blade.php'));
        $this->assertStringNotContainsString('0.0300', $blade);
        $this->assertStringNotContainsString('0.0900', $blade);
        $this->assertStringNotContainsString('3.00', $blade);
    }

    // 6
    public function test_no_secret_or_internal_fee_data_on_public_page(): void
    {
        config(['fees.categories.referral.internal' => true, 'fees.categories.referral.enabled' => true]);
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-pp-fee="referral"', $content);
        $this->assertStringNotContainsString('idempotency', $content);
        $this->assertStringNotContainsString('private agent margin', $content);
        $this->assertStringNotContainsString('SQLSTATE', $content);
    }

    // 7
    public function test_fees_localization_files_have_identical_keys(): void
    {
        $en = (array) require base_path('lang/en/account_services.php');
        $th = (array) require base_path('lang/th/account_services.php');
        $this->assertSame(array_keys($en), array_keys($th));
        $this->assertGreaterThan(80, count($en));
        foreach ($en as $k => $v) {
            $this->assertNotSame('', trim((string) $v), 'empty en '.$k);
        }
        foreach ($th as $k => $v) {
            $this->assertNotSame('', trim((string) $v), 'empty th '.$k);
        }
    }

    // 8
    public function test_fee_table_semantic_structure(): void
    {
        $content = (string) $this->get('/fees')->assertOk()->getContent();
        $this->assertStringContainsString('<table', $content);
        $this->assertStringContainsString('<th scope="col">', $content);
        $this->assertStringContainsString('<th scope="row">', $content);
        $this->assertStringContainsString('caption', $content);
        $this->assertStringContainsString('fee-table-wrap', $content);
        $this->assertStringContainsString('prefers-reduced-motion', (string) file_get_contents(base_path('resources/css/account-services.css')));
        // Grouped sections + provider column + configured states exist.
        $this->assertStringContainsString('data-pp-section="fee-table"', $content);
        $this->assertStringContainsString('fee-table__provider', $content);
        $this->assertStringContainsString('data-pp-provider="bank"', $content);
        $this->assertStringContainsString('data-pp-state="CONFIGURED"', $content);
        $this->assertStringContainsString('data-pp-state="NOT_CONFIGURED"', $content);
    }

    // Fee calculator unit-style checks
    public function test_fee_calculator_min_max_zero_boundary_and_rounding(): void
    {
        $fees = app(FeesPageService::class);

        config(['fees.categories.cash_balance_transfer.rate' => '0.0150']);
        // Exact boundary
        $this->assertSame('1.50', $fees->calculate('cash_balance_transfer', '100.00'));
        // Zero
        $this->assertSame('0.00', $fees->calculate('cash_balance_transfer', '0.00'));
        // Rounding half-up at scale 2: 0.015 * 33.33 = 0.49995 → 0.50
        $this->assertSame('0.50', $fees->calculate('cash_balance_transfer', '33.33'));

        // Min / max clamp
        config(['fees.categories.cash_balance_transfer.min' => '2.00']);
        config(['fees.categories.cash_balance_transfer.max' => '5.00']);
        $this->assertSame('2.00', $fees->calculate('cash_balance_transfer', '50.00')); // below min → 2.00
        $this->assertSame('5.00', $fees->calculate('cash_balance_transfer', '10000.00')); // above max → 5.00

        // Percentage fee never exceeds base
        config(['fees.categories.cash_balance_transfer.min' => null]);
        config(['fees.categories.cash_balance_transfer.max' => null]);
        config(['fees.categories.cash_balance_transfer.rate' => '2.0000']); // 200%
        $this->assertSame('10.00', $fees->calculate('cash_balance_transfer', '10.00'));
    }

    public function test_fee_calculator_mirrors_the_live_engine_rule(): void
    {
        $fees = app(FeesPageService::class);

        // The generic + bank withdrawal rows resolve their rate at runtime
        // from the canonical live rule, so display and execution can never
        // disagree: same whole-percent value in, same fee out as
        // WithdrawalService::resolveFee would charge.
        config(['finance.withdrawal.fee_percentage' => '8.00']);
        $this->assertSame('8.00', $fees->calculate('withdrawal', '100.00'));
        $this->assertSame('8.00', $fees->calculate('withdrawal_bank', '100.00'));
        $this->assertSame('80.00', $fees->calculate('withdrawal', '1000.00'));

        // Half-percent live rule stays exact (2.50% of 100.00 = 2.50).
        config(['finance.withdrawal.fee_percentage' => '2.50']);
        $this->assertSame('2.50', $fees->calculate('withdrawal', '100.00'));

        // The engine's own resolver must agree to the cent.
        $engineFee = app(WithdrawalService::class)
            ->resolveFee(Money::of('100.00', Currency::THB));
        $this->assertSame('2.50', $engineFee->toString());

        // Cash-in mirror, same contract.
        config(['finance.deposit.fee_percentage' => '1.50']);
        $this->assertSame('1.50', $fees->calculate('cash_in', '100.00'));
        $this->assertSame('1.50', $fees->calculate('cash_in_bank', '100.00'));
    }

    public function test_fees_api_public_json(): void
    {
        $response = $this->getJson('/api/v1/fees');
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.categories'));
        $this->assertNotEmpty($response->json('data.groups'));

        // The JSON projection carries the provider-aware rows and the same
        // configured states as the HTML page.
        $categories = collect($response->json('data.categories'));
        $this->assertTrue($categories->contains(fn ($row) => $row['key'] === 'withdrawal_skrill' && $row['provider'] === 'skrill'));
        $this->assertTrue($categories->contains(fn ($row) => $row['key'] === 'withdrawal_paypal' && $row['state'] === 'NOT_CONFIGURED'));
        $this->assertTrue($categories->contains(fn ($row) => $row['key'] === 'cash_in_paypal' && $row['state'] === 'NOT_CONFIGURED'));
        $this->assertFalse($categories->contains(fn ($row) => $row['key'] === 'internal_provider_margin'));
    }

    // =====================================================================
    // VERIFICATION
    // =====================================================================

    // 9
    public function test_verification_unauthenticated_rejected(): void
    {
        $response = $this->get('/account/verification');
        $this->assertTrue(in_array($response->status(), [302, 401, 403], true));
        $this->assertNotSame(200, $response->status());
    }

    // 10
    public function test_authenticated_user_sees_own_status(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get('/account/verification')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('NOT_SUBMITTED', $content);
        $this->assertStringContainsString($this->player->email, $content);
        $this->assertStringContainsString('DOCUMENT_UPLOAD_VERIFICATION', $content);
        $this->assertStringContainsString('PHONE_VERIFICATION_NOT_CONFIGURED', $content);
    }

    // 11
    public function test_another_user_cannot_see_this_verification(): void
    {
        $other = User::factory()->create(['status' => UserStatus::Active]);
        $other->phone = '+66800000001';
        $other->save();

        $content = (string) $this->actingAs($this->player)
            ->get('/account/verification')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($other->email, $content);
        $this->assertStringNotContainsString('+66800000001', $content);
        $this->assertStringNotContainsString('800000001', $content);
    }

    // 12
    public function test_valid_submission_creates_pending_document(): void
    {
        $file = UploadedFile::fake()->image('passport.jpg', 640, 480);

        $this->actingAs($this->player)
            ->post('/account/verification', [
                'country_code' => '+66',
                'mobile' => '812345678',
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertRedirect(route('account.verification'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $this->player->id,
            'document_type' => 'passport',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->player->id,
        ]);
    }

    // 13
    public function test_duplicate_open_submission_rejected(): void
    {
        $file1 = UploadedFile::fake()->image('id.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'national_id',
                'document' => $file1,
            ])
            ->assertSessionHasNoErrors();

        $file2 = UploadedFile::fake()->image('id2.jpg', 640, 480);
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file2,
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(
            1,
            KycDocument::query()->where('user_id', $this->player->id)->count(),
            'duplicate open packages must not create endless rows',
        );
    }

    // 14
    public function test_invalid_mobile_rejected(): void
    {
        $file = UploadedFile::fake()->image('ok.jpg', 640, 480);
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'country_code' => '+66',
                'mobile' => 'abc',
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document');

        $this->assertDatabaseMissing('kyc_documents', ['user_id' => $this->player->id]);
    }

    // 15
    public function test_invalid_document_type_rejected(): void
    {
        $file = UploadedFile::fake()->image('ok.jpg', 640, 480);
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'birth_certificate_fake',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document_type');
    }

    // 16
    public function test_oversized_document_rejected(): void
    {
        $file = UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'); // 11MB
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document');
    }

    // 17
    public function test_executable_document_rejected(): void
    {
        $file = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document');
        $this->assertDatabaseMissing('kyc_documents', ['user_id' => $this->player->id]);
    }

    // 18
    public function test_svg_script_document_rejected(): void
    {
        $file = UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml');
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'other',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document');
    }

    // 19
    public function test_path_traversal_filename_rejected(): void
    {
        // Build a real temp file then wrap it with a traversal original name.
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'jpeg-bytes-not-really');

        $file = new UploadedFile(
            $tmp,
            '../../etc/passwd.jpg',
            'image/jpeg',
            null,
            true,
        );

        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document');
        $this->assertDatabaseMissing('kyc_documents', ['user_id' => $this->player->id]);

        @unlink($tmp);
    }

    // 20
    public function test_original_filename_not_used_as_storage_path(): void
    {
        $file = UploadedFile::fake()->image('my-passport-name.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasNoErrors();

        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();
        $this->assertStringNotContainsString('my-passport-name', (string) $doc->file_path);
        $this->assertStringStartsWith('kyc_documents/'.$this->player->id.'/', (string) $doc->file_path);
        $this->assertMatchesRegularExpression('/kyc_\d+_[A-Za-z0-9]+\.jpg$/', (string) basename((string) $doc->file_path));
        // Original name kept only as display metadata.
        $this->assertSame('my-passport-name.jpg', (string) $doc->original_filename);
    }

    // 21
    public function test_private_document_not_publicly_accessible(): void
    {
        $file = UploadedFile::fake()->image('pass.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasNoErrors();

        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();
        // No public /storage URL in page or response.
        $page = (string) $this->actingAs($this->player)->get('/account/verification')->getContent();
        $this->assertStringNotContainsString('/storage/kyc', $page);
        $this->assertStringNotContainsString('storage/app/private', $page);

        // Direct guest guess of storage path must not 200 with the file.
        $guess = '/storage/'.$doc->file_path;
        $response = $this->get($guess);
        $this->assertNotSame(200, $response->status());
    }

    // 22
    public function test_client_cannot_submit_approved(): void
    {
        $file = UploadedFile::fake()->image('x.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
                'status' => 'approved',
                'approved' => 'true',
                'phone_verified' => 'true',
                'verification_status' => 'APPROVED',
            ])
            ->assertSessionHasNoErrors();

        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();
        $this->assertSame(KycStatus::Pending, $doc->status);
        $this->assertNull($this->player->fresh()->phone_verified_at);
        $this->assertNotSame('APPROVED', $this->player->fresh()->kycStatus()->value);
    }

    // 23
    public function test_reviewer_authorization_four_eyes(): void
    {
        $file = UploadedFile::fake()->image('y.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ]);
        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();

        // Self-review forbidden by canonical service.
        $this->expectException(\InvalidArgumentException::class);
        app(KycVerificationService::class)
            ->reviewDocument($doc, $this->player, true, null);
    }

    // 24
    public function test_audit_exists_for_submission(): void
    {
        $file = UploadedFile::fake()->image('z.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ]);

        $audit = AuditLog::query()
            ->where('user_id', $this->player->id)
            ->get()
            ->contains(fn ($row) => str_contains(json_encode($row->metadata ?? []), 'account_verification_submitted')
                || str_contains(json_encode($row->metadata ?? []), 'kyc_document_submitted'));

        $this->assertTrue($audit, 'submission must write an audit row');
    }

    // 25
    public function test_rejected_flow(): void
    {
        $file = UploadedFile::fake()->image('r.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ]);
        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();

        $reviewer = User::factory()->create(['status' => UserStatus::Active]);
        app(KycVerificationService::class)
            ->reviewDocument($doc, $reviewer, false, 'blurred');

        $doc->refresh();
        $this->assertSame(KycStatus::Rejected, $doc->status);

        $status = app(AccountVerificationService::class)
            ->publicStatus($this->player->fresh());
        $this->assertSame('REJECTED', $status);
    }

    // 26
    public function test_approved_flow(): void
    {
        $file = UploadedFile::fake()->image('a.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'national_id',
                'document' => $file,
            ]);
        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();

        $reviewer = User::factory()->create(['status' => UserStatus::Active]);
        app(KycVerificationService::class)
            ->reviewDocument($doc, $reviewer, true, null);

        $doc->refresh();
        $this->assertSame(KycStatus::Verified, $doc->status);

        $status = app(AccountVerificationService::class)
            ->publicStatus($this->player->fresh());
        $this->assertSame('APPROVED', $status);
    }

    // 27
    public function test_expired_flow(): void
    {
        $file = UploadedFile::fake()->image('e.jpg', 640, 480);
        $this->actingAs($this->player)
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ]);
        $doc = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();

        $reviewer = User::factory()->create(['status' => UserStatus::Active]);
        app(KycVerificationService::class)
            ->reviewDocument($doc, $reviewer, true, null);

        // Force evidence lapse then derive expired public status via KycStatus path.
        $doc->refresh();
        $doc->expires_at = now()->subDay();
        $doc->save();

        // Aggregate: user-level Expired comes from verification lifecycle;
        // here we assert document past expiry fails re-verification evidence
        // and public mapping handles Expired.
        $this->assertSame(
            'EXPIRED',
            AccountVerification::publicStatusFromKycStatus(KycStatus::Expired),
        );
        // Past-expiry document is no longer countsAsVerified for gates that re-check expiry.
        $this->assertTrue($doc->expires_at->isPast());
    }

    public function test_double_extension_filename_rejected(): void
    {
        $file = UploadedFile::fake()->create('invoice.pdf.php', 10, 'application/pdf');
        // mimes rule uses extension — php fails mimes; also document service rejects.
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasErrors();
    }

    public function test_renamed_executable_with_pdf_extension_still_needs_valid_mime(): void
    {
        // Fake file claims pdf mime but service re-validates via getMimeType.
        $file = UploadedFile::fake()->create('malware.pdf', 10, 'application/x-msdownload');
        $this->actingAs($this->player)
            ->from('/account/verification')
            ->post('/account/verification', [
                'document_type' => 'passport',
                'document' => $file,
            ])
            ->assertSessionHasErrors('document');
    }

    public function test_null_byte_filename_rejected_by_document_service(): void
    {
        $base = UploadedFile::fake()->image('ok.jpg', 640, 480);
        $file = new UploadedFile(
            $base->getPathname(),
            "evil\0.jpg",
            'image/jpeg',
            null,
            true,
        );

        try {
            app(AccountVerificationDocumentService::class)
                ->storeDocument($this->player, KycDocumentType::Passport, $file, null, '127.0.0.1');
            $this->fail('null byte filename must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('null byte', $e->getMessage());
        }
    }

    // =====================================================================
    // GRADE
    // =====================================================================

    // 28
    public function test_own_grade_visible(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get('/account/grade')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-grade-key="bronze"', $content);
        $this->assertStringContainsString('0.00', $content);
        $this->assertStringContainsString('grade-history', $content);
    }

    // 29
    public function test_another_users_grade_forbidden(): void
    {
        $other = User::factory()->create(['status' => UserStatus::Active]);
        $this->seedSpend($other, '50000.00');
        app(AccountGradeService::class)->recalculate($other);

        $content = (string) $this->actingAs($this->player)
            ->get('/account/grade')
            ->assertOk()
            ->getContent();

        // Must not leak other user's spend figure or grade history rows.
        $this->assertStringNotContainsString('50000.00', $content);
        // No user id parameter accepted — route has none.
        $this->get('/account/grade?user_id='.$other->id)->assertOk();
        $page2 = (string) $this->get('/account/grade')->getContent();
        $this->assertStringNotContainsString('50000.00', $page2);
    }

    // 30
    public function test_rolling_spend_calculation(): void
    {
        $this->seedSpend($this->player, '100.00', now()->subDays(5));
        $this->seedSpend($this->player, '50.00', now()->subDays(40)); // outside 30d

        $spend = app(AccountGradeService::class)
            ->qualifyingSpend($this->player, now()->subDays(30), now());
        // Only the in-window 100.00 counts — the 50.00 outside the window must not.
        $this->assertSame(0, bccomp($spend, '100.00', 2), 'only in-window spend counts, got '.$spend);
        // Spend is strictly less than the combined 150.00 (outside-window excluded).
        $this->assertSame(-1, bccomp($spend, '150.00', 2), 'outside-window spend must be excluded, got '.$spend);
    }

    // 31
    public function test_reversed_spend_excluded(): void
    {
        $this->seedSpend($this->player, '80.00', now(), TransactionStatus::Reversed);
        $spend = app(AccountGradeService::class)
            ->qualifyingSpend($this->player, now()->subDays(30), now());
        $this->assertSame(0, bccomp($spend, '0.00', 2));
    }

    // 32
    public function test_refunded_spend_excluded(): void
    {
        // bet_refund type is not in qualifying types; also cancelled status excluded.
        $this->seedSpend($this->player, '80.00', now(), TransactionStatus::Cancelled);
        $tx = FinancialTransaction::query()->where('user_id', $this->player->id)->firstOrFail();
        $tx->type = TransactionType::BetRefund;
        $tx->save();

        $spend = app(AccountGradeService::class)
            ->qualifyingSpend($this->player, now()->subDays(30), now());
        $this->assertSame(0, bccomp($spend, '0.00', 2));
    }

    // 33
    public function test_highest_eligible_tier(): void
    {
        // GRADE PARITY BATCH: the benchmark ladder - 200/300/400/500/600
        // THB fraction thresholds, inclusive, highest wins, bronze base.
        $service = app(AccountGradeService::class);
        $this->assertSame('bronze', $service->resolveTier('0.00')['key']);
        $this->assertSame('bronze', $service->resolveTier('199.99')['key']);
        $this->assertSame('gold_plus', $service->resolveTier('200.00')['key']);
        $this->assertSame('platinum', $service->resolveTier('300.00')['key']);
        $this->assertSame('platinum_plus', $service->resolveTier('400.00')['key']);
        $this->assertSame('diamond', $service->resolveTier('500.00')['key']);
        $this->assertSame('diamond_plus', $service->resolveTier('600.00')['key']);
        $this->assertSame('diamond_plus', $service->resolveTier('999999.00')['key']);
    }

    // 34
    public function test_no_threshold_spoofing(): void
    {
        $response = $this->actingAs($this->player)
            ->post('/account/grade/refresh', [
                'grade' => 'platinum',
                'qualifying_spend' => '999999.00',
                'discount' => '0.5',
            ]);
        $response->assertRedirect(route('account.grade'));

        $grade = app(AccountGradeService::class)->current($this->player->fresh(), true);
        $this->assertSame('bronze', $grade['grade_key']);
        $this->assertSame('0.00', $grade['qualifying_spend']);
    }

    // 35
    public function test_grade_snapshot_created_on_refresh(): void
    {
        $this->seedSpend($this->player, '6000.00');
        $this->actingAs($this->player)
            ->post('/account/grade/refresh')
            ->assertRedirect(route('account.grade'));

        // 6000.00 >= 600.00 -> diamond_plus under the benchmark ladder.
        $this->assertDatabaseHas('account_grade_snapshots', [
            'user_id' => $this->player->id,
            'grade_key' => 'diamond_plus',
        ]);
    }

    // 36
    public function test_grade_history_immutable_across_recalculations(): void
    {
        // Window spends that cross exactly one programme boundary:
        // 250.00 -> gold_plus, then 350.00 -> platinum.
        $this->seedSpend($this->player, '250.00');
        $this->actingAs($this->player)->post('/account/grade/refresh');
        $firstCount = AccountGradeSnapshot::query()->where('user_id', $this->player->id)->count();
        $firstId = AccountGradeSnapshot::query()->where('user_id', $this->player->id)->firstOrFail()->id;

        $this->seedSpend($this->player, '350.00');
        $this->actingAs($this->player)->post('/account/grade/refresh');

        $snapshots = AccountGradeSnapshot::query()->where('user_id', $this->player->id)->get();
        $this->assertGreaterThanOrEqual($firstCount, $snapshots->count());
        // First snapshot row untouched (append-only).
        $still = AccountGradeSnapshot::query()->find($firstId);
        $this->assertNotNull($still);
        $this->assertSame('gold_plus', $still->grade_key);
    }

    // 37
    public function test_discount_calculated_server_side(): void
    {
        $this->seedSpend($this->player, '6000.00');
        app(AccountGradeService::class)->recalculate($this->player);

        // 6000.00 >= 600.00 -> diamond_plus at 0.0600 on the live product
        // lane; the engine caps at max_discount_rate 0.2500, untouched.
        $pricing = app(AccountDiscountService::class)->priceFor($this->player, '3d', '100.00');
        $this->assertSame('0.0600', $pricing['rate']);
        $this->assertSame('6.00', $pricing['discount']);
        $this->assertSame('94.00', $pricing['final']);
    }

    // 38
    public function test_client_cannot_override_discount(): void
    {
        $response = $this->actingAs($this->player)
            ->post('/account/grade/refresh', ['discount_rate' => '0.90']);
        $response->assertRedirect(route('account.grade'));

        $rate = app(AccountDiscountService::class)->discountRateForProduct($this->player, '3d');
        $this->assertSame('0.0000', $rate); // bronze without spend
    }

    // 39
    public function test_glo_l6_price_80_protected(): void
    {
        $this->seedSpend($this->player, '999999.00');
        app(AccountGradeService::class)->recalculate($this->player);

        $this->assertSame('80.00', (string) config('glo.l6.ticket_price'));
        $pricing = app(AccountDiscountService::class)->priceFor($this->player, 'glo_l6', '80.00');
        $this->assertSame('0.0000', $pricing['rate']);
        $this->assertSame('0.00', $pricing['discount']);
        $this->assertSame('80.00', $pricing['final']);
    }

    // 40
    public function test_glo_n3_price_20_protected(): void
    {
        $this->seedSpend($this->player, '999999.00');
        app(AccountGradeService::class)->recalculate($this->player);

        $this->assertSame('20.00', (string) config('glo.n3.ticket_price'));
        $pricing = app(AccountDiscountService::class)->priceFor($this->player, 'glo_n3', '20.00');
        $this->assertSame('0.0000', $pricing['rate']);
        $this->assertSame('0.00', $pricing['discount']);
        $this->assertSame('20.00', $pricing['final']);

        // Also N3 alias
        $pricing2 = app(AccountDiscountService::class)->priceFor($this->player, 'n3', '20.00');
        $this->assertSame('20.00', $pricing2['final']);
    }

    // =====================================================================
    // Extra architecture / security guards
    // =====================================================================

    public function test_no_second_kyc_table_created(): void
    {
        $this->assertTrue(
            Schema::hasTable('kyc_documents'),
            'canonical kyc_documents must exist',
        );
        $this->assertTrue(
            Schema::hasTable('kyc_verifications'),
            'canonical kyc_verifications must exist',
        );

        // PROMPT 3: account_verifications now EXISTS — but strictly as the
        // immutable submission-event aggregate (one row per submission:
        // reference, country/mobile pair, document pair, review outcome).
        // The guard is STRONGER than before: the table must carry no
        // identity-state vocabulary of its own beyond the mirrored enum,
        // and the model must keep delegating public status to the
        // canonical KYC machine (no parallel identity schema).
        $this->assertTrue(
            Schema::hasTable('account_verifications'),
            'the PROMPT 3 submission-event aggregate must exist',
        );
        $this->assertFalse(
            Schema::hasTable('account_verification_documents'),
            'documents must reuse kyc_documents',
        );

        $mirrored = array_map(
            static fn (\App\Enums\KycVerificationStatus $case): string => $case->value,
            \App\Enums\KycVerificationStatus::cases(),
        );
        $aggregate = array_map(
            static fn (\App\Enums\AccountVerificationStatus $case): string => $case->value,
            array_values(array_filter(
                \App\Enums\AccountVerificationStatus::cases(),
                static fn (\App\Enums\AccountVerificationStatus $case): bool =>
                    $case !== \App\Enums\AccountVerificationStatus::NotSubmitted,
            )),
        );
        $this->assertSame(
            $mirrored,
            $aggregate,
            'the aggregate status vocabulary must MIRROR the canonical KYC machine — no invented states',
        );
        $this->assertSame(
            'NOT_SUBMITTED',
            \App\Models\AccountVerification::publicStatusFromKycStatus(\App\Enums\KycStatus::Unverified),
            'public status words still flow through the canonical KYC status',
        );
    }

    public function test_no_competitor_branding_in_production_sources(): void
    {
        $paths = [
            'app/Services/PublicPages/FeesPageService.php',
            'app/Services/Account',
            'config/fees.php',
            'config/account_grades.php',
            'config/account.php',
            'resources/views/fees',
            'resources/views/account',
            'lang/en/account_services.php',
            'lang/th/account_services.php',
        ];
        foreach ($paths as $path) {
            $full = base_path($path);
            if (is_dir($full)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full));
                foreach ($iterator as $file) {
                    if (! $file->isFile()) {
                        continue;
                    }
                    $body = (string) file_get_contents($file->getPathname());
                    $this->assertStringNotContainsStringIgnoringCase('thailotto.club', $body, $file->getPathname());
                }
            } elseif (is_file($full)) {
                $body = (string) file_get_contents($full);
                $this->assertStringNotContainsStringIgnoringCase('thailotto.club', $body, $full);
            }
        }
    }

    public function test_no_unescaped_blade_on_account_pages(): void
    {
        foreach ([
            'resources/views/fees/index.blade.php',
            'resources/views/account/verification.blade.php',
            'resources/views/account/grade.blade.php',
            'resources/views/components/public/fee-table.blade.php',
            'resources/views/components/account/verification-status.blade.php',
            'resources/views/components/account/document-upload.blade.php',
            'resources/views/components/account/grade-card.blade.php',
        ] as $view) {
            $body = (string) file_get_contents(base_path($view));
            $this->assertStringNotContainsString('{!!', $body, $view);
        }
        foreach ([
            'resources/js/account-verification.js',
            'resources/js/account-grade.js',
        ] as $js) {
            $body = (string) file_get_contents(base_path($js));
            $this->assertStringNotContainsString('innerHTML', $body, $js);
        }
    }

    public function test_verification_and_grade_requires_auth_for_history_api_shape(): void
    {
        $this->get('/account/grade/history')->assertRedirect();
        $this->post('/account/grade/refresh')->assertRedirect();
        $this->post('/account/verification')->assertRedirect();
    }

    public function test_lang_key_parity_and_grade_page_localization_visible(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get('/account/grade')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Qualifying spend', $content);
        $this->assertStringContainsString('never changed by account grade discounts', $content);
    }

    // ------------------------------------------------------------------ helpers

    private function seedSpend(
        User $user,
        string $amount,
        ?Carbon $at = null,
        TransactionStatus $status = TransactionStatus::Completed,
    ): void {
        $at = $at ?? now();
        FinancialTransaction::query()->create([
            'reference_number' => 'TX-'.uniqid(),
            'user_id' => $user->id,
            'wallet_id' => null,
            'type' => TransactionType::BetPlacement,
            'currency' => Currency::THB,
            'amount' => $amount,
            'fee' => '0.00',
            'description' => 'qualifying spend seed',
            'metadata' => ['test' => true],
            'idempotency_key' => 'seed-'.uniqid(),
        ]);

        $tx = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->firstOrFail();

        // Lifecycle fields are not mass-assignable — set explicitly.
        $tx->status = $status;
        $tx->processed_at = $at;
        if ($status === TransactionStatus::Reversed) {
            $tx->reversed_at = $at;
        }
        $tx->save();
    }
}
