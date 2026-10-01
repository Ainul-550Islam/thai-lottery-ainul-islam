<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Canonical public About-page data source.
 *
 * All user-facing copy is localized through public_pages translations. The
 * service only composes approved public data, validates timeline records, and
 * resolves CTAs to named routes that exist in the application.
 */
final class AboutPageService
{
    public function __construct(
        private readonly PublicPageTextBag $text,
        private readonly AboutTimelineService $timeline,
    ) {
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
            'title' => $this->text->line('about_title', $locale),
            'lead' => $this->text->line('about_lead', $locale),
            'meta_title' => $this->text->line('about_meta_title', $locale),
            'meta_description' => $this->text->line('about_meta_description', $locale),
            'hero' => [
                'eyebrow' => $this->text->line('about_hero_eyebrow', $locale),
                'title' => $this->text->line('about_hero_title', $locale),
                'visual_label' => $this->text->line('about_hero_visual_label', $locale),
            ],
            'story' => [
                'eyebrow' => $this->text->line('about_story_eyebrow', $locale),
                'title' => $this->text->line('history_title', $locale),
                'text' => $this->text->line('history_text', $locale),
                'source_note' => $this->text->line('about_story_source', $locale),
            ],
            'timeline' => [
                'eyebrow' => $this->text->line('about_timeline_eyebrow', $locale),
                'title' => $this->text->line('about_timeline_title', $locale),
                'text' => $this->text->line('about_timeline_text', $locale),
                'source_label' => $this->text->line('about_timeline_source_label', $locale),
                'empty_label' => $this->text->line('about_timeline_empty', $locale),
                'items' => $this->timeline->milestones($locale),
            ],
            'how_it_works' => $this->howItWorks($locale),
            'values' => [
                'eyebrow' => $this->text->line('about_values_eyebrow', $locale),
                'title' => $this->text->line('about_values_title', $locale),
                'text' => $this->text->line('about_values_text', $locale),
                'items' => $this->values($locale),
            ],
            'trust' => [
                'eyebrow' => $this->text->line('about_trust_eyebrow', $locale),
                'title' => $this->text->line('about_trust_title', $locale),
                'text' => $this->text->line('about_trust_text', $locale),
                'items' => $this->trustItems($locale),
            ],
            'useful_links' => $this->usefulLinks($locale),
            'contact' => [
                'title' => $this->text->line('contact_title', $locale),
                'text' => $this->text->line('contact_text', $locale),
                'href' => $this->namedRoute('contact'),
            ],
            'cta' => [
                'title' => $this->text->line('about_cta_title', $locale),
                'text' => $this->text->line('about_cta_text', $locale),
                'source_note' => $this->text->line('about_source_note', $locale),
                'links' => [
                    [
                        'key' => 'results',
                        'label' => $this->text->line('about_cta_results', $locale),
                        'href' => $this->namedRoute('results.index'),
                    ],
                    [
                        'key' => 'ticket_check',
                        'label' => $this->text->line('about_cta_check', $locale),
                        'href' => $this->namedRoute('ticket-check'),
                    ],
                    [
                        'key' => 'contact',
                        'label' => $this->text->line('about_cta_contact', $locale),
                        'href' => $this->namedRoute('contact'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, text: string, index: int}>
     */
    private function howItWorks(string $locale): array
    {
        $steps = [];
        $index = 1;
        foreach ((array) config('public_pages.how_it_works_steps', ['choose', 'buy', 'result', 'claim']) as $key) {
            $key = (string) $key;
            $label = $this->text->line('how_it_works_'.$key.'_label', $locale);
            $text = $this->text->line('how_it_works_'.$key.'_text', $locale);
            if ($label === '' || $text === '') {
                continue;
            }
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'text' => $text,
                'index' => $index,
            ];
            $index++;
        }

        return $steps;
    }

    /**
     * @return array<int, array{key: string, label: string, text: string}>
     */
    private function values(string $locale): array
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
     * @return array<int, array{key: string, text: string}>
     */
    private function trustItems(string $locale): array
    {
        $items = [];
        foreach ((array) config('public_pages.governance_controls', []) as $key) {
            $key = (string) $key;
            $text = $this->text->line('governance_'.$key, $locale);
            if ($text === '') {
                continue;
            }
            $items[] = ['key' => $key, 'text' => $text];
        }

        return $items;
    }

    /**
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function usefulLinks(string $locale): array
    {
        $labels = [
            'results' => $this->text->line('useful_links_results', $locale),
            'ticket_check' => $this->text->line('useful_links_ticket_check', $locale),
            'sales_points' => $this->text->line('useful_links_sales_points', $locale),
            'contact' => $this->text->line('useful_links_contact', $locale),
            'privacy' => $this->text->line('useful_links_privacy', $locale),
            'terms' => $this->text->line('useful_links_terms', $locale),
        ];
        $links = [];

        foreach ((array) config('public_pages.useful_links', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = (string) ($entry['key'] ?? '');
            $routeName = (string) ($entry['route'] ?? '');
            if ($key === '' || $routeName === '' || ! $this->routeExists($routeName)) {
                continue;
            }
            $links[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'href' => route($routeName),
            ];
        }

        return $links;
    }

    private function namedRoute(string $name): string
    {
        return $this->routeExists($name) ? route($name) : route('home');
    }

    private function routeExists(string $name): bool
    {
        try {
            return Route::has($name);
        } catch (Throwable) {
            return false;
        }
    }
}
