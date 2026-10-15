<?php

namespace App\Http\Middleware;

use App\Services\Security\CspNonceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $config = (array) config('security.security_headers', []);

        $headers = array_filter([
            'X-Frame-Options' => $config['x_frame_options'] ?? null,
            'X-Content-Type-Options' => $config['x_content_type_options'] ?? null,
            'X-XSS-Protection' => $config['x_xss_protection'] ?? null,
            'Referrer-Policy' => $config['referrer_policy'] ?? null,
            'Permissions-Policy' => $config['permissions_policy'] ?? null,
        ]);

        foreach ($headers as $header => $value) {
            $response->headers->set($header, (string) $value);
        }

        if ($request->isSecure() && ! empty($config['hsts_max_age'])) {
            $hsts = 'max-age='.(int) $config['hsts_max_age'];

            if (! empty($config['hsts_include_subdomains'])) {
                $hsts .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        $this->applyContentSecurityPolicy($response);

        return $response;
    }

    /**
     * Part 63 — Content-Security-Policy, added to the header set this
     * middleware already owned rather than as a second middleware that would
     * have to be ordered against it.
     *
     * WHY IT SHIPS IN REPORT-ONLY
     * Every other header here is inert: a browser either honours it or ignores
     * it, and nothing the application renders changes. A CSP in `enforce` mode
     * is different — it can break a page that currently works, including the
     * Livewire-driven admin panel, which injects inline scripts. So the default
     * is `report-only`: the browser evaluates the policy and reports violations
     * without blocking anything. Reading those reports is what tells an
     * operator which directives are still wrong; flipping to `enforce` before
     * that is how a CSP turns into an outage.
     *
     * `off` sends no CSP header at all, which is the pre-Part-63 behaviour.
     */
    private function applyContentSecurityPolicy(Response $response): void
    {
        $csp = (array) config('security.csp', []);
        $mode = (string) ($csp['mode'] ?? 'off');

        if ($mode === 'off') {
            return;
        }

        $policy = $this->buildPolicy($csp);

        if ($policy === '') {
            return;
        }

        $header = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $policy);
    }

    /**
     * Append the request nonce to the two directives that gate inline content.
     *
     * `$directives` is modified in place because the policy is assembled in one
     * pass below, and building it twice to accommodate the nonce would leave two
     * places that have to agree on directive order.
     *
     * 'unsafe-inline' is only removed when the operator asks for it. Browsers
     * that support nonces ignore 'unsafe-inline' when a nonce is present, so
     * keeping it is not a hole in those browsers; removing it before the markup
     * carries the nonce, on the other hand, refuses every inline block on the
     * page — including the ones Livewire writes.
     *
     * @param  array<string, mixed>  $directives
     * @param  array<string, mixed>  $nonceConfig
     */
    private function applyNonce(array &$directives, array $nonceConfig): void
    {
        if (! (bool) ($nonceConfig['enabled'] ?? false)) {
            return;
        }

        $nonce = app(CspNonceService::class);
        $expression = $nonce->sourceExpression();

        if ($expression === '') {
            return;
        }

        foreach (['script-src', 'style-src'] as $directive) {
            $sources = array_values(array_map('strval', (array) ($directives[$directive] ?? [])));

            if (! empty($nonceConfig['drop_unsafe_inline'])) {
                $sources = array_values(array_filter(
                    $sources,
                    static fn (string $source): bool => $source !== "'unsafe-inline'",
                ));
            }

            $sources[] = $expression;

            $directives[$directive] = array_values(array_unique($sources));
        }
    }

    /**
     * @param  array<string, mixed>  $csp
     */
    private function buildPolicy(array $csp): string
    {
        $directives = (array) ($csp['directives'] ?? []);

        $this->applyNonce($directives, (array) ($csp['nonce'] ?? []));

        $parts = [];

        foreach ($directives as $name => $sources) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $values = is_array($sources) ? array_values(array_filter(array_map('strval', $sources))) : [];

            $parts[] = $values === [] ? $name : $name.' '.implode(' ', $values);
        }

        $reportUri = $csp['report_uri'] ?? null;

        if (is_string($reportUri) && $reportUri !== '') {
            $parts[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $parts);
    }
}
