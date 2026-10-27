<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * The one place that decides which security headers a response carries.
 *
 * WHY A POLICY OBJECT RATHER THAN A BIGGER MIDDLEWARE
 * The middleware used to hold the whole policy inline: which headers exist, how
 * the CSP string is assembled, which pages are unindexable, when HSTS applies.
 * That made it impossible to test the policy without a request/response round
 * trip, and impossible to reuse from the PDF and API surfaces, which build their
 * own responses. It also meant the private-page list lived next to the
 * directive sanitiser, so a change to one was reviewed as if it were a change to
 * the other.
 *
 * This class answers three questions and nothing else:
 *
 *   headers()      the flat header map for an ordinary HTML page
 *   csp()          the Content-Security-Policy string, or '' when disabled
 *   isPrivate()    whether this request must not be indexed
 *
 * NO BEHAVIOUR IS INVENTED HERE. Every value is read from
 * config('security.security_headers') exactly as before; the sanitiser rejects
 * a directive name that is not a bare token and a source that contains ';' or a
 * newline, so a compromised config file cannot inject a second directive or
 * split the header.
 *
 * THE NONCE IS PASSED IN, NEVER GENERATED HERE. Vite must hand the same nonce to
 * the Blade tags and to the header, so the caller that calls
 * `Vite::useCspNonce()` owns it. A policy object that generated its own nonce
 * would produce a header no script could match.
 */
final class SecurityHeaderPolicy
{
    /**
     * Guest-facing paths that must never be indexed.
     *
     * An explicit list rather than a pattern: `/login` is public, and a
     * `/login-as-admin` lookalike would be private; guessing from a prefix is how
     * a private page ends up crawlable.
     *
     * @var list<string>
     */
    public const PRIVATE_GUEST_PATHS = [
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'reset-password/*',
        'legal/consent',
        'account/age-verification',
    ];

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Whether the CSP lane is switched on at all.
     */
    public function cspEnabled(): bool
    {
        return (bool) $this->block()['enabled'];
    }

    /**
     * Whether the CSP must be emitted as a report-only header.
     */
    public function cspReportOnly(): bool
    {
        return (bool) ($this->block()['report_only'] ?? false);
    }

