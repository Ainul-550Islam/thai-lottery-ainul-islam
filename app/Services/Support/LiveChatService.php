<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\User;
use App\Support\ResponsibleGamingGate;

/**
 * Live Support Chat Widget Configuration & HMAC Identity Signer (Audit §D6.3).
 *
 * SECURITY & PRIVACY RULES
 * ------------------------
 * 1. Never renders on administrative / operational route prefixes (`admin`,
 *    `filament`, `horizon`, `telescope`).
 * 2. Never transmits member identity unless `LIVE_CHAT_IDENTITY_SECRET` is set,
 *    preventing client-side user-impersonation attacks in support threads.
 * 3. Wallet balances, phone numbers, and government IDs are NEVER included.
 * 4. When `require_consent` is enabled (default), third-party network scripts
 *    are deferred until the visitor explicitly opens the support launcher or
 *    grants consent — protecting Core Web Vitals and PDPA/GDPR compliance.
 */
final class LiveChatService
{
    private const SUPPORTED_PROVIDERS = ['crisp', 'intercom', 'tawk'];

    /**
     * Build the safe view payload for `<x-support.live-chat />`.
     *
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     widget_id: string,
     *     require_consent: bool,
     *     suppress_proactive: bool,
     *     identity: ?array{user_id: string, email: string, hmac: string}
     * }
     */
    public function viewConfig(?User $user, string $requestPath = '/'): array
    {
        $disabled = [
            'enabled' => false,
            'provider' => 'crisp',
            'widget_id' => '',
            'require_consent' => true,
            'suppress_proactive' => true,
            'identity' => null,
        ];

        if (! (bool) config('conversion_tooling.live_chat.enabled', false)) {
            return $disabled;
        }

        $trimmedPath = trim($requestPath, '/');
        /** @var array<int, string> $excluded */
        $excluded = (array) config('conversion_tooling.live_chat.excluded_prefixes', ['admin', 'filament']);
        foreach ($excluded as $prefix) {
            $cleanPrefix = trim((string) $prefix, '/');
            if ($cleanPrefix !== '' && ($trimmedPath === $cleanPrefix || str_starts_with($trimmedPath, $cleanPrefix.'/'))) {
                return $disabled;
            }
        }

        $provider = strtolower(trim((string) config('conversion_tooling.live_chat.provider', 'crisp')));
        $widgetId = trim((string) config('conversion_tooling.live_chat.widget_id', ''));

        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true) || $widgetId === '') {
            return $disabled;
        }

        if ($provider === 'tawk' && substr_count($widgetId, '/') !== 1) {
            return $disabled;
        }

        $suppressProactive = $user instanceof User && ResponsibleGamingGate::isSelfExcluded($user);

        return [
            'enabled' => true,
            'provider' => $provider,
            'widget_id' => $widgetId,
            'require_consent' => (bool) config('conversion_tooling.live_chat.require_consent', true),
            'suppress_proactive' => $suppressProactive,
            'identity' => $this->signedIdentity($provider, $user),
        ];
    }

    /**
     * Compute provider-specific HMAC-SHA256 verification payload.
     *
     * @return ?array{user_id: string, email: string, hmac: string}
     */
    public function signedIdentity(string $provider, ?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $secret = (string) config('conversion_tooling.live_chat.identity_secret', '');
        if ($secret === '') {
            return null;
        }

        $userId = (string) $user->getKey();
        $email = trim((string) ($user->email ?? ''));

        if ($provider === 'intercom') {
            return [
                'user_id' => $userId,
                'email' => $email,
                'hmac' => hash_hmac('sha256', $userId, $secret),
            ];
        }

        if ($email === '') {
            return null;
        }

        return [
            'user_id' => $userId,
            'email' => $email,
            'hmac' => hash_hmac('sha256', $email, $secret),
        ];
    }
}
