<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LEGACY URL COMPATIBILITY LAYER (audit findings 1-4, 8, 12-15).
 *
 * The replaced site published .php URLs. Every documented one must answer
 * 301 to its modern equivalent, every unknown .php path must keep the
 * framework 404, and no real route may be shadowed by the bridge.
 */
class LegacyRedirectTest extends TestCase
{
    use RefreshDatabase;

    public static function staticPageProvider(): array
    {
        return [
            'about' => ['/about.php', '/about'],
            'vision' => ['/vision.php', '/vision'],
            'terms' => ['/terms.php', '/terms'],
            'fees' => ['/fees.php', '/fees'],
            'contact' => ['/contact.php', '/contact'],
            'national lane' => ['/national-lottery.php', '/national-lottery'],
            'weekly lane' => ['/weekly-lottery.php', '/weekly-lottery'],
            'mega lane' => ['/bingo-lottery.php', '/bingo-lottery'],
            'pcso lane' => ['/pcso-lottery.php', '/pcso-lottery'],
            'account verify' => ['/account-verify.php', '/account-verification-guide'],
            'account grade' => ['/account-grade.php', '/account-grades'],
            'prize verification' => ['/prize-verification.php', '/prize-verification'],
            'lotto discount' => ['/lotto-discount.php', '/discounts'],
            'login' => ['/login.php', '/login'],
            'register' => ['/register.php', '/register'],
            'forgot password' => ['/forgot_password.php', '/forgot-password'],
            // Second audit pass: the bare index URL and both observed
            // spellings of the prize-verification and discount pages.
            'index' => ['/index.php', '/'],
            'prize verify short' => ['/prize-verify.php', '/prize-verification'],
            'discount short' => ['/discount.php', '/discounts'],
        ];
    }

    #[DataProvider('staticPageProvider')]
    public function test_static_legacy_urls_redirect_permanently(string $legacy, string $modern): void
    {
        $this->get($legacy)
            ->assertStatus(301)
            ->assertRedirect($modern);
    }

    public static function archivePageProvider(): array
    {
        return [
            // The "lottoery" typo form the replaced site published for
            // National/Weekly archives (2564-2568) — indexed by crawlers.
            'national typo archive' => ['/national-lottoery-2568.php', '/national-lottery/year/2568'],
            'national typo archive older' => ['/national-lottoery-2565.php', '/national-lottery/year/2565'],
            'weekly typo archive' => ['/weekly-lottoery-2567.php', '/weekly-lottery/year/2567'],
            // Correctly spelled Bingo/PCSO year archives.
            'bingo year archive' => ['/bingo-lottery-2566.php', '/bingo-lottery/year/2566'],
            'pcso year archive' => ['/pcso-lottery-2565.php', '/pcso-lottery/year/2565'],
            // The numbered archive pages for the oldest years — FINAL
            // audit §9: every lane, every suffix, no exceptions.
            'national numbered 2563' => ['/national-lottery1.php', '/national-lottery/year/2563'],
            'national numbered 2562' => ['/national-lottery2.php', '/national-lottery/year/2562'],
            'national numbered 2561' => ['/national-lottery3.php', '/national-lottery/year/2561'],
            'weekly numbered 2563' => ['/weekly-lottery1.php', '/weekly-lottery/year/2563'],
            'weekly numbered 2562' => ['/weekly-lottery2.php', '/weekly-lottery/year/2562'],
            'weekly numbered 2561' => ['/weekly-lottery3.php', '/weekly-lottery/year/2561'],
            'bingo numbered 2563' => ['/bingo-lottery1.php', '/bingo-lottery/year/2563'],
            'bingo numbered 2562' => ['/bingo-lottery2.php', '/bingo-lottery/year/2562'],
            'bingo numbered 2561' => ['/bingo-lottery3.php', '/bingo-lottery/year/2561'],
            'pcso numbered 2563' => ['/pcso-lottery1.php', '/pcso-lottery/year/2563'],
            'pcso numbered 2562' => ['/pcso-lottery2.php', '/pcso-lottery/year/2562'],
            'pcso numbered 2561' => ['/pcso-lottery3.php', '/pcso-lottery/year/2561'],
            // Typo-form archives, remaining indexed years.
            'national typo 2564' => ['/national-lottoery-2564.php', '/national-lottery/year/2564'],
            'national typo 2566' => ['/national-lottoery-2566.php', '/national-lottery/year/2566'],
            'national typo 2567' => ['/national-lottoery-2567.php', '/national-lottery/year/2567'],
            'weekly typo 2564' => ['/weekly-lottoery-2564.php', '/weekly-lottery/year/2564'],
            'weekly typo 2565' => ['/weekly-lottoery-2565.php', '/weekly-lottery/year/2565'],
            'weekly typo 2566' => ['/weekly-lottoery-2566.php', '/weekly-lottery/year/2566'],
            'weekly typo 2568' => ['/weekly-lottoery-2568.php', '/weekly-lottery/year/2568'],
            // Correctly-spelled archives, more years.
            'bingo archive 2565' => ['/bingo-lottery-2565.php', '/bingo-lottery/year/2565'],
            'bingo archive 2567' => ['/bingo-lottery-2567.php', '/bingo-lottery/year/2567'],
            'bingo archive 2568' => ['/bingo-lottery-2568.php', '/bingo-lottery/year/2568'],
            'pcso archive 2566' => ['/pcso-lottery-2566.php', '/pcso-lottery/year/2566'],
            'pcso archive 2567' => ['/pcso-lottery-2567.php', '/pcso-lottery/year/2567'],
            'pcso archive 2568' => ['/pcso-lottery-2568.php', '/pcso-lottery/year/2568'],
        ];
    }

