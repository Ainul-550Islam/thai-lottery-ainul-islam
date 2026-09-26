<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * About page data: how-it-works, source-safe history, useful links, contact.
 *
 * No fake founding dates, no government relationship claims, no competitor
 * branding. Useful links resolve only named routes that exist on this app.
 */
final class AboutPageService
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
            'title' => $this->text->line('about_title', $locale),
            'lead' => $this->text->line('about_lead', $locale),
            'meta_title' => $this->text->line('about_meta_title', $locale),
            'meta_description' => $this->text->line('about_meta_description', $locale),
            'how_it_works' => $this->howItWorks($locale),
            'history' => [
                'title' => $this->text->line('history_title', $locale),
                'text' => $this->text->line('history_text', $locale),
            ],
            'useful_links' => $this->usefulLinks($locale),
            'contact' => [
                'title' => $this->text->line('contact_title', $locale),
                'text' => $this->text->line('contact_text', $locale),
                'href' => route('contact'),
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, text: string, index: int}>
     */
    private function howItWorks(string $locale): array
    {
        $keys = config('public_pages.how_it_works_steps', ['choose', 'buy', 'result', 'claim']);
        $steps = [];
        $index = 1;
        foreach ($keys as $key) {
            $key = (string) $key;
            $steps[] = [
                'key' => $key,
                'label' => $this->text->line('how_it_works_'.$key.'_label', $locale),
                'text' => $this->text->line('how_it_works_'.$key.'_text', $locale),
                'index' => $index,
            ];
            $index++;
        }

        return $steps;
    }

    /**
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function usefulLinks(string $locale): array
    {
        $labels = $this->usefulLinkLabels($locale);
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

        if ($links === []) {
            // Minimal always-present fallbacks so the section never renders empty.
            $links = [
                ['key' => 'results', 'label' => $labels['results'] ?? 'Results', 'href' => route('results.index')],
                ['key' => 'ticket_check', 'label' => $labels['ticket_check'] ?? 'Ticket check', 'href' => route('ticket-check')],
                ['key' => 'contact', 'label' => $labels['contact'] ?? 'Contact', 'href' => route('contact')],
            ];
        }

        return $links;
    }

    /**
     * @return array<string, string>
     */
    private function usefulLinkLabels(string $locale): array
    {
        $map = [
            'results' => 'useful_links_results',
            'ticket_check' => 'useful_links_ticket_check',
            'sales_points' => 'useful_links_sales_points',
            'contact' => 'useful_links_contact',
            'privacy' => 'useful_links_privacy',
            'terms' => 'useful_links_terms',
        ];

        // Prefer dedicated public_pages keys; fall back to titles already in the bag.
        $fallback = [
            'results' => 'Results',
            'ticket_check' => 'Ticket check',
            'sales_points' => 'Sales points',
            'contact' => $this->text->line('contact_title', $locale),
            'privacy' => 'Privacy',
            'terms' => $this->text->line('terms_meta_title', $locale),
        ];

        $labels = [];
        foreach ($map as $key => $lineKey) {
            $value = $this->text->line($lineKey, $locale);
            $labels[$key] = $value !== '' ? $value : ($fallback[$key] ?? $key);
        }

        return $labels;
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
