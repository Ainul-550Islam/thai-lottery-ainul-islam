<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Social login via OAuth2 (Facebook, Twitter/X, Google+).
 *
 * No third-party SDK required: each provider's authorize + token +
 * userinfo endpoints are called directly with Guzzle (already a
 * project dependency). The controller never stores social tokens —
 * it only exchanges them for the provider's stable user ID, then
 * links or creates the local member account.
 */
final class SocialAuthController extends Controller
{
    /** Providers this application supports. */
    private const PROVIDERS = ['facebook', 'twitter', 'google'];

    /** OAuth2 authorize endpoint per provider. */
    private const AUTHORIZE_URL = [
        'facebook' => 'https://www.facebook.com/v18.0/dialog/oauth',
        'twitter'  => 'https://twitter.com/i/oauth2/authorize',
        'google'   => 'https://accounts.google.com/o/oauth2/v2/auth',
    ];

    /** OAuth2 token endpoint per provider. */
    private const TOKEN_URL = [
        'facebook' => 'https://graph.facebook.com/v18.0/oauth/access_token',
        'twitter'  => 'https://api.twitter.com/2/oauth2/token',
        'google'   => 'https://oauth2.googleapis.com/token',
    ];

    /** OAuth2 userinfo endpoint per provider. */
    private const USERINFO_URL = [
        'facebook' => 'https://graph.facebook.com/v18.0/me?fields=id,name,email,picture',
        'twitter'  => 'https://api.twitter.com/2/users/me?user.fields=profile_image_url,name',
        'google'   => 'https://www.googleapis.com/oauth2/v3/userinfo',
    ];

    /** Scopes requested per provider. */
    private const SCOPES = [
        'facebook' => ['email', 'public_profile'],
        'twitter'  => ['tweet.read', 'users.read'],
        'google'   => ['openid', 'email', 'profile'],
    ];

