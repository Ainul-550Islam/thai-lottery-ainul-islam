<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_applied(): void
    {
        $response = $this->get('/up');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_content_security_policy_is_configured_and_nonce_bound(): void
    {
        $response = $this->get('/up');
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9]+'/", $policy);
        $this->assertMatchesRegularExpression("/style-src[^;]*'nonce-[A-Za-z0-9]+'/", $policy);
        $this->assertSame(1, preg_match("/(?:^|; )script-src ([^;]*)/", $policy, $scriptDirective));
        $this->assertSame(1, preg_match("/(?:^|; )style-src ([^;]*)/", $policy, $styleDirective));
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptDirective[1]);
        $this->assertStringNotContainsString("'unsafe-inline'", $styleDirective[1]);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $policy);
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_rendered_inline_style_nonce_matches_the_enforced_policy(): void
    {
        $response = $this->get(route('login'))->assertOk();
        $html = (string) $response->getContent();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match('/<style\b[^>]*\bnonce="([^"]+)"/i', $html, $matches));
        $this->assertStringContainsString("'nonce-{$matches[1]}'", $policy);
    }

    public function test_csp_directives_and_report_only_mode_are_configurable(): void
    {
        config()->set('security.security_headers.content_security_policy.report_only', true);
        config()->set(
            'security.security_headers.content_security_policy.directives.default-src',
            ["'none'"],
        );

        $response = $this->get('/up');
        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9]+'/", $policy);
        $this->assertMatchesRegularExpression("/style-src[^;]*'nonce-[A-Za-z0-9]+'/", $policy);
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_csp_can_be_disabled_without_disabling_other_security_headers(): void
    {
        config()->set('security.security_headers.content_security_policy.enabled', false);

        $response = $this->get('/up');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
