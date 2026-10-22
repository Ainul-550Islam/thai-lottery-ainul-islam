<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bangla (bn) locale switch: GET & POST switch endpoints, session, cookie,
 * middleware priority, HTML lang="bn", global hreflang="en|th|bn|x-default",
 * and public route rendering across all trilingual surfaces (Audit Finding D8).
 */
final class BanglaLocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_to_bangla_persists_session_and_sets_cookie(): void
    {
        $response = $this->from('/')->get('/locale/bn');

        $response->assertRedirect('/');
        $this->assertSame('bn', session('locale'));

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'locale');
        $this->assertNotNull($cookie, 'locale cookie was not queued');
        $this->assertSame('bn', $this->plainCookieValue($cookie->getValue()));
    }

    public function test_post_locale_endpoint_switches_to_bangla_and_sets_cookie(): void
    {
        $response = $this->from('/about')->post('/locale', ['locale' => 'bn']);

        $response->assertRedirect('/about');
        $this->assertSame('bn', session('locale'));

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'locale');
        $this->assertNotNull($cookie, 'locale cookie was not queued on POST /locale');
        $this->assertSame('bn', $this->plainCookieValue($cookie->getValue()));
    }

    public function test_post_locale_endpoint_rejects_unsupported_locale(): void
    {
        $response = $this->from('/')->post('/locale', ['locale' => 'fr']);

        $response->assertSessionHasErrors('locale');
    }

    /**
     * Recover plain cookie value from Laravel's signed/encrypted response cookie.
     */
    private function plainCookieValue(string $raw): string
    {
        if (str_contains($raw, '|') && ! str_starts_with($raw, 'eyJ')) {
            return substr($raw, strrpos($raw, '|') + 1);
        }

        try {
            $payload = (string) Crypt::decrypt($raw, false);
        } catch (\Throwable) {
            return $raw;
        }

        return str_contains($payload, '|') ? substr($payload, strrpos($payload, '|') + 1) : $payload;
    }

    public function test_switch_route_still_accepts_english_and_thai(): void
    {
        $this->get('/locale/th');
        $this->assertSame('th', session('locale'));

        $this->get('/locale/en');
        $this->assertSame('en', session('locale'));
    }

    public function test_unsupported_locale_does_not_change_the_session(): void
    {
        $this->get('/locale/bn');
        $this->assertSame('bn', session('locale'));

        $response = $this->from('/')->get('/locale/fr');

        $response->assertRedirect('/');
        $this->assertSame('bn', session('locale'), 'an unsupported code overwrote the session locale');
    }

    public function test_unsupported_locale_sets_no_cookie(): void
    {
        $response = $this->from('/')->get('/locale/xx');

        $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('locale', $names);
    }

    public function test_middleware_applies_bangla_from_query_parameter(): void
    {
        $request = Request::create('/?lang=bn', 'GET');
        $request->setLaravelSession(app('session.store'));

        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertSame('bn', app()->getLocale());
        $this->assertSame('bn', $request->session()->get('locale'));
    }

    public function test_middleware_applies_bangla_from_cookie(): void
    {
        $request = Request::create('/', 'GET', [], ['locale' => 'bn']);

        app()->setLocale('en');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertSame('bn', app()->getLocale());
    }

    public function test_middleware_ignores_invalid_cookie_and_keeps_default(): void
    {
        $request = Request::create('/', 'GET', [], ['locale' => '../etc']);

        app()->setLocale('en');
        (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertSame('en', app()->getLocale());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicRouteProvider(): array
    {
        return [
            'home' => ['/'],
            'about' => ['/about'],
            'vision' => ['/vision'],
            'terms' => ['/terms'],
            'privacy' => ['/privacy'],
            'contact' => ['/contact'],
            'our_fees' => ['/our-fees'],
            'account_verification' => ['/account-verification'],
            'account_grade' => ['/account-grade'],
            'prize_verification' => ['/prize-verification'],
            'lotto_discount' => ['/lotto-discount'],
            'download' => ['/download'],
            'how_to_play' => ['/how-to-play'],
            'faq' => ['/faq'],
            'results' => ['/results'],
            'national_lottery' => ['/national-lottery'],
            'weekly_lottery' => ['/weekly-lottery'],
            'bingo_lottery' => ['/bingo-lottery'],
            'pcso_lottery' => ['/pcso-lottery'],
            'glo_l6' => ['/glo-l6'],
        ];
    }

    #[DataProvider('publicRouteProvider')]
    public function test_every_public_page_accepts_bangla_locale_and_emits_hreflang_alternates(string $path): void
    {
        $response = $this->get($path.'?lang=bn');
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('lang="bn"', $html, "{$path} failed to render lang=\"bn\"");
        $this->assertStringContainsString('hreflang="en"', $html, "{$path} missing hreflang=\"en\"");
        $this->assertStringContainsString('hreflang="th"', $html, "{$path} missing hreflang=\"th\"");
        $this->assertStringContainsString('hreflang="bn"', $html, "{$path} missing hreflang=\"bn\"");
        $this->assertStringContainsString('hreflang="x-default"', $html, "{$path} missing hreflang=\"x-default\"");
    }
}
