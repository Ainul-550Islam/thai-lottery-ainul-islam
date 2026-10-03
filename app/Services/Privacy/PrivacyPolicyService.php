<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Services\PublicPages\PrivacyPageService;

/**
 * Privacy Policy Service.
 *
 * Centralizes date-stable, versioned privacy policy data for legal compliance,
 * public rendering, and user audit assertions. Never generates dynamic `date()`
 * for legal policy effective dates.
 */
class PrivacyPolicyService
{
    public function __construct(
        private readonly PrivacyPageService $pageService,
    ) {}

    /**
     * Get structured, versioned privacy policy document.
     *
     * @return array<string, mixed>
     */
    public function getPolicy(?string $locale = null): array
    {
        return $this->pageService->data($locale);
    }

    /**
     * Get the authoritative semantic version of the privacy policy.
     */
    public function getVersion(): string
    {
        return (string) config('legal.version', 'v1.0.0');
    }

    /**
     * Get the fixed effective date of the privacy policy.
     */
    public function getEffectiveDate(): string
    {
        $effectiveAt = (string) config('legal.effective_at', '');

        return $effectiveAt !== '' ? $effectiveAt : (string) config('legal.updated_at', '2026-09-24');
    }

    /**
     * Get the fixed last updated date of the privacy policy.
     */
    public function getUpdatedDate(): string
    {
        return (string) config('legal.updated_at', '2026-09-24');
    }
}
