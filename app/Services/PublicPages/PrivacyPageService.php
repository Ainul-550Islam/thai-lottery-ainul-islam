<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

/**
 * Privacy Policy page data assembly.
 *
 * Version metadata comes from config/legal.php (fixed dates, never dynamic).
 * Follows the canonical legal structure established by TermsPageService.
 */
final class PrivacyPageService
{
    public function __construct(private readonly PublicPageTextBag $text)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();
        $version = (string) config('legal.version', 'v1.0.0');
        $effectiveAt = (string) config('legal.effective_at', '');
        $updatedAt = (string) config('legal.updated_at', '');
        if ($effectiveAt === '') {
            $effectiveAt = (string) config('legal.updated_at', '');
        }
        $notConfigured = $this->text->line('privacy_not_configured', $locale)
            ?: $this->text->line('not_configured', $locale);

        $sections = [
            [
                'id' => 'data-processed',
                'title' => $this->text->line('privacy_data_title', $locale),
                'body' => (string) $this->text->line('privacy_data_text', $locale),
            ],
            [
                'id' => 'public-surfaces',
                'title' => $this->text->line('privacy_public_title', $locale),
                'body' => (string) $this->text->line('privacy_public_text', $locale),
            ],
            [
                'id' => 'security-storage',
                'title' => $this->text->line('privacy_security_title', $locale),
                'body' => (string) $this->text->line('privacy_security_text', $locale),
            ],
            [
                'id' => 'player-rights',
                'title' => $this->text->line('privacy_rights_title', $locale),
                'body' => (string) $this->text->line('privacy_rights_text', $locale),
            ],
            [
                'id' => 'inquiries-contact',
                'title' => $this->text->line('privacy_contact_title', $locale),
                'body' => (string) $this->text->line('privacy_contact_text', $locale),
            ],
        ];

        $operator = (array) config('legal.operator', []);

        return [
            'status' => 'AVAILABLE',
            'locale' => $locale,
            'title' => $this->text->line('privacy_title', $locale),
            'meta_title' => $this->text->line('privacy_meta_title', $locale),
            'meta_description' => $this->text->line('privacy_meta_description', $locale),
            'version' => $version,
            'version_label' => $this->text->line('privacy_version_label', $locale),
            'effective_at' => $effectiveAt,
            'effective_label' => $this->text->line('privacy_effective_label', $locale),
            'updated_at' => $updatedAt,
            'updated_label' => $this->text->line('privacy_updated_label', $locale),
            'not_configured' => $notConfigured,
            'sections' => $sections,
            'operator' => [
                'legal_name' => is_string($operator['legal_name'] ?? null) && $operator['legal_name'] !== ''
                    ? $operator['legal_name'] : $notConfigured,
                'registration_number' => is_string($operator['registration_number'] ?? null) && $operator['registration_number'] !== ''
                    ? $operator['registration_number'] : $notConfigured,
                'support_email' => is_string($operator['support_email'] ?? null) && $operator['support_email'] !== ''
                    ? $operator['support_email'] : $notConfigured,
                'support_phone' => is_string($operator['support_phone'] ?? null) && $operator['support_phone'] !== ''
                    ? $operator['support_phone'] : $notConfigured,
                'address' => is_string($operator['address'] ?? null) && $operator['address'] !== ''
                    ? $operator['address'] : $notConfigured,
            ],
        ];
    }
}
