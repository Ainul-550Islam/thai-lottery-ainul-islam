<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Response security headers, on every surface.
 *
 * ============================================================================
 * WHY A POLICY OBJECT AND NOT A MIDDLEWARE FULL OF STRINGS
 * ============================================================================
 * The CSP has to be consistent with what the pages actually load: a nonce for the
 * Vite assets, nothing inline anywhere else, no external script or style origins
 * except the ones the product genuinely uses, and 'frame-ancestors' that cannot
 * be widened by a page that forgets a directive. Encoding that in one policy
 * class means the operator page, the public pages and the API error responses
 * cannot drift apart.
 *
 * The second half of the suite is about the OPPOSITE failure: headers that
 * reassure without protecting. A CSP containing 'unsafe-inline' on script-src is
 * decoration. A HSTS header on a plain-HTTP request is wrong. An X-Robots-Tag on
 * a public results page hides a page that is supposed to be indexed.
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_the_public_homepage_carries_the_baseline_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $expectedHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            // Presence-only assertions carry an empty expectation; the value must
            // be a sane non-empty policy but its exact wording is the policy
            // object's business.
            'Referrer-Policy' => '',
            'Permissions-Policy' => '',
            'Cross-Origin-Opener-Policy' => '',
        ];

        foreach ($expectedHeaders as $header => $expected) {
            $this->assertTrue($response->headers->has($header), 'missing header: '.$header);

            if ($expected !== '') {
                $this->assertSame($expected, $response->headers->get($header), $header.' has the wrong value');
            } else {
                $this->assertNotSame('', (string) $response->headers->get($header), $header.' is empty');
            }
        }
    }

    public function test_a_csp_is_sent_and_does_not_allow_unsafe_inline_script(): void
    {
        $response = $this->get('/');

        $csp = (string) ($response->headers->get('Content-Security-Policy')
            ?? $response->headers->get('Content-Security-Policy-Report-Only'));

        $this->assertNotSame('', $csp, 'no CSP was sent at all');

        // Parse the script-src directive specifically: an 'unsafe-inline' that
        // appears only in style-src is a different (and lesser) question.
        if (preg_match('/script-src([^;]*)/', $csp, $matches) === 1) {
            $this->assertStringNotContainsString(
                "'unsafe-inline'",
                $matches[1],
                'script-src may not allow inline script; that is what the nonce is for',
            );

            $this->assertStringNotContainsString(
                "'unsafe-eval'",
                $matches[1],
                'script-src may not allow eval',
            );
        }

        $this->assertStringContainsString("frame-ancestors 'none'", $csp, 'the pages may not be framed');
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_private_guest_pages_are_marked_noindex(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $path) {
            $response = $this->get($path);

            $this->assertTrue(
                in_array($response->getStatusCode(), [200, 302], true),
                $path.' answered '.$response->getStatusCode(),
            );

            $robots = (string) $response->headers->get('X-Robots-Tag');

            $this->assertNotSame('', $robots, $path.' must not be indexed');
            $this->assertStringContainsString('noindex', strtolower($robots), $path.' must carry noindex');
        }
    }

    public function test_a_public_page_is_not_marked_noindex(): void
    {
        $response = $this->get('/results');

        // A results page that answers anything but 200 is a product failure, not
        // an empty database state to be excused: the page has a defined answer
        // for "no draws yet" and it is an empty list, not an error. Asserting the
        // status here is what stops this suite from passing while the public
        // results surface is unreachable.
        $response->assertOk();

        $robots = strtolower((string) $response->headers->get('X-Robots-Tag'));

        $this->assertStringNotContainsString(
            'noindex',
            $robots,
            'published results are meant to be indexed; hiding them from search is a product decision, not a default',
        );
    }

    public function test_hsts_is_only_sent_over_a_secure_connection(): void
    {
        $insecure = $this->get('http://localhost/');
        $this->assertFalse(
            $insecure->headers->has('Strict-Transport-Security'),
            'HSTS over plain HTTP is meaningless at best and harmful behind a broken proxy',
        );

        $secure = $this->get('https://localhost/');
        $strict = (string) $secure->headers->get('Strict-Transport-Security');

        $this->assertNotSame('', $strict, 'a secure request must advertise HSTS');
        $this->assertStringContainsString('max-age=', $strict);
        $this->assertStringContainsString('includeSubDomains', $strict);
    }

    public function test_the_policy_preserves_a_stricter_policy_set_by_the_application(): void
    {
        // A route group may narrow the policy (for example a download endpoint
        // that must serve a file and nothing else). The middleware must not
        // overwrite a stricter header with the default one.
        Route::get('/__test/stricter-policy', static function () {
            return response('ok')->header(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'",
            );
        })->middleware('web');

        $response = $this->get('/__test/stricter-policy');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp, 'the stricter policy was overwritten');
    }

    public function test_the_policy_class_reports_the_same_values_the_middleware_sends(): void
    {
        $policy = $this->app->make(\App\Services\Security\SecurityHeaderPolicy::class);
        $headers = $policy->headers();

        $this->assertIsArray($headers);
        $this->assertNotSame([], $headers);

        $response = $this->get('/');

        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Content-Security-Policy') === 0) {
                // The middleware composes the CSP per request because the nonce
                // changes; the class supplies the directives, not the nonce.
                continue;
            }

            $this->assertSame(
                $value,
                $response->headers->get($name),
                $name.' differs between the policy object and the response',
            );
        }
    }
}
