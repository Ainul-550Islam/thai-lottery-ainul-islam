<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

/**
 * Vision & Mission page data: platform-specific vision/mission grounded in
 * implemented capabilities, own core-values framework (not a competitor's),
 * and governance controls limited to what this codebase actually enforces.
 */
final class VisionMissionService
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

        return [
            'status' => 'AVAILABLE',
            'locale' => $locale,
            'title' => $this->text->line('vision_title', $locale),
            'lead' => $this->text->line('vision_lead', $locale),
            'meta_title' => $this->text->line('vision_meta_title', $locale),
            'meta_description' => $this->text->line('vision_meta_description', $locale),
            'vision' => [
                'title' => $this->text->line('vision_section_title', $locale),
                'text' => $this->text->line('vision_section_text', $locale),
            ],
            'mission' => [
                'title' => $this->text->line('mission_section_title', $locale),
                'text' => $this->text->line('mission_section_text', $locale),
            ],
            'core_values' => $this->coreValues($locale),
            'governance' => $this->governance($locale),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, text: string}>
     */
    private function coreValues(string $locale): array
    {
        $values = [];
        foreach ((array) config('public_pages.core_values', []) as $key) {
            $key = (string) $key;
            $values[] = [
                'key' => $key,
                'label' => $this->text->line('core_values_'.$key.'_label', $locale),
                'text' => $this->text->line('core_values_'.$key.'_text', $locale),
            ];
        }

        return $values;
    }

    /**
     * @return array{title: string, text: string, controls: array<int, array{key: string, text: string}>, disclaimer: string}
     */
    private function governance(string $locale): array
    {
        $controls = [];
        foreach ((array) config('public_pages.governance_controls', []) as $key) {
            $key = (string) $key;
            $controls[] = [
                'key' => $key,
                'text' => $this->text->line('governance_'.$key, $locale),
            ];
        }

        return [
            'title' => $this->text->line('governance_title', $locale),
            'text' => $this->text->line('governance_text', $locale),
            'controls' => $controls,
            'disclaimer' => $this->text->line('governance_disclaimer', $locale),
        ];
    }
}
