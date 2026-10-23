<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\ChatException;
use App\Models\Chat\ChatCannedResponse;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Operator canned response management and locale-aware picker queries.
 */
final class ChatCannedResponseService
{
    /**
     * @return array{locale: string, categories: list<array{category: string, responses: list<array<string, mixed>>}>}
     */
    public function forPicker(string $locale = 'en'): array
    {
        $normalisedLocale = strtolower(trim($locale)) ?: 'en';

        $rows = ChatCannedResponse::query()
            ->enabled()
            ->forLocale($normalisedLocale)
            ->inDisplayOrder()
            ->get();

        $effectiveLocale = $normalisedLocale;
        if ($rows->isEmpty() && $normalisedLocale !== 'en') {
            $rows = ChatCannedResponse::query()
                ->enabled()
                ->forLocale('en')
                ->inDisplayOrder()
                ->get();
            $effectiveLocale = 'en';
        }

        $grouped = [];
        foreach ($rows as $row) {
            $category = (string) ($row->category ?: 'general');
            $grouped[$category][] = [
                'id' => (int) $row->getKey(),
                'key' => (string) $row->key,
                'title' => (string) $row->title,
                'body' => (string) $row->body,
                'category' => $category,
                'placeholders' => $row->placeholders ?? [],
                'locale' => (string) $row->locale,
            ];
        }

        $categories = [];
        foreach ($grouped as $categoryName => $responses) {
            $categories[] = [
                'category' => $categoryName,
                'responses' => $responses,
            ];
        }

        return [
            'locale' => $effectiveLocale,
            'categories' => $categories,
        ];
    }

    public function paginateForAdmin(int $perPage = 25): LengthAwarePaginator
    {
        return ChatCannedResponse::query()
            ->inDisplayOrder()
            ->paginate(min(100, max(1, $perPage)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): ChatCannedResponse
    {
        $key = strtolower(trim((string) ($attributes['key'] ?? '')));
        if ($key === '' || preg_match('/^[a-z0-9_-]{2,64}$/', $key) !== 1) {
            throw ChatException::invalidCannedShortcut('Invalid canned response key.');
        }

        return ChatCannedResponse::query()->create([
            'key' => $key,
            'title' => trim((string) ($attributes['title'] ?? '')),
            'body' => trim((string) ($attributes['body'] ?? '')),
            'category' => isset($attributes['category']) && trim((string) $attributes['category']) !== ''
                ? trim((string) $attributes['category'])
                : 'general',
            'placeholders' => isset($attributes['placeholders']) && is_array($attributes['placeholders'])
                ? array_values($attributes['placeholders'])
                : [],
            'locale' => strtolower(trim((string) ($attributes['locale'] ?? 'en'))) ?: 'en',
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            'enabled' => array_key_exists('enabled', $attributes) ? (bool) $attributes['enabled'] : true,
            'updated_by_user_id' => (int) $actor->getAuthIdentifier(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        ChatCannedResponse $cannedResponse,
        array $attributes,
        User $actor,
    ): ChatCannedResponse {
        if (array_key_exists('title', $attributes)) {
            $cannedResponse->title = trim((string) $attributes['title']);
        }

        if (array_key_exists('body', $attributes)) {
            $cannedResponse->body = trim((string) $attributes['body']);
        }

        if (array_key_exists('category', $attributes)) {
            $cannedResponse->category = trim((string) $attributes['category']) ?: 'general';
        }

        if (array_key_exists('placeholders', $attributes) && is_array($attributes['placeholders'])) {
            $cannedResponse->placeholders = array_values($attributes['placeholders']);
        }

        if (array_key_exists('locale', $attributes)) {
            $cannedResponse->locale = strtolower(trim((string) $attributes['locale'])) ?: 'en';
        }

        if (array_key_exists('sort_order', $attributes)) {
            $cannedResponse->sort_order = (int) $attributes['sort_order'];
        }

        if (array_key_exists('enabled', $attributes)) {
            $cannedResponse->enabled = (bool) $attributes['enabled'];
        }

        $cannedResponse->updated_by_user_id = (int) $actor->getAuthIdentifier();
        $cannedResponse->save();

        return $cannedResponse->fresh();
    }

    public function disable(ChatCannedResponse $cannedResponse, User $actor): ChatCannedResponse
    {
        $cannedResponse->enabled = false;
        $cannedResponse->updated_by_user_id = (int) $actor->getAuthIdentifier();
        $cannedResponse->save();

        return $cannedResponse->fresh();
    }
}
