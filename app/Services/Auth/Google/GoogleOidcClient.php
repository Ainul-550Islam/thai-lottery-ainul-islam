<?php

declare(strict_types=1);

namespace App\Services\Auth\Google;

use Firebase\JWT\JWT;
use Google\Auth\AccessToken;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google OpenID Connect, authorization-code flow + PKCE, server side.
 *
 * Not `final` only so tests can mock it. No Google JavaScript is loaded, so the CSP needs
 * no script-src change.
 *
 * Defences:
 *  - CSRF / login-CSRF : random `state` bound to THIS session, compared with hash_equals, single use, 10 min TTL.
 *  - Replay            : random `nonce` bound to THIS session, compared with the id_token's `nonce` claim.
 *  - Code interception : PKCE S256 (`code_verifier` never leaves the server).
 *  - Forged identity   : the id_token signature is verified against Google's published keys by
 *                        google/auth, then iss / aud / exp are re-asserted here (belt and braces).
 *  - Unverified emails : `email_verified` must be true.
 */
class GoogleOidcClient
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SESSION_KEY = 'google_oidc';
    private const TTL_SECONDS = 600;

    /**
     * Clock-skew tolerance (seconds) for the id_token iat / nbf / exp checks. google/auth runs them
     * through firebase/php-jwt, whose default leeway is 0, so a server clock even 1 s behind Google's
     * rejects a token that was issued a moment ago (BeforeValidException).
     */
    private const CLOCK_SKEW_LEEWAY_SECONDS = 60;

    public function __construct(private readonly Http $http) {}

    public function enabled(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            $value = config('services.google.'.$key);
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return (bool) config('services.google.enabled', false);
    }

    public function authorizationUrl(Request $request): string
    {
        $state = Str::random(48);
        $nonce = Str::random(48);
        $verifier = $this->b64url(random_bytes(64));

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'expires_at' => time() + self::TTL_SECONDS,
        ]);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => (string) config('services.google.redirect'),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @throws GoogleSignInException */
    public function profileFromCallback(Request $request): GoogleProfile
    {
        // pull() => single use, even when validation below fails.
        $flow = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($flow) || (int) ($flow['expires_at'] ?? 0) < time()) {
            throw new GoogleSignInException('flow_missing_or_expired');
        }

        $state = $request->query('state');
        if (! is_string($state) || ! hash_equals((string) $flow['state'], $state)) {
            throw new GoogleSignInException('state_mismatch');
        }

        if ($request->query('error') !== null) {
            throw new GoogleSignInException('provider_denied');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            throw new GoogleSignInException('missing_code');
        }

        $clientId = (string) config('services.google.client_id');

        try {
            $response = $this->http->asForm()->connectTimeout(4)->timeout(8)->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => (string) config('services.google.client_secret'),
                'redirect_uri' => (string) config('services.google.redirect'),
                'code_verifier' => (string) $flow['verifier'],
            ]);
        } catch (Throwable) {
            throw new GoogleSignInException('token_endpoint_unreachable');
        }

        $idToken = $response->ok() ? $response->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            throw new GoogleSignInException('token_rejected');
        }

        // The leeway is raised only around this verify() call and always restored afterwards, so any
        // other firebase/php-jwt use in the same process keeps its own (stricter) setting.
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::CLOCK_SKEW_LEEWAY_SECONDS;

        try {
            $claims = (new AccessToken)->verify($idToken, ['audience' => $clientId, 'throwException' => true]);
        } catch (Throwable $e) {
            Log::error('google_idtoken_verify_failed', ['class' => $e::class, 'message' => $e->getMessage()]);
            $claims = false;
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (! is_array($claims)) {
            throw new GoogleSignInException('id_token_invalid');
        }

        return $this->profileFromClaims($claims, (string) $flow['nonce'], $clientId);
    }

    /**
     * @param  array<string, mixed>  $claims  already signature-verified
     *
     * @throws GoogleSignInException
     */
    private function profileFromClaims(array $claims, string $expectedNonce, string $clientId): GoogleProfile
    {
        if (! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new GoogleSignInException('bad_issuer');
        }

        $aud = $claims['aud'] ?? null;
        if (! (is_string($aud) ? $aud === $clientId : (is_array($aud) && in_array($clientId, $aud, true)))) {
            throw new GoogleSignInException('bad_audience');
        }

        if ((int) ($claims['exp'] ?? 0) <= time()) {
            throw new GoogleSignInException('expired');
        }

        $nonce = $claims['nonce'] ?? null;
        if (! is_string($nonce) || ! hash_equals($expectedNonce, $nonce)) {
            throw new GoogleSignInException('nonce_mismatch');
        }

        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 191) {
            throw new GoogleSignInException('bad_subject');
        }

        $email = is_string($claims['email'] ?? null) ? mb_strtolower(trim($claims['email'])) : '';
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleSignInException('bad_email');
        }

        // Google serialises this as a bool, but has emitted the string "true" historically.
        if (filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new GoogleSignInException('email_unverified');
        }

        $allowedHd = config('services.google.hosted_domain');
        if (is_string($allowedHd) && $allowedHd !== '' && ($claims['hd'] ?? null) !== $allowedHd) {
            throw new GoogleSignInException('wrong_hosted_domain');
        }

        return new GoogleProfile($sub, $email, true, $this->cleanName($claims['name'] ?? null, $email), $this->safePicture($claims['picture'] ?? null));
    }

    private function cleanName(mixed $raw, string $email): string
    {
        $name = is_string($raw) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw)) : '';

        return $name !== '' ? mb_substr($name, 0, 120) : mb_substr(explode('@', $email)[0], 0, 120);
    }

    /** Only an https Google-content avatar that fits users.avatar_url (varchar 255). */
    private function safePicture(mixed $raw): ?string
    {
        if (! is_string($raw) || strlen($raw) > 255) {
            return null;
        }

        $parts = parse_url($raw);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ! ($host === 'googleusercontent.com' || str_ends_with($host, '.googleusercontent.com'))) {
            return null;
        }

        return $raw;
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