    /**
     * The ordinary header map. Values are cast to string and empty values are
     * dropped, so an unset key means "no header" rather than "empty header",
     * which some proxies rewrite.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $raw = [
            'X-Frame-Options' => $this->config->get('security.security_headers.x_frame_options'),
            'X-Content-Type-Options' => $this->config->get('security.security_headers.x_content_type_options'),
            'X-XSS-Protection' => $this->config->get('security.security_headers.x_xss_protection'),
            'Referrer-Policy' => $this->referrerPolicy(),
            'Permissions-Policy' => $this->permissionsPolicy(),
            /*
             * Cross-Origin-Opener-Policy.
             *
             * Values, and why this one:
             *   same-origin             the strongest, and wrong here: it severs
             *                           window.opener for EVERY popup, including a
             *                           3-D Secure / bank verification window that
             *                           some payment flows open and then post back
             *                           into. Severing that link strands the
             *                           member mid-payment.
             *   same-origin-allow-popups the page is isolated from cross-origin
             *                           openers, but popups it opens itself keep
             *                           their handle. That closes the
             *                           window.opener-based attack surface (a
             *                           framed page reaching back into this
             *                           origin) without breaking a payment
             *                           hand-off.
             *
             * 'unsafe-none' is the absence of the header, which is what this
             * product shipped before: no isolation at all.
             */
            'Cross-Origin-Opener-Policy' => $this->config->get(
                'security.security_headers.cross_origin_opener_policy',
                'same-origin-allow-popups',
            ),
        ];

        $headers = [];

        foreach ($raw as $name => $value) {
            if (! is_string($value) && ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            // A header value can never contain a CR/LF; refuse rather than
            // strip, because a silently altered policy is not the configured one.
            if (strpbrk($value, "\r\n") !== false) {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * The Referrer-Policy. Kept as its own accessor because it is the header most
     * often overridden per surface (a payment redirect needs a different value
     * from a results page) and it should be overridable without rebuilding the
     * whole map.
     */
    public function referrerPolicy(): string
    {
        $value = $this->config->get('security.security_headers.referrer_policy', 'strict-origin-when-cross-origin');

        return is_string($value) ? trim($value) : 'strict-origin-when-cross-origin';
    }

    /**
     * The Permissions-Policy value, assembled from the configured directives
     * when the config gives a structured array, or passed through when it is
     * already a string.
     */
    public function permissionsPolicy(): string
    {
        $configured = $this->config->get('security.security_headers.permissions_policy');

        if (is_string($configured)) {
            return trim($configured);
        }

        if (! is_array($configured)) {
            return '';
        }

        $parts = [];

        foreach ($configured as $feature => $allowlist) {
            if (! is_string($feature)) {
                continue;
            }

            $feature = trim($feature);

            if (preg_match('/^[a-z][a-z0-9-]*$/D', $feature) !== 1) {
                continue;
            }

            if (is_string($allowlist)) {
                $value = trim($allowlist);
            } elseif (is_bool($allowlist)) {
                $value = $allowlist ? '*' : '()';
            } elseif (is_array($allowlist)) {
                $origins = [];

                foreach ($allowlist as $origin) {
                    if (! is_string($origin)) {
                        continue;
                    }

                    $origin = trim($origin);

                    if ($origin === '' || preg_match('/[\s;,]/', $origin) === 1) {
                        continue;
                    }

                    $origins[] = $origin === 'self' ? "'self'" : ($origin === 'none' ? '' : $origin);
                }

                $origins = array_values(array_filter($origins, static fn (string $o): bool => $o !== ''));
                $value = '(' . implode(' ', $origins) . ')';
            } else {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $parts[] = $feature.'='.$value;
        }

        return implode(', ', $parts);
    }

    /**
     * Build the Content-Security-Policy string.
     *
     * @return string '' when the CSP lane is off or no directive survived validation
     */
    public function csp(?string $nonce): string
    {
        $block = $this->block();

        if (! (bool) ($block['enabled'] ?? false)) {
            return '';
        }

        $directives = is_array($block['directives'] ?? null) ? $block['directives'] : [];
        $nonceDirectives = is_array($block['nonce_directives'] ?? null) ? $block['nonce_directives'] : [];

        $nonceDirectives = array_fill_keys(array_filter(
            array_map(
                static fn (mixed $directive): string => is_string($directive) ? strtolower(trim($directive)) : '',
                $nonceDirectives,
            ),
            static fn (string $directive): bool => preg_match('/^[a-z][a-z0-9-]*$/D', $directive) === 1,
        ), true);

        $parts = [];

        foreach ($directives as $name => $sources) {
            if (! is_string($name) || ! is_array($sources)) {
                continue;
            }

            $name = strtolower(trim($name));

            if (preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
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

    /**
     * The header name the CSP is written to, honouring report-only.
     */
    public function cspHeaderName(): string
    {
        return $this->cspReportOnly()
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    /**
     * HSTS, or '' when the connection is not secure or no max-age is configured.
     */
    public function strictTransportSecurity(Request $request): string
    {
        $maxAge = $this->config->get('security.security_headers.hsts_max_age');

        if (! $request->isSecure() || empty($maxAge)) {
            return '';
        }

        $value = 'max-age='.(int) $maxAge;

        if (! empty($this->config->get('security.security_headers.hsts_include_subdomains'))) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }

    /**
     * Whether this request is a private (non-indexable) page.
     *
     * A route behind `auth` is private by definition, which covers the player
     * area and every admin/agent surface without keeping a second URL list that
     * could drift from routes/web.php.
     */
    public function isPrivate(Request $request): bool
    {
        if ($request->is(self::PRIVATE_GUEST_PATHS)) {
            return true;
        }

        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || $middleware === 'admin.auth') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function block(): array
    {
        $config = $this->config->get('security.security_headers', []);
        $config = is_array($config) ? $config : [];

        $csp = $config['content_security_policy'] ?? [];

        return is_array($csp) ? $csp : [];
    }
}
