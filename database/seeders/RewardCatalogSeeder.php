<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RewardType;
use App\Models\RewardCatalogItem;
use Illuminate\Database\Seeder;

final class RewardCatalogSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array<string, mixed>> $items */
        $items = (array) config('rewards.catalog.default_items', []);

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['code'], $item['type'])) {
                continue;
            }

            $type = RewardType::from((string) $item['type']);
            /** @var array<string, mixed> $payload */
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];

            RewardCatalogItem::query()->updateOrCreate(
                ['code' => (string) $item['code']],
                [
                    'name' => (string) ($item['name'] ?? $item['code']),
                    'description' => isset($item['description']) ? (string) $item['description'] : null,
                    'type' => $type,
                    'points_cost' => max(1, (int) ($item['points_cost'] ?? 1)),
                    'payload' => $payload,
                    'stock' => array_key_exists('stock', $item) && $item['stock'] !== null
                        ? max(0, (int) $item['stock'])
                        : null,
                    'per_member_limit' => array_key_exists('per_member_limit', $item) && $item['per_member_limit'] !== null
                        ? max(1, (int) $item['per_member_limit'])
                        : null,
                    'starts_at' => $item['starts_at'] ?? null,
                    'ends_at' => $item['ends_at'] ?? null,
                    'active' => (bool) ($item['active'] ?? true),
                ],
            );
        }
    }
}
