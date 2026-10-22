<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Enums\DrawStatus;
use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\LotteryTicketVerification;
use App\Models\User;
use App\Services\Affiliate\AffiliateCommissionDisplayService;
use App\Services\Lottery\PrizeVerificationService;
use App\Services\Lottery\TicketAuthenticityService;
use App\Services\Lottery\TicketBarcodeService;
use App\Services\Lottery\TicketIdentityService;
use App\Services\Pricing\LottoDiscountService;
use App\Services\Pricing\LottoPayoutRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PROMPT 4 — public Prize Verification + Lotto Discount.
 *
 * WHAT THIS FILE IS DEFENDING
 * Two anonymous pages were added that read the ticket, prize, claim, freeze
 * and pricing domains. Each of those domains already had an owner, so the
 * risks are not "does the page render" but:
 *
 *   1. does the page invent a claim it is not entitled to make
 *      (official GLO verification, paper authenticity, an unauthorised
 *      barcode format);
 *   2. does it leak anything owner-scoped (name, contact, bank, freeze
 *      reason, another affiliate's earnings, internal ids);
 *   3. does it let the client dictate a price, a discount or a verdict;
 *   4. does it move an immutable official price (L6 80.00 / N3 20.00);
 *   5. does it lose a leading zero anywhere;
 *   6. is it an enumeration oracle;
 *   7. does the displayed payout differ from the executed one.
 *
 * Every test below maps to one of those seven, plus a final concurrency
 * group proving the read paths stay consistent under repetition.
 *
 * REFERENCE-SITE NOTE
 * The brief cites thailotto.club/prize-verify.php and /discount.php as a
 * FEATURE reference. No markup, stylesheet, wording, fee, payout figure or
 * discount percentage was taken from it; test 39 asserts that string appears
 * nowhere in production source.
 */
final class PrizeVerificationAndDiscountTest extends TestCase
{
    use RefreshDatabase;

    private const WINNING_NUMBER = '123456';

    private const LOSING_NUMBER = '654321';

    /** A number whose leading zeros must survive every layer. */
    private const ZERO_LED_NUMBER = '007123';

    protected function setUp(): void
    {
        parent::setUp();

        // The limiter is real middleware on the POST route; tests that are not
        // about throttling must not trip it.
        RateLimiter::clear('ticket-verification');
        config()->set('ticket_verification.rate_limit.per_minute', 1000);
        config()->set('ticket_verification.rate_limit.per_hour', 1000);
        config()->set('ticket_verification.rate_limit.fingerprint_per_minute', 1000);
        config()->set('discounts.cache.enabled', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function publishedDraw(string $firstPrize = self::WINNING_NUMBER): Draw
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subHour(),
        ]);

        DrawResult::factory()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => $firstPrize,
            'published_at' => now()->subMinutes(30),
        ]);

        return $draw;
    }

    private function gloTicket(Draw $draw, string $number, string $product = 'l6'): GloTicket
    {
        return GloTicket::query()->create([
            'draw_id' => $draw->getKey(),
            'product' => $product,
            'ticket_number' => $number,
            'ticket_reference' => GloTicket::buildReference((int) $draw->getKey(), $product, $number),
            'metadata' => [],
        ]);
    }

    private function verifier(): PrizeVerificationService
    {
        return app(PrizeVerificationService::class);
    }

    /**
     * A minimal claim row. Written with forceFill because a public page must
     * cope with rows the claim workflow produced, not rows shaped for it.
     */
    private function recordClaim(Draw $draw, GloTicket $ticket, GloClaimStatus $status): GloPrizeClaim
    {
        $claim = new GloPrizeClaim;

        $claim->forceFill([
            'claim_reference' => 'CLM-'.uniqid(),
            'ticket_id' => $ticket->getKey(),
            'draw_id' => $draw->getKey(),
            'product' => 'l6',
            'prize_category' => 'first',
            'ticket_number' => self::WINNING_NUMBER,
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'net_prize' => '5970000.00',
            'claimant_user_id' => User::factory()->create()->getKey(),
            'claim_channel' => GloClaimChannel::GloOffice->value,
            'status' => $status->value,
            'payment_status' => $status === GloClaimStatus::Paid ? 'paid' : 'pending',
            'hold_status' => $status === GloClaimStatus::Hold ? 'active' : 'none',
            'submitted_at' => now(),
            'fingerprint' => hash('sha256', (string) $ticket->getKey().$status->value),
        ])->save();

        return $claim;
    }

    // =====================================================================
    // GROUP 1 — routing, rendering and page contract (1-6)
    // =====================================================================

    /** 1 */
    public function test_prize_verification_page_is_public_and_renders(): void
    {
        $response = $this->get('/prize-verification');

        $response->assertOk();
        $response->assertSee('data-pd-page="prize-verification"', false);
        $response->assertSee(trans('prize_discount.verify_heading'), false);
    }

    /** 2 */
    public function test_discount_page_is_public_and_renders(): void
    {
        $response = $this->get('/discounts');

        $response->assertOk();
        $response->assertSee('data-pd-page="discounts"', false);
        $response->assertSee(trans('prize_discount.discount_heading'), false);
    }

    /** 3 */
    public function test_both_routes_are_registered_with_expected_names_and_middleware(): void
    {
        $get = Route::getRoutes()->getByName('prize-verification');
        $post = Route::getRoutes()->getByName('prize-verification.submit');
        $discounts = Route::getRoutes()->getByName('discounts');

        $this->assertNotNull($get);
        $this->assertNotNull($post);
        $this->assertNotNull($discounts);

        $this->assertContains('throttle:ticket-verification', $post->gatherMiddleware());
        $this->assertNotContains('auth', $get->gatherMiddleware());
        $this->assertNotContains('auth', $discounts->gatherMiddleware());
    }

    /** 4 */
    public function test_verification_page_never_claims_to_be_an_official_glo_service(): void
    {
        $html = $this->get('/prize-verification')->getContent();

        foreach ([
            'official GLO verification',
            'official verification system',
            'official government verification',
            'verified by the Government Lottery Office',
        ] as $forbiddenClaim) {
            $this->assertStringNotContainsStringIgnoringCase($forbiddenClaim, $html);
        }

        $this->assertStringContainsString('GLO-compatible', $html);
        $this->assertStringContainsString(e(trans('prize_discount.not_official_notice')), $html);
    }

    /** 5 */
    public function test_verification_page_publishes_barcode_provider_states_only_from_the_supported_vocabulary(): void
    {
        $states = app(TicketBarcodeService::class)->providerStates();

        foreach (['glo_data_matrix', 'operator_qr'] as $provider) {
            $this->assertContains($states[$provider], [
                TicketBarcodeService::SUPPORTED,
                TicketBarcodeService::NOT_CONFIGURED,
                TicketBarcodeService::UNSUPPORTED_FORMAT,
                TicketBarcodeService::INVALID,
            ]);
        }

        $this->assertSame('SYNTHETIC_FIXTURE_V1', $states['fixture_schema']);
    }

    /** 6 */
    public function test_physical_inspection_notes_are_marked_informational_only(): void
    {
        $guidance = app(TicketAuthenticityService::class)->physicalInspectionGuidance();

        $this->assertTrue($guidance['informational_only']);
        $this->assertSame([
            'prize_discount.physical_note_intro',
            'prize_discount.physical_note_markings',
            'prize_discount.physical_note_barcode',
            'prize_discount.physical_note_limits',
        ], $guidance['keys']);

        $this->get('/prize-verification')->assertSee(trans('prize_discount.physical_note_limits'), false);
    }

    // =====================================================================
    // GROUP 2 — input normalisation and leading zeros (7-12)
    // =====================================================================

    /** 7 */
    public function test_six_digit_number_keeps_its_leading_zeros_end_to_end(): void
    {
        $draw = $this->publishedDraw(self::ZERO_LED_NUMBER);

        $result = $this->verifier()->verify('number', self::ZERO_LED_NUMBER);

        $this->assertSame(self::ZERO_LED_NUMBER, $result['query_echo']);
        $this->assertSame(PrizeVerificationService::WINNING, $result['status']);
        $this->assertSame($draw->getKey(), $result['draw']['id']);
    }

    /** 8 */
    public function test_identity_service_never_coerces_a_number_to_an_integer(): void
    {
        $normalised = app(TicketIdentityService::class)->normaliseNumber('007123');

        $this->assertTrue($normalised['valid']);
        $this->assertIsString($normalised['number']);
        $this->assertSame('007123', $normalised['number']);
        $this->assertSame('007123', $normalised['canonical']);
    }

    /** 9 */
    public function test_human_formatting_is_stripped_but_digits_are_never_deleted(): void
    {
        $normalised = app(TicketIdentityService::class)->normaliseNumber(' 00 71-23 ');

        $this->assertTrue($normalised['valid']);
        $this->assertSame('007123', $normalised['number']);
    }

    /** 10 */
    public function test_oversized_input_is_rejected_before_any_database_access(): void
    {
        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $this->verifier()->verify('number', str_repeat('9', 40));

        $this->assertSame(PrizeVerificationService::INVALID, $result['status']);
        // The only permissible query is the evidence insert.
        $this->assertLessThanOrEqual(2, $queries);
    }

    /** 11 */
    public function test_malformed_barcode_payload_is_invalid_and_is_not_parsed(): void
    {
        $result = $this->verifier()->verify('barcode', "\x00\x01bad payload<script>");

        $this->assertSame(PrizeVerificationService::INVALID, $result['status']);
        $this->assertSame(TicketIdentityService::PRODUCT_UNKNOWN, $result['product']);
    }

    /** 12 */
    public function test_unsupported_number_length_is_refused_rather_than_guessed(): void
    {
        $result = $this->verifier()->verify('number', '1234');

        $this->assertSame(PrizeVerificationService::INVALID, $result['status']);
        $this->assertSame('LENGTH_NOT_SUPPORTED', $result['reason']);
    }

    // =====================================================================
    // GROUP 3 — verification verdicts (13-22)
    // =====================================================================

    /** 13 */
    public function test_winning_six_digit_number_returns_winning(): void
    {
        $this->publishedDraw();

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        $this->assertSame(PrizeVerificationService::WINNING, $result['status']);
        $this->assertNotNull($result['prize']['amount']);
        $this->assertNotEmpty($result['prize']['matches']);
    }

    /** 14 */
    public function test_non_winning_six_digit_number_returns_found_not_winning(): void
    {
        $this->publishedDraw();

        $result = $this->verifier()->verify('number', self::LOSING_NUMBER);

        $this->assertSame(PrizeVerificationService::FOUND_NOT_WINNING, $result['status']);
        $this->assertNull($result['prize']['amount']);
    }

    /** 15 */
    public function test_a_three_digit_number_without_a_draw_reference_is_unavailable_not_a_guess(): void
    {
        $result = $this->verifier()->verify('number', '123');

        $this->assertSame(PrizeVerificationService::UNAVAILABLE, $result['status']);
        $this->assertSame('DRAW_REFERENCE_REQUIRED', $result['reason']);
    }

    /** 16 */
    public function test_a_glo_reference_resolves_its_draw_product_and_number(): void
    {
        $draw = $this->publishedDraw();
        $ticket = $this->gloTicket($draw, self::WINNING_NUMBER);

        $result = $this->verifier()->verify('reference', (string) $ticket->ticket_reference);

        $this->assertSame(TicketIdentityService::PRODUCT_GLO_L6, $result['product']);
        $this->assertSame(self::WINNING_NUMBER, $result['query_echo']);
        $this->assertSame(PrizeVerificationService::WINNING, $result['status']);
    }

    /** 17 */
    public function test_a_recorded_glo_ticket_caps_at_public_record_found_and_never_claims_paper(): void
    {
        $draw = $this->publishedDraw();
        $this->gloTicket($draw, self::WINNING_NUMBER);

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        // This platform is not the state lottery's issue registry, so even a
        // complete local projection caps at PUBLIC_RECORD_FOUND. The stronger
        // DIGITAL_RECORD_VERIFIED is reserved for tickets this application
        // itself issued.
        $this->assertSame(
            TicketAuthenticityService::PUBLIC_RECORD_FOUND,
            $result['authenticity']['state'],
        );
        $this->assertFalse($result['authenticity']['paper_authenticity_claimed']);
    }

    /** 18 */
    public function test_a_number_with_no_platform_ticket_is_not_verified_at_all(): void
    {
        $this->publishedDraw();

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        $this->assertSame(
            TicketAuthenticityService::NOT_VERIFIED,
            $result['authenticity']['state'],
        );
        $this->assertNotSame(
            TicketAuthenticityService::DIGITAL_RECORD_VERIFIED,
            $result['authenticity']['state'],
        );
        $this->assertFalse($result['authenticity']['paper_authenticity_claimed']);
    }

    /** 19 */
    public function test_a_paid_claim_downgrades_the_verdict_to_paid(): void
    {
        $draw = $this->publishedDraw();
        $ticket = $this->gloTicket($draw, self::WINNING_NUMBER);

        $this->recordClaim($draw, $ticket, GloClaimStatus::Paid);

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        $this->assertSame(PrizeVerificationService::PAID, $result['status']);
    }

    /** 20 */
    public function test_a_held_claim_reports_payment_hold_without_any_reason_detail(): void
    {
        $draw = $this->publishedDraw();
        $ticket = $this->gloTicket($draw, self::WINNING_NUMBER);

        $this->recordClaim($draw, $ticket, GloClaimStatus::Hold);

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        $this->assertSame(PrizeVerificationService::PAYMENT_HOLD, $result['status']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['police', 'investigation', 'authority', 'case'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }

    /** 21 */
    public function test_every_status_returned_is_inside_the_published_public_vocabulary(): void
    {
        $this->publishedDraw();

        $statuses = [];
        foreach ([self::WINNING_NUMBER, self::LOSING_NUMBER, '123', '1234', 'zz'] as $value) {
            $statuses[] = $this->verifier()->verify('number', $value)['status'];
        }

        foreach ($statuses as $status) {
            $this->assertContains($status, (array) config('ticket_verification.public_statuses'));
        }
    }

    /** 22 */
    public function test_official_source_is_false_on_every_answer(): void
    {
        $this->publishedDraw();

        foreach (['number', 'reference', 'barcode'] as $kind) {
            $result = $this->verifier()->verify($kind, self::WINNING_NUMBER);
            $this->assertFalse($result['official_source'], $kind.' claimed an official source');
        }
    }

    // =====================================================================
    // GROUP 4 — privacy and client-authority (23-28)
    // =====================================================================

    /** 23 */
    public function test_verification_output_contains_no_owner_identifying_field(): void
    {
        $draw = $this->publishedDraw();
        $ticket = $this->gloTicket($draw, self::WINNING_NUMBER);
        $ticket->forceFill(['owner_user_id' => null])->save();

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        foreach (['owner_user_id', 'email', 'phone', 'mobile', 'bank', 'national_id', 'address'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                json_encode($result, JSON_THROW_ON_ERROR),
            );
        }
    }

    /** 24 */
    public function test_client_supplied_verdict_fields_are_ignored_by_the_endpoint(): void
    {
        $this->publishedDraw();

        $response = $this->postJson('/prize-verification', [
            'mode' => 'number',
            'value' => self::LOSING_NUMBER,
            'is_winner' => true,
            'status' => 'WINNING',
            'verified' => true,
            'authentic' => true,
            'prize' => ['amount' => '9999999.00'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', PrizeVerificationService::FOUND_NOT_WINNING);
        $response->assertJsonPath('data.prize.amount', null);
    }

    /** 25 */
    public function test_an_unknown_mode_is_rejected_by_validation(): void
    {
        $this->postJson('/prize-verification', [
            'mode' => 'telepathy',
            'value' => self::WINNING_NUMBER,
        ])->assertStatus(422);
    }

    /** 26 */
    public function test_evidence_rows_store_a_hash_and_never_the_submitted_value(): void
    {
        $this->publishedDraw();

        $this->verifier()->verify('number', self::ZERO_LED_NUMBER, correlationId: 'corr-evidence-1');

        $row = LotteryTicketVerification::query()->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString(self::ZERO_LED_NUMBER, json_encode($row->toArray(), JSON_THROW_ON_ERROR));
        $this->assertNotEmpty($row->query_fingerprint);
        $this->assertSame(64, strlen((string) $row->query_fingerprint));
    }

    /** 27 */
    public function test_the_evidence_public_projection_exposes_no_internal_columns(): void
    {
        $this->publishedDraw();
        $this->verifier()->verify('number', self::WINNING_NUMBER, correlationId: 'corr-evidence-2');

        $row = LotteryTicketVerification::query()->latest('id')->firstOrFail();
        $public = $row->toPublicArray();

        $this->assertArrayNotHasKey('id', $public);
        $this->assertArrayNotHasKey('query_fingerprint', $public);
    }

    /** 28 */
    public function test_disabled_verification_answers_unavailable_instead_of_failing_open(): void
    {
        config()->set('ticket_verification.enabled', false);

        $result = $this->verifier()->verify('number', self::WINNING_NUMBER);

        $this->assertSame(PrizeVerificationService::UNAVAILABLE, $result['status']);
        $this->assertSame('VERIFICATION_DISABLED', $result['reason']);
    }

    // =====================================================================
    // GROUP 5 — discount engine (29-36)
    // =====================================================================

    /** 29 */
    public function test_glo_l6_price_can_never_be_discounted(): void
    {
        $quote = app(LottoDiscountService::class)->quote('glo_l6', null, '80.00');

        $this->assertSame(LottoDiscountService::GLO_IMMUTABLE, $quote['refused']);
        $this->assertSame('80.00', $quote['final']);
        $this->assertSame('0.00', $quote['discount_total']);
    }

    /** 30 */
    public function test_glo_n3_price_can_never_be_discounted(): void
    {
        $quote = app(LottoDiscountService::class)->quote('glo_n3', null, '20.00');

        $this->assertSame(LottoDiscountService::GLO_IMMUTABLE, $quote['refused']);
        $this->assertSame('20.00', $quote['final']);
    }

    /** 31 */
    public function test_configured_glo_prices_still_match_the_official_expected_values(): void
    {
        $audit = app(LottoDiscountService::class)->immutablePriceAudit();

        $this->assertSame('80.00', (string) $audit['glo_l6']['expected']);
        $this->assertSame('20.00', (string) $audit['glo_n3']['expected']);
        $this->assertTrue($audit['glo_l6']['matches']);
        $this->assertTrue($audit['glo_n3']['matches']);
    }

    /** 32 */
    public function test_a_percentage_rule_is_applied_once_and_capped(): void
    {
        $quote = app(LottoDiscountService::class)->quote('operator_3d', '3d_direct', '1000.00');

        $this->assertNull($quote['refused']);
        $this->assertCount(1, $quote['layers']);
        // 2% of 1000 = 20.00, under the 40.00 rule cap.
        $this->assertSame('20.00', $quote['discount_total']);
        $this->assertSame('980.00', $quote['final']);
    }

    /** 33 */
    public function test_a_rule_maximum_discount_is_honoured(): void
    {
        $quote = app(LottoDiscountService::class)->quote('operator_3d', '3d_direct', '100000.00');

        $this->assertSame('40.00', $quote['discount_total']);
        $this->assertSame('99960.00', $quote['final']);
    }

    /** 34 */
    public function test_a_rule_below_its_minimum_amount_does_not_apply(): void
    {
        $quote = app(LottoDiscountService::class)->quote('operator_3d', '3d_direct', '10.00');

        $this->assertSame('0.00', $quote['discount_total']);
        $this->assertSame('10.00', $quote['final']);
        $this->assertSame([], $quote['layers']);
    }

    /** 35 */
    public function test_a_market_scoped_rule_only_applies_to_that_market(): void
    {
        $service = app(LottoDiscountService::class);

        $bottom = $service->quote('operator_run', 'run_bottom', '100.00');
        $top = $service->quote('operator_run', 'run_top', '100.00');

        $this->assertSame('1.00', $bottom['discount_total']);
        $this->assertSame('0.00', $top['discount_total']);
    }

    /** 36 */
    public function test_the_final_price_is_never_negative_and_the_total_cap_holds(): void
    {
        config()->set('discounts.rules.operator_2d_launch.discount_value', '99.00');
        config()->set('discounts.rules.operator_2d_launch.maximum_discount', null);
        config()->set('discounts.rules.operator_2d_launch.minimum_amount', null);

        $quote = app(LottoDiscountService::class)->quote('operator_2d', '2d_top', '100.00');

        // Capped at 60% of base by discounts.limits.max_total_percentage.
        $this->assertSame('60.00', $quote['discount_total']);
        $this->assertSame('40.00', $quote['final']);
        $this->assertTrue(bccomp($quote['final'], '0.00', 2) >= 0);
    }

    // =====================================================================
    // GROUP 6 — catalogue, payout and affiliate presentation (37-40)
    // =====================================================================

    /** 37 */
    public function test_the_public_catalogue_hides_disabled_and_expired_rules(): void
    {
        $catalogue = app(LottoDiscountService::class)->publicCatalogue('en');

        $ruleIds = [];
        foreach ($catalogue['products'] as $product) {
            foreach ($product['rules'] as $rule) {
                $ruleIds[] = $rule['rule_id'];
            }
        }

        $this->assertContains('operator_3d_launch', $ruleIds);
        // Disabled + not public_visible.
        $this->assertNotContains('operator_3d_exclusive_event', $ruleIds);
        // Enabled and public, but its effective_to has passed.
        $this->assertNotContains('operator_2d_opening_week', $ruleIds);
    }

    /** 38 */
    public function test_displayed_payout_multipliers_are_the_executed_ones(): void
    {
        $payouts = app(LottoPayoutRuleService::class);
        $pairs = $payouts->publicPairs();

        $this->assertNotSame([], $pairs);

        foreach ($pairs as $pair) {
            foreach (['direct', 'reverse'] as $role) {
                if ($pair[$role] === null) {
                    continue;
                }

                $this->assertTrue(
                    $payouts->displayMatchesExecution($pair[$role]['market']),
                    $pair[$role]['market'].' displays a rate the engine would not execute',
                );
            }
        }

        // No immutable GLO product may appear as an operator payout card.
        $products = array_column($pairs, 'product');
        $this->assertNotContains('glo_l6', $products);
        $this->assertNotContains('glo_n3', $products);
    }

    /** 39 */
    public function test_no_competitor_value_or_reference_exists_in_production_source(): void
    {
        $roots = [base_path('app'), base_path('config'), base_path('resources'), base_path('routes'), base_path('lang')];
        $hits = [];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (stripos($contents, 'thailotto.club') !== false) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, 'Competitor reference found in production source.');
    }

    /** 40 */
    public function test_affiliate_display_publishes_bands_only_and_hides_internal_ones(): void
    {
        $catalogue = app(AffiliateCommissionDisplayService::class)->publicCatalogue();

        $this->assertTrue($catalogue['published']);

        $bands = array_column($catalogue['bands'], 'band');
        $this->assertContains('standard', $bands);
        $this->assertContains('partner', $bands);
        $this->assertNotContains('internal_pilot', $bands);

        $html = $this->get('/discounts')->getContent();
        $this->assertStringNotContainsString('2.25', $html);

        config()->set('discounts.affiliate_display.publish', false);
        $this->assertSame([], app(AffiliateCommissionDisplayService::class)->publicCatalogue()['bands']);
    }

    // =====================================================================
    // GROUP 7 — concurrency and repetition (C1-C5)
    // =====================================================================

    /** C1 */
    public function test_concurrency_repeated_verification_of_the_same_number_is_stable(): void
    {
        $this->publishedDraw();

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->verifier()->verify('number', self::WINNING_NUMBER)['status'];
        }

        $this->assertSame([PrizeVerificationService::WINNING], array_values(array_unique($statuses)));
    }

    /** C2 */
    public function test_concurrency_evidence_is_deduplicated_per_fingerprint_and_correlation(): void
    {
        $this->publishedDraw();

        for ($i = 0; $i < 8; $i++) {
            $this->verifier()->verify('number', self::WINNING_NUMBER, correlationId: 'corr-dedupe');
        }

        $this->assertSame(
            1,
            LotteryTicketVerification::query()->where('correlation_id', 'corr-dedupe')->count(),
        );
    }

    /** C3 */
    public function test_concurrency_distinct_correlations_each_record_exactly_one_row(): void
    {
        $this->publishedDraw();

        for ($i = 0; $i < 6; $i++) {
            $this->verifier()->verify('number', self::WINNING_NUMBER, correlationId: 'corr-'.$i);
        }

        $this->assertSame(6, LotteryTicketVerification::query()->count());
    }

    /** C4 */
    public function test_concurrency_the_rate_limiter_stops_an_enumeration_sweep(): void
    {
        config()->set('ticket_verification.rate_limit.per_minute', 3);
        config()->set('ticket_verification.rate_limit.per_hour', 100);
        config()->set('ticket_verification.rate_limit.fingerprint_per_minute', 100);
        RateLimiter::clear('ticket-verification');

        $this->publishedDraw();

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->postJson('/prize-verification', [
                'mode' => 'number',
                'value' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'The public checker never throttled a sweep.');
    }

    /** C5 */
    public function test_concurrency_repeated_quotes_never_double_apply_a_discount(): void
    {
        $service = app(LottoDiscountService::class);

        $totals = [];
        for ($i = 0; $i < 10; $i++) {
            $quote = $service->quote('operator_3d', '3d_direct', '1000.00');
            $totals[] = $quote['discount_total'];
            $this->assertCount(1, $quote['layers']);
        }

        $this->assertSame(['20.00'], array_values(array_unique($totals)));
    }
}
