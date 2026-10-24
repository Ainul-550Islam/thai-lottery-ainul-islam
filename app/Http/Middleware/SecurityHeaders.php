<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('security.security_headers', []);
        $cspConfig = is_array($config['content_security_policy'] ?? null)
            ? $config['content_security_policy']
            : [];
        $cspEnabled = (bool) ($cspConfig['enabled'] ?? false);

        if ($cspEnabled) {
            // Generate before Blade renders, so @cspNonce and the response
            // policy share the same request-scoped nonce.
            Vite::useCspNonce();
        }

        $response = $next($request);

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

        // Preserve strict attachment sandbox CSP (`sandbox; default-src 'none'...`)
        // already applied by private download controllers instead of downgrading it.
        $existingCsp = trim((string) $response->headers->get('Content-Security-Policy', ''));
        if ($cspEnabled && $existingCsp === '') {
            $nonce = Vite::cspNonce();
            $policy = $this->buildContentSecurityPolicy($cspConfig, is_string($nonce) ? $nonce : null);

            if ($policy !== '') {
                $header = (bool) ($cspConfig['report_only'] ?? false)
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy';

                $response->headers->set($header, $policy);
            }
        }

        return $response;
    }

    /** @param array<string, mixed> $config */
    private function buildContentSecurityPolicy(array $config, ?string $nonce): string
    {
        $directives = is_array($config['directives'] ?? null) ? $config['directives'] : [];
        $nonceDirectives = is_array($config['nonce_directives'] ?? null)
            ? $config['nonce_directives']
            : [];
        $nonceDirectives = array_fill_keys(array_filter(
            array_map(static fn (mixed $directive): string => is_string($directive) ? strtolower(trim($directive)) : '', $nonceDirectives),
            static fn (string $directive): bool => preg_match('/^[a-z][a-z0-9-]*$/D', $directive) === 1,
        ), true);

        $parts = [];

        foreach ($directives as $name => $sources) {
            if (! is_string($name)) {
                continue;
            }

            $name = strtolower(trim($name));

            if (preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
                continue;
            }

            if (! is_array($sources)) {
                continue;
            }

            $values = [];

            foreach ($sources as $source) {
                if (! is_string($source)) {
                    continue;
                }

                $source = trim($source);

                if ($source === '' || preg_match('/[;\r\n]/', $source) === 1) {
                    continue;
                }

                $values[] = $source;
            }

            if (isset($nonceDirectives[$name]) && $nonce !== null && $nonce !== '') {
                $values[] = "'nonce-{$nonce}'";
            }

            $values = array_values(array_unique($values));

            if ($values === []) {
                continue;
            }

            $parts[] = $name.' '.implode(' ', $values);
        }

        return implode('; ', $parts);
    }
}