    /**
     * Redirect the user to the provider's OAuth2 authorization page.
     *
     * @throws ValidationException when the provider is not configured
     */
    public function redirectToProvider(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'provider' => __('public_pages.social_invalid_provider'),
            ]);
        }

        $config = $this->providerConfig($provider);

        $state = Str::random(32);
        $request = request();
        $request->session()->put('social_auth_state_'.$provider, $state);
        $request->session()->put('social_auth_provider', $provider);

        $params = http_build_query(array_filter([
            'client_id'     => $config['client_id'],
            'redirect_uri'  => route('social.callback', $provider, absolute: false),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES[$provider] ?? []),
            'state'         => $state,
            // Twitter/X requires this explicitly
            'code_challenge'       => null,
            'code_challenge_method' => null,
        ]));

        return redirect(self::AUTHORIZE_URL[$provider].'?'.$params);
    }

    /**
     * Handle the OAuth2 callback from the provider.
     *
     * @throws ValidationException on missing/mismatched state or token failure
     */
    public function handleProviderCallback(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'provider' => __('public_pages.social_invalid_provider'),
            ]);
        }

        $request = request();

        // CSRF / state verification
        $expectedState = $request->session()->pull('social_auth_state_'.$provider);
        $actualState   = $request->query('state');
        if ($expectedState === null || $expectedState !== $actualState) {
            throw ValidationException::withMessages([
                'state' => __('public_pages.social_invalid_state'),
            ]);
        }

        if ($request->has('error')) {
            throw ValidationException::withMessages([
                'provider' => $request->query('error_description') ?: __('public_pages.social_cancelled'),
            ]);
        }

        $code = $request->query('code');
        if (! $code) {
            throw ValidationException::withMessages([
                'code' => __('public_pages.social_missing_code'),
            ]);
        }

        $config = $this->providerConfig($provider);

        // Exchange code for access token
        $tokenParams = [
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code'          => $code,
            'redirect_uri'  => route('social.callback', $provider, absolute: false),
            'grant_type'    => 'authorization_code',
        ];

        $tokenResponse = $this->postJson(self::TOKEN_URL[$provider], $tokenParams, $provider === 'twitter');

        if (! isset($tokenResponse['access_token'])) {
            Log::error('social_token_error', [
                'provider' => $provider,
                'response' => $tokenResponse,
            ]);

            throw ValidationException::withMessages([
                'provider' => __('public_pages.social_token_failed'),
            ]);
        }

        $accessToken = $tokenResponse['access_token'];

        // Fetch user profile from provider
        $userInfo = $this->getJson(self::USERINFO_URL[$provider], $accessToken);

        $socialId = $this->extractSocialId($userInfo, $provider);
        $email    = $this->extractEmail($userInfo, $provider);
        $name     = $this->extractName($userInfo, $provider);
        $avatar   = $this->extractAvatar($userInfo, $provider);

        if (! $socialId) {
            throw ValidationException::withMessages([
                'provider' => __('public_pages.social_profile_failed'),
            ]);
        }

        // Clear session state
        $request->session()->forget('social_auth_state_'.$provider);
        $request->session()->forget('social_auth_provider');

        // Link or create account
        $user = User::query()->where('provider', $provider)
            ->where('provider_id', $socialId)
            ->first();

        if (! $user) {
            // No social binding yet — try matching by email so a member who
            // already registered with email+pwd can later log in via social.
            if ($email) {
                $user = User::query()->where('email', $email)->first();
                if ($user) {
                    // Bind the social identity to the existing local account
                    $user->provider = $provider;
                    $user->provider_id = $socialId;
                    if ($avatar && ! $user->avatar_url) {
                        $user->avatar_url = $avatar;
                    }
                    $user->save();
                }
            }

            if (! $user) {
                // Create brand-new member account from social profile
                $user = User::create([
                    'name'     => $name ?: __('public_pages.social_user'),
                    'email'    => $email ?: Str::random(8).'@social.'.$provider.'.local',
                    'username' => $this->deriveUsername($email ?: $socialId),
                    'phone'    => null,
                    'password' => Hash::make(Str::random(32)), // unusable password; social only
                    'provider' => $provider,
                    'provider_id'=> $socialId,
                    'avatar_url' => $avatar,
                    'status'   => 'active',
                    'preferences' => [
                        'social_login' => true,
                        'provider' => $provider,
                        'registration_ip' => $request->ip(),
                    ],
                ]);
            }
        }

        // Session hardening at the moment of authentication
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('player.dashboard'))
            ->with('status', __('public_pages.social_welcome'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function providerConfig(string $provider): array
    {
        $key = 'social.'.$provider;
        return [
            'client_id'     => (string) config($key.'.client_id', ''),
            'client_secret' => (string) config($key.'.client_secret', ''),
        ];
    }

    private function postJson(string $url, array $params, bool $twitterStyle = false): array
    {
        $http = app(\GuzzleHttp\Client::class);

        if ($twitterStyle) {
            // Twitter/X uses Basic auth for client credentials + form body
            $config = $this->providerConfig('twitter');
            $response = $http->post($url, [
                'auth' => [$config['client_id'], $config['client_secret']],
                'form_params' => $params,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);
        } else {
            $response = $http->post($url, [
                'form_params' => $params,
                'headers' => ['Accept' => 'application/json'],
            ]);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function getJson(string $url, string $accessToken): array
    {
        $http = app(\GuzzleHttp\Client::class);

        $response = $http->get($url, [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/json',
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function extractSocialId(array $info, string $provider): ?string
    {
        return $info['id'] ?? null;
    }

    private function extractEmail(array $info, string $provider): ?string
    {
        return $info['email'] ?? null;
    }

    private function extractName(array $info, string $provider): ?string
    {
        return $info['name'] ?? null;
    }

    private function extractAvatar(array $info, string $provider): ?string
    {
        switch ($provider) {
            case 'facebook':
                return $info['picture']['data']['url'] ?? null;
            case 'twitter':
                return $info['profile_image_url'] ?? null;
            case 'google':
                return $info['picture'] ?? null;
        }
        return null;
    }

    private function deriveUsername(string $seed): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '', $seed) ?? 'member';
        $base = mb_substr($base, 0, 24);

        $username = $base;
        $suffix = 0;
        while (User::query()->where('username', $username)->exists()) {
            $username = mb_substr($base, 0, 24).(++$suffix);
        }

        return $username;
    }
}
