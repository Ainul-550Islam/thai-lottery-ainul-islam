<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the response headers that a browser needs in order to treat this
 * application as a hardened origin: framing, sniffing, referrer, permissions,
 * HSTS and the Content Security Policy.
 *
 * Registered globally in bootstrap/app.php, so it applies to web, api and the
 * framework's own /up health route alike.
 */
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
     * Build the policy from config/security.php and attach it.
     *
     * Nothing about the policy is hardcoded here: the directive list lives in
     * config so it can be cached, inspected and overridden per environment, and
     * ContentSecurityPolicyTest asserts that what goes out on the wire matches
     * what the view tree actually needs.
     */
    private function applyContentSecurityPolicy(Response $response): void
    {
        $csp = (array) config('security.content_security_policy', []);

        if (! ($csp['enabled'] ?? true)) {
            return;
        }

        $directives = $this->directives($csp);

        if ($directives === []) {
            return;
        }

        $policy = $this->compose($directives, isset($csp['report_uri']) ? (string) $csp['report_uri'] : null);

        $response->headers->set(
            ! empty($csp['report_only']) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
            $policy,
        );
    }

    /**
     * @param  array<string, mixed>  $csp
     * @return array<string, list<string>>
     */
    private function directives(array $csp): array
    {
        $directives = [];

        foreach ((array) ($csp['directives'] ?? []) as $name => $sources) {
            $sources = is_array($sources) ? array_values($sources) : [(string) $sources];
            $sources = array_values(array_filter(array_map('strval', $sources), static fn (string $s): bool => $s !== ''));

            if ($sources !== []) {
                $directives[(string) $name] = $sources;
            }
        }

        $this->allowViteDevServer($directives);

        if (! empty($csp['upgrade_insecure_requests']) || app()->isProduction()) {
            // A valueless directive. Represented as an empty list so compose() emits
            // the name on its own rather than skipping it.
            $directives['upgrade-insecure-requests'] = [];
        }

        return $directives;
    }

    /**
     * Widen the policy for the Vite dev server while one is running.
     *
     * When public/hot exists, @vite() emits absolute URLs pointing at the dev
     * server instead of hashed /build/assets paths, and HMR opens a websocket back
     * to it. Under script-src 'self' both would be blocked and `npm run dev` would
     * serve an unstyled, unscripted page - which is how a CSP ends up disabled in
     * development and then shipped untested. The file is written by `npm run dev`
     * and removed when it stops, so this branch is never taken in production.
     *
     * @param  array<string, list<string>>  $directives
     */
    private function allowViteDevServer(array &$directives): void
    {
        $hot = public_path('hot');

        if (! is_file($hot)) {
            return;
        }

        $origin = trim((string) file_get_contents($hot));

        if ($origin === '' || ! preg_match('#^https?://#', $origin)) {
            return;
        }

        $origin = rtrim($origin, '/');

        foreach (['script-src', 'style-src', 'img-src', 'connect-src', 'font-src', 'media-src'] as $name) {
            if (! isset($directives[$name])) {
                continue;
            }

            $directives[$name][] = $origin;

            if ($name === 'connect-src') {
                // HMR uses a websocket to the same host. Deriving it from the origin
                // rather than allowing ws: wholesale keeps the policy tied to the dev
                // server that is actually running.
                $directives[$name][] = preg_replace('#^http#', 'ws', $origin);
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $directives
     */
    private function compose(array $directives, ?string $reportUri): string
    {
        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = $sources === [] ? $name : $name.' '.implode(' ', $sources);
        }

        if ($reportUri !== null && $reportUri !== '') {
            $parts[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $parts);
    }
}
