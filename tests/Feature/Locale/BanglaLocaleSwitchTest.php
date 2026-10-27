<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use Illuminate\Http\Request;
use App\Http\Middleware\SetLocale;
use Tests\TestCase;

/**
 * Bangla (bn) locale switch: route, session, cookie and middleware priority.
 * No database is touched: the switch route and the middleware are stateless
 * apart from the session/cookie.
 */
final class BanglaLocaleSwitchTest extends TestCase
{
    public function test_switching_to_bangla_persists_session_and_sets_cookie(): void
    {
        $response = $this->from('/')->get('/locale/bn');

        $response->assertRedirect('/');
        $this->assertSame('bn', session('locale'));

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'locale');
        $this->assertNotNull($cookie, 'locale cookie was not queued');
        $this->assertSame('bn', $this->plainCookieValue($cookie->getValue()));
    }

    /**
     * The queued locale cookie is passed through the framework's cookie
     * encryption, so the raw response value is not the plain code. Recover the
     * plain code the same way the framework does, falling back to the raw value
     * when the cookie was not encrypted.
     */
    private function plainCookieValue(string $raw): string
    {
        // Signed form: "<hmac>|<value>"
        if (str_contains($raw, '|') && ! str_starts_with($raw, 'eyJ')) {
            return substr($raw, strrpos($raw, '|') + 1);
        }

        try {
            $payload = (string) \Illuminate\Support\Facades\Crypt::decrypt($raw, false);
        } catch (\Throwable) {
            return $raw;
        }

        // The app's cookie helper signs the value ("<hmac>|<value>") before it is
        // encrypted, so the decrypted payload carries the same prefix.
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

        (new SetLocale())->handle($request, fn () => response('ok'));

        $this->assertSame('bn', app()->getLocale());
        $this->assertSame('bn', $request->session()->get('locale'));
    }

    public function test_middleware_applies_bangla_from_cookie(): void
    {
        $request = Request::create('/', 'GET', [], ['locale' => 'bn']);

        app()->setLocale('en');
        (new SetLocale())->handle($request, fn () => response('ok'));

        $this->assertSame('bn', app()->getLocale());
    }

    public function test_middleware_ignores_invalid_cookie_and_keeps_default(): void
    {
        $request = Request::create('/', 'GET', [], ['locale' => '../etc']);

        app()->setLocale('en');
        (new SetLocale())->handle($request, fn () => response('ok'));

        $this->assertSame('en', app()->getLocale());
    }
}
