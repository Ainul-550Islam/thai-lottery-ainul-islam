<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SecurityTxtController;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * RFC 9116 contract for GET /.well-known/security.txt.
 *
 * Like the static contract tests, this needs no database, session or
 * middleware: it calls the invokable controller directly and drives it
 * through config().
 */
final class SecurityTxtTest extends TestCase
{
    public function test_route_is_registered_at_the_well_known_path(): void
    {
        self::assertSame('/.well-known/security.txt', route('security.txt', [], false));

        $route = $this->app['router']->getRoutes()->getByName('security.txt');

        self::assertNotNull($route);
        self::assertStringStartsWith(SecurityTxtController::class, $route->getActionName());
    }

    public function test_nothing_is_published_without_a_valid_contact(): void
    {
        $invalid = [
            null,
            '',
            '   ',
            'security@example.com',                                   // no scheme
            'http://example.com/report',                              // not https
            'mailto:',
            'mailto:no-at-sign.example.com',
            'mailto:with space@example.com',
            "mailto:a@example.com\nContact: attacker@evil.test",       // header/line injection
            'tel:call-me',
            'javascript:alert(1)',
            'ftp://example.com',
        ];

        foreach ($invalid as $contact) {
            self::assertNull($this->publish($contact), 'Must 404 for contact: '.var_export($contact, true));
        }
    }

    public function test_valid_contacts_are_published_as_plain_text(): void
    {
        foreach (['mailto:security@example.com', 'https://example.com/security-contact', 'tel:+66812345678'] as $contact) {
            $response = $this->publish($contact);

            self::assertInstanceOf(Response::class, $response, $contact);
            self::assertSame(200, $response->getStatusCode(), $contact);
            self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'), $contact);
            self::assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));

            $lines = $this->lines($response);
            self::assertSame('Contact: '.$contact, $lines[0]);
            self::assertContains('Preferred-Languages: en, th', $lines);
        }
    }

    public function test_body_is_lf_terminated_without_carriage_returns(): void
    {
        $body = (string) $this->publish('mailto:security@example.com')?->getContent();

        self::assertStringEndsWith("\n", $body);
        self::assertStringNotContainsString("\r", $body);
    }

    public function test_expires_is_a_utc_timestamp_about_180_days_out(): void
    {
        $lines = $this->lines($this->publish('mailto:security@example.com'));

        $expires = null;
        foreach ($lines as $line) {
            if (preg_match('/^Expires: (\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)$/', $line, $m) === 1) {
                $expires = Carbon::parse($m[1]);
            }
        }

        self::assertNotNull($expires, 'Expires line missing or not in RFC 3339 UTC form.');
        self::assertTrue($expires->between(Carbon::now('UTC')->addDays(179), Carbon::now('UTC')->addDays(181)));
    }

    public function test_canonical_uses_app_url_without_double_slash(): void
    {
        config(['app.url' => 'https://example.test/']);

        self::assertContains(
            'Canonical: https://example.test/.well-known/security.txt',
            $this->lines($this->publish('mailto:security@example.com')),
        );
    }

    public function test_policy_is_only_published_for_a_clean_https_url(): void
    {
        $ok = $this->lines($this->publish('mailto:security@example.com', 'https://example.com/disclosure'));
        self::assertContains('Policy: https://example.com/disclosure', $ok);

        foreach ([null, '', '   ', 'http://example.com/disclosure', "https://example.com/p\nContact: evil@x.test", 'https://exa mple.com'] as $policy) {
            $body = (string) $this->publish('mailto:security@example.com', $policy)?->getContent();
            self::assertStringNotContainsString('Policy:', $body, 'Policy must be omitted for: '.var_export($policy, true));
            self::assertStringNotContainsString('evil@x.test', $body);
        }
    }

    private function publish(mixed $contact, mixed $policy = null): ?Response
    {
        config([
            'security.security_txt.contact' => $contact,
            'security.security_txt.policy' => $policy,
        ]);

        try {
            return $this->app->make(SecurityTxtController::class)();
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    /** @return list<string> */
    private function lines(?Response $response): array
    {
        self::assertNotNull($response, 'Expected the controller to publish, got a 404.');

        return explode("\n", rtrim((string) $response->getContent(), "\n"));
    }
}
