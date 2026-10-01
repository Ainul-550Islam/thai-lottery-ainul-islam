<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Canonical Vision & Mission public content composer.
 *
 * This service reads approved localized content and configured engineering
 * controls. It never infers licences, government relationships, certifications,
 * milestones, metrics, or future products from the observed live page.
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
            'hero' => [
                'eyebrow' => $this->text->line('vision_hero_eyebrow', $locale),
                'title' => $this->text->line('vision_hero_title', $locale),
                'text' => $this->text->line('vision_hero_text', $locale),
                'visual_label' => $this->text->line('vision_hero_visual_label', $locale),
                'disclaimer' => $this->text->line('vision_hero_disclaimer', $locale),
            ],
            'vision' => [
                'title' => $this->text->line('vision_section_title', $locale),
                'text' => $this->text->line('vision_section_text', $locale),
                'principles_title' => $this->text->line('vision_principles_title', $locale),
                'principles' => [
                    [
                        'key' => 'clarity',
                        'title' => $this->text->line('vision_principle_clarity_title', $locale),
                        'text' => $this->text->line('vision_principle_clarity_text', $locale),
                    ],
                    [
                        'key' => 'reliability',
                        'title' => $this->text->line('vision_principle_reliability_title', $locale),
                        'text' => $this->text->line('vision_principle_reliability_text', $locale),
                    ],
                    [
                        'key' => 'responsibility',
                        'title' => $this->text->line('vision_principle_responsibility_title', $locale),
                        'text' => $this->text->line('vision_principle_responsibility_text', $locale),
                    ],
                ],
            ],
            'mission' => [
                'eyebrow' => $this->text->line('mission_eyebrow', $locale),
                'title' => $this->text->line('mission_section_title', $locale),
                'text' => $this->text->line('mission_section_text', $locale),
                'principles_title' => $this->text->line('mission_principles_title', $locale),
                'principles' => [
                    [
                        'key' => 'service',
                        'title' => $this->text->line('mission_principle_service_title', $locale),
                        'text' => $this->text->line('mission_principle_service_text', $locale),
                    ],
                    [
                        'key' => 'technology',
                        'title' => $this->text->line('mission_principle_technology_title', $locale),
                        'text' => $this->text->line('mission_principle_technology_text', $locale),
                    ],
                    [
                        'key' => 'growth',
                        'title' => $this->text->line('mission_principle_growth_title', $locale),
                        'text' => $this->text->line('mission_principle_growth_text', $locale),
                    ],
                ],
            ],
            'clear' => [
                'eyebrow' => $this->text->line('clear_eyebrow', $locale),
                'title' => $this->text->line('clear_title', $locale),
                'text' => $this->text->line('clear_text', $locale),
                'items' => $this->clearValues($locale),
            ],
            'core_values' => $this->coreValues($locale),
            'governance' => $this->governance($locale),
            'journey' => [
                'eyebrow' => $this->text->line('journey_eyebrow', $locale),
                'title' => $this->text->line('journey_title', $locale),
                'text' => $this->text->line('journey_text', $locale),
                'items' => [
                    [
                        'key' => 'now',
                        'label' => $this->text->line('journey_now', $locale),
                        'text' => $this->text->line('journey_now_text', $locale),
                    ],
                    [
                        'key' => 'next',
                        'label' => $this->text->line('journey_next', $locale),
                        'text' => $this->text->line('journey_next_text', $locale),
                    ],
                    [
                        'key' => 'future',
                        'label' => $this->text->line('journey_future', $locale),
                        'text' => $this->text->line('journey_future_text', $locale),
                    ],
                ],
            ],
            'cta' => [
                'title' => $this->text->line('vision_cta_title', $locale),
                'text' => $this->text->line('vision_cta_text', $locale),
                'source_note' => $this->text->line('vision_source_note', $locale),
                'links' => [
                    ['key' => 'about', 'label' => $this->text->line('vision_cta_about', $locale), 'href' => $this->namedRoute('about')],
                    ['key' => 'results', 'label' => $this->text->line('vision_cta_results', $locale), 'href' => $this->namedRoute('results.index')],
                    ['key' => 'contact', 'label' => $this->text->line('vision_cta_contact', $locale), 'href' => $this->namedRoute('contact')],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, text: string, letter: string}>
     */
    private function clearValues(string $locale): array
    {
        $items = [
            ['key' => 'collaboration', 'letter' => 'C'],
            ['key' => 'learning', 'letter' => 'L'],
            ['key' => 'ethics', 'letter' => 'E'],
            ['key' => 'accountability', 'letter' => 'A'],
            ['key' => 'relationship', 'letter' => 'R'],
        ];
        $values = [];

        foreach ($items as $item) {
            $values[] = [
                'key' => $item['key'],
                'letter' => $item['letter'],
                'label' => $this->text->line('clear_'.$item['key'].'_title', $locale),
                'text' => $this->text->line('clear_'.$item['key'].'_text', $locale),
            ];
        }

        return $values;
    }

    /**
     * @return array<int, array{key: string, label: string, text: string}>
     */
    private function coreValues(string $locale): array
    {
        $values = [];
        foreach ((array) config('public_pages.core_values', []) as $key) {
            $key = (string) $key;
            $label = $this->text->line('core_values_'.$key.'_label', $locale);
            $text = $this->text->line('core_values_'.$key.'_text', $locale);
            if ($label === '' || $text === '') {
                continue;
            }
            $values[] = ['key' => $key, 'label' => $label, 'text' => $text];
        }

        return $values;
    }

    /**
     * @return array{eyebrow: string, title: string, text: string, controls: array<int, array{key: string, text: string}>, disclaimer: string}
     */
    private function governance(string $locale): array
    {
        $controls = [];
        foreach ((array) config('public_pages.governance_controls', []) as $key) {
            $key = (string) $key;
            $text = $this->text->line('governance_'.$key, $locale);
            if ($text === '') {
                continue;
            }
            $controls[] = ['key' => $key, 'text' => $text];
        }

        return [
            'eyebrow' => $this->text->line('governance_eyebrow', $locale),
            'title' => $this->text->line('governance_display_title', $locale),
            'text' => $this->text->line('governance_display_text', $locale),
            'controls' => $controls,
            'disclaimer' => $this->text->line('governance_disclaimer', $locale),
        ];
    }

    private function namedRoute(string $name): string
    {
        try {
            return Route::has($name) ? route($name) : route('home');
        } catch (Throwable) {
            return route('home');
        }
    }
}
