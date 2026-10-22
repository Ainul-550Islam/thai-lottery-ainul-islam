<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Video platforms the public live draw card can embed. Each case has its own strict URL
 * allowlist in App\Services\Lottery\LiveStreamEmbedResolver. Adding a case without a
 * resolver branch is a compile-time error because the resolver match is exhaustive.
 */
enum LiveStreamProvider: string
{
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';

    /**
     * Human-readable platform name for operator and visitor messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Facebook => 'Facebook',
            self::TikTok => 'TikTok',
        };
    }

    /**
     * Maps an operator-supplied config value to a provider, or null when unsupported.
     */
    public static function fromConfig(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
