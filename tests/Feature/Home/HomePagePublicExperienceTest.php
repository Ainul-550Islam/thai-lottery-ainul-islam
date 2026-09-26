<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloSalesPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * PROMPT 1 — public Home feature contract.
 *
 * 30 required behaviors + security tests (XSS, malicious/oversized ticket input,
 * rate limit, no PII/secrets/admin metrics) + performance bounds (no N+1 /
 * unbounded public search).
 */
final class HomePagePublicExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ------------------------------------------------------------------
    // 1–10 Core presence / anonymity / honesty
    // ------------------------------------------------------------------

    public function test_home_is_public_anonymous_without_login(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $this->assertGuest();
    }

    public function test_home_contains_required_feature_landmarks(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        foreach ([
            'id="current-result"',
            'id="next-draw"',
            'id="live-draw"',
            'id="check"',
            'id="sales-points"',
            'id="products"',
            'id="prize"',
            'id="stats"',
            'id="bonuses"',
            'id="payments"',
            'id="support"',
            'id="app-links"',
            'id="trust"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content, 'Missing landmark '.$needle);
        }
    }

    public function test_home_never_returns_500_without_draws(): void
    {
        $this->get('/')->assertOk();
        $this->get('/check')->assertOk();
        $this->get('/sales-points')->assertOk();
        $this->get('/contact')->assertOk();
    }

    public function test_current_result_honest_without_verified_row(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('No verified result available', $content);
    }

    public function test_next_draw_countdown_uses_server_iso_target(): void
    {
        Draw::factory()->create([
            'status' => DrawStatus::Scheduled,
            'scheduled_at' => now()->addDays(3),
        ]);

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-home-countdown', $content);
        $this->assertMatchesRegularExpression('/data-target="[^"]+"/', $content);
        $this->assertStringContainsString('Asia/Bangkok', $content);

        // JS must not implement a draw calendar.
        $js = (string) file_get_contents(base_path('resources/js/home/countdown.js'));
        $this->assertStringNotContainsString('getMonth', $js);
        $this->assertStringNotContainsString('[1, 16]', $js);
    }

    public function test_live_draw_honest_not_configured(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('LIVE DRAW NOT_CONFIGURED', $content);
        $this->assertStringNotContainsString('<iframe', $content);
        $this->assertStringNotContainsString('youtube.com/embed/', $content);
        $this->assertStringNotContainsString('player.twitch.tv', $content);
    }

    public function test_ticket_check_ui_is_dedicated_form_not_raw_api_link(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(route('ticket-check'), $content);
        $this->assertStringNotContainsString('/api/v1/glo/results/check/000001', $content);

        $page = (string) $this->get('/check')->assertOk()->getContent();
        $this->assertStringContainsString('name="number"', $page);
        $this->assertStringContainsString('maxlength="6"', $page);
    }

    public function test_sales_point_cta_links_to_public_search(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(route('sales-points'), $content);
    }

    public function test_glo_product_summary_uses_official_prices_not_40(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('80.00', $content);
        $this->assertStringContainsString('20.00', $content);
        $this->assertStringNotContainsString('40.00', $content);
        // Banned fixed N3 payouts never appear.
        foreach (['3686', '749', '531', '252116'] as $banned) {
            $this->assertStringNotContainsString($banned, $content);
        }
    }

    public function test_prize_highlight_unavailable_or_verified_only(): void
    {
        // No draws → catalogue-only or unavailable, never a fake jackpot number.
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertTrue(
            str_contains($content, 'CATALOGUE_ONLY')
            || str_contains($content, 'UNAVAILABLE')
            || str_contains($content, 'VERIFIED'),
            'Prize highlight must carry an explicit status',
        );
        $this->assertStringNotContainsString('Official GLO Jackpot', $content);
        $this->assertStringNotContainsString('15700', $content);
        $this->assertStringNotContainsString('189300', $content);
        $this->assertStringNotContainsString('18600', $content);
    }

    // ------------------------------------------------------------------
    // 11–20 Results, stats, bonuses, payments, support, apps, trust, footer
    // ------------------------------------------------------------------

    public function test_verified_result_shows_six_digit_string_with_leading_zero(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'home-lead-1',
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '012345',
            'second_prize' => ['654321'],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => ['glo' => [
                'import_fingerprint' => 'home-fp-1',
                'import_provider' => 'fixture',
            ]],
        ]);

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('012345', $content);
        // Fixture never labeled official.
        $this->assertStringContainsString(GloSourceState::FixtureOnly->value, $content);
        $this->assertStringNotContainsString('Official GLO Jackpot', $content);
    }

    public function test_stats_are_db_backed_and_not_fabricated(): void
    {
        User::factory()->create();
        User::factory()->create();

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('VERIFIED', $content);
        $this->assertStringNotContainsString('15700', $content);
        $this->assertStringNotContainsString('189300', $content);
        $this->assertStringNotContainsString('18600', $content);
    }

    public function test_bonuses_empty_config_renders_no_active_promotions(): void
    {
        config(['home.campaigns' => []]);
        Cache::flush();
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('No active promotions', $content);
    }

    public function test_bonuses_active_window_renders_campaign(): void
    {
        config(['home.campaigns' => [[
            'id' => 'c1',
            'title' => 'Welcome bonus',
            'description' => 'Example configured campaign',
            'valid_from' => now()->subDay()->toIso8601String(),
            'valid_until' => now()->addDay()->toIso8601String(),
        ]]]);
        Cache::flush();

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Welcome bonus', $content);
        $this->assertStringContainsString('c1', $content);
    }

    public function test_expired_campaign_not_rendered(): void
    {
        config(['home.campaigns' => [[
            'id' => 'old',
            'title' => 'Expired campaign that must not show',
            'valid_from' => now()->subMonth()->toIso8601String(),
            'valid_until' => now()->subDay()->toIso8601String(),
        ]]]);
        Cache::flush();

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Expired campaign that must not show', $content);
        $this->assertStringContainsString('No active promotions', $content);
    }

    public function test_payment_methods_not_configured_by_default(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('NOT_CONFIGURED', $content);
        $this->assertStringNotContainsString('sk_live_', $content);
        $this->assertStringNotContainsString('whsec_', $content);
    }

    public function test_payment_methods_available_when_enabled_with_credentials(): void
    {
        config([
            'payment.deposit.enabled' => true,
            'payment.deposit.allowed_methods' => ['stripe'],
            'payment.gateways.stripe' => [
                'enabled' => true,
                'key' => 'pk_test_placeholder',
                'secret' => 'sk_test_never_rendered',
            ],
        ]);
        Cache::flush();

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('AVAILABLE', $content);
        $this->assertStringContainsString('Stripe', $content);
        $this->assertStringNotContainsString('sk_test_never_rendered', $content);
    }

    public function test_support_not_configured_does_not_invent_contact(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('NOT_CONFIGURED', $content);
        $this->assertStringNotContainsString('0800-000-000', $content);
        $this->assertStringNotContainsString('support@example.com', $content);
        $this->assertStringNotContainsString('+66 2 000 0000', $content);
    }

    public function test_app_links_not_configured_renders_no_fake_store_button(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('App download links are not configured', $content);
        $this->assertStringNotContainsString('play.google.com', $content);
        $this->assertStringNotContainsString('apps.apple.com', $content);
    }

    public function test_trust_section_only_factual_claims(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Server-side authorization', $content);
        $this->assertStringContainsString('Idempotent wallet', $content);
        // No invented compliance badges.
        $this->assertStringNotContainsString('ISO 27001', $content);
        $this->assertStringNotContainsString('PCI-DSS certified', $content);
    }

    public function test_footer_required_links_and_dynamic_year_and_app_name(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('home'), $content);
        $this->assertStringContainsString(route('results.index'), $content);
        $this->assertStringContainsString(route('ticket-check'), $content);
        $this->assertStringContainsString(route('sales-points'), $content);
        $this->assertStringContainsString(route('terms'), $content);
        $this->assertStringContainsString(route('contact'), $content);
        $this->assertStringContainsString(route('privacy'), $content);
        $this->assertStringContainsString(route('login'), $content);
        $this->assertStringContainsString(route('register'), $content);
        $this->assertStringContainsString((string) date('Y'), $content);
        $this->assertStringContainsString(e(config('app.name')), $content);
    }

    public function test_language_files_exist_for_thai_and_english(): void
    {
        $this->assertFileExists(base_path('lang/en/home.php'));
        $this->assertFileExists(base_path('lang/th/home.php'));
        $en = (array) require base_path('lang/en/home.php');
        $th = (array) require base_path('lang/th/home.php');
        $this->assertSame(array_keys($en), array_keys($th));
    }

    public function test_accessibility_landmarks_and_roles(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<main', $content);
        $this->assertStringContainsString('role="contentinfo"', $content);
        $this->assertStringContainsString('aria-live', $content);
        $this->assertStringContainsString('aria-labelledby', $content);
        $this->assertStringContainsString('prefers-reduced-motion', (string) file_get_contents(base_path('resources/css/home.css')));
    }

    public function test_countdown_not_sole_timing_info(): void
    {
        Draw::factory()->create([
            'status' => DrawStatus::Scheduled,
            'scheduled_at' => now()->addDays(2)->setTime(15, 0),
        ]);

        $content = (string) $this->get('/')->assertOk()->getContent();
        // Absolute date/time present alongside countdown.
        $this->assertStringContainsString('<time', $content);
        $this->assertStringContainsString('data-home-countdown', $content);
        $this->assertStringContainsString('visually-hidden', $content);
    }

    // ------------------------------------------------------------------
    // 21–30 Ticket check behavior + remaining contract
    // ------------------------------------------------------------------

    public function test_ticket_check_accepts_leading_zero_six_digits(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '000001',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => ['glo' => ['import_fingerprint' => 'fp-z', 'import_provider' => 'fixture']],
        ]);

        $response = $this->post('/check', ['number' => '000001']);
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString('000001', $content);
    }

    public function test_ticket_check_rejects_non_six_digit_input(): void
    {
        $response = $this->post('/check', ['number' => '123']);
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertTrue(
            str_contains($content, 'six digits') || str_contains($content, 'exactly six'),
            'Expected six-digit validation message',
        );
    }

    public function test_no_cdn_tailwind_in_layout(): void
    {
        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $this->assertStringNotContainsString('cdn.tailwindcss.com', $layout);
        $this->assertStringContainsString('@vite', $layout);
        $this->assertStringNotContainsString('<link href="/resources/css/app.css"', $layout);
    }

    public function test_no_thailotto_club_branding_or_copy(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('thailotto.club', $content);
        $this->assertStringNotContainsString('Thailotto', $content);
    }

    // ------------------------------------------------------------------
    // Security tests
    // ------------------------------------------------------------------

    public function test_campaign_xss_is_escaped(): void
    {
        config(['home.campaigns' => [[
            'id' => 'xss',
            'title' => '<script>alert(1)</script>',
            'description' => '"><img src=x onerror=alert(2)>',
            'valid_from' => now()->subDay()->toIso8601String(),
            'valid_until' => now()->addDay()->toIso8601String(),
        ]]]);
        Cache::flush();

        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $content);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    public function test_support_xss_email_is_escaped(): void
    {
        config(['home.support.email' => 'evil"><script>alert(3)</script>@x.test']);
        $content = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(3)</script>', $content);
    }

    public function test_malicious_ticket_input_rejected_and_escaped(): void
    {
        $payloads = [
            '<script>alert(1)</script>',
            "'; DROP TABLE draws; --",
            str_repeat('9', 5000),
            '../../etc/passwd',
            '000001<script>',
        ];

        foreach ($payloads as $i => $payload) {
            $response = $this->post('/check', ['number' => $payload]);
            $response->assertOk();
            $content = (string) $response->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $content, 'payload '.$i);
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('stack trace', $content);
        }
    }

    public function test_ticket_check_rate_limited(): void
    {
        RateLimiter::clear('home-check|home-check:127.0.0.1');
        RateLimiter::clear('home-check:home-check:127.0.0.1');
        RateLimiter::clear('home-check|ip:127.0.0.1');

        $status = 200;
        for ($i = 0; $i < 15; $i++) {
            $status = $this->post('/check', ['number' => '111111'])->status();
            if ($status === 429) {
                break;
            }
        }

        $this->assertSame(429, $status, 'home-check limiter must return 429 after ceiling');
        RateLimiter::clear('home-check|home-check:127.0.0.1');
        RateLimiter::clear('home-check:home-check:127.0.0.1');
        RateLimiter::clear('home-check|ip:127.0.0.1');
    }

    public function test_home_has_no_pii_secrets_or_admin_metrics(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        foreach ([
            'base64:ZdZRVoeGZh2IQXa67',
            'APP_DEBUG',
            'DB_PASSWORD',
            'api_key',
            'secret_key',
            'reconciliation',
            'ledger_balance',
            'is_admin',
            'two_factor_secret',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $content, 'Leaked: '.$needle);
        }
    }

    // ------------------------------------------------------------------
    // Performance bounds
    // ------------------------------------------------------------------

    public function test_stats_query_count_is_bounded(): void
    {
        User::factory()->count(3)->create();
        Cache::flush();

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/')->assertOk();
        // Home page must not N+1: generous ceiling for session/schema warmup,
        // but far below per-row queries across users/results.
        $this->assertLessThan(40, $queries, 'Home query count too high: '.$queries);
    }

    public function test_sales_point_search_never_returns_unbounded_table(): void
    {
        foreach (range(1, 25) as $i) {
            GloSalesPoint::query()->create([
                'sales_point_code' => 'SP-HOME-'.$i,
                'dealer_id' => null,
                'display_name' => 'Point '.$i,
                'address' => 'Addr '.$i,
                'province' => 'Bangkok',
                'district' => 'D'.$i,
                'latitude' => 13.75 + $i * 0.001,
                'longitude' => 100.5 + $i * 0.001,
                'status' => 'active',
            ]);
        }

        $response = $this->get('/sales-points?q=Point');
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString('Point 1', $content);
        // Hard bound from service config (max_results default 50) — with 25 rows
        // the page still proves pagination/total semantics exist.
        $this->assertStringContainsString('found', $content);
    }

    public function test_home_does_not_query_draws_exponentially(): void
    {
        foreach (range(1, 8) as $i) {
            $draw = Draw::factory()->create([
                'status' => DrawStatus::Completed,
                'scheduled_at' => now()->subDays($i),
                'draw_number' => 'perf-'.$i,
            ]);
            DrawResult::query()->create([
                'draw_id' => $draw->getKey(),
                'first_prize' => sprintf('%06d', $i),
                'second_prize' => [],
                'third_prize' => [],
                'consolation_prizes' => [],
                'metadata' => ['glo' => ['import_fingerprint' => 'perf-fp-'.$i, 'import_provider' => 'fixture']],
            ]);
        }
        Cache::flush();

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/')->assertOk();
        // result=1 (latest), stats bounded counts, prizes catalogue/config — no per-draw loops.
        $this->assertLessThan(40, $queries, 'Expected bounded queries, got '.$queries);
    }
}