    #[DataProvider('archivePageProvider')]
    public function test_legacy_archive_urls_redirect_to_year_pages(string $legacy, string $modern): void
    {
        $this->get($legacy)
            ->assertStatus(301)
            ->assertRedirect($modern);
    }

    public static function brokenMemberUrlProvider(): array
    {
        return [
            'lottery' => ['/members/members/lottery.php', '/draws'],
            'lottery history' => ['/members/members/lottery_history.php', '/bets'],
            'cash to win' => ['/members/members/cash_to_win.php', '/deposit'],
            'win to cash' => ['/members/members/win_to_cash.php', '/withdraw'],
            // Second audit pass: the remaining dead member links.
            'profile' => ['/members/members/profile.php', '/profile'],
            'profile password' => ['/members/members/profile-password.php', '/profile'],
            // Legacy GET logout maps to home, never to the logout action:
            // modern logout is a CSRF-protected POST, and a redirected GET
            // must never be able to log anybody out.
            'logout' => ['/members/members/logout.php', '/'],
        ];
    }

    #[DataProvider('brokenMemberUrlProvider')]
    public function test_broken_double_path_member_urls_redirect_to_modern_pages(string $legacy, string $modern): void
    {
        // These 404'd on the replaced site's own server. The bridge answers
        // 301 first; the auth middleware then handles the signed-out case,
        // exactly as a direct visit to the modern page would.
        $this->get($legacy)
            ->assertStatus(301)
            ->assertRedirect($modern);
    }

    public function test_unknown_php_path_is_still_a_404(): void
    {
        $this->get('/this-never-existed.php')->assertNotFound();
        $this->get('/about.php/extra/deeper.php')->assertNotFound();
    }

    public function test_real_routes_are_not_shadowed_by_the_bridge(): void
    {
        // Same path shape as a legacy URL minus the extension: the bridge
        // must never claim a path a real route answers.
        $this->get('/about')->assertOk();
        $this->get('/fees')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_legacy_form_post_redirects_instead_of_405(): void
    {
        // Old forms POSTed to .php actions; the bridge must not answer 405.
        $this->post('/login.php', ['username' => 'x'])
            ->assertStatus(301)
            ->assertRedirect('/login');
    }

    public function test_numbered_archive_suffixes_out_of_range_are_404s(): void
    {
        // The bridge is a closed map, not a pattern that guesses: only the
        // documented suffixes 1/2/3 exist.
        $this->get('/national-lottery4.php')->assertNotFound();
        $this->get('/national-lottery0.php')->assertNotFound();
        $this->get('/bingo-lottery9.php')->assertNotFound();
    }

    public function test_the_legacy_bridge_never_captures_payment_callback_routes(): void
    {
        // FINAL audit §9: the browser payment-return routes must answer as
        // themselves (auth redirect for guests), never fall through to the
        // legacy resolver and its 404.
        $response = $this->get('/payment/success');

        $this->assertNotSame(404, $response->status());
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
    }

    public function test_legacy_member_logout_get_redirects_home_without_logging_anyone_out(): void
    {
        // A GET may never perform the logout mutation; the legacy path just
        // lands on home.
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->get('/members/members/logout.php')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_information_pages_are_reachable_from_the_guest_nav(): void
    {
        // Audit finding 8: the replaced site's nav exposed the information
        // pages as first-class destinations; ours must too.
        $page = (string) $this->get('/')->getContent();

        $this->assertStringContainsString(route('about'), $page);
        $this->assertStringContainsString(route('vision'), $page);
        $this->assertStringContainsString(route('terms'), $page);
        $this->assertStringContainsString(route('fees'), $page);
        $this->assertStringContainsString(route('account-verification-guide'), $page);
        $this->assertStringContainsString(route('account-grades'), $page);
        $this->assertStringContainsString(route('prize-verification'), $page);
        $this->assertStringContainsString(route('discounts'), $page);
    }
}
