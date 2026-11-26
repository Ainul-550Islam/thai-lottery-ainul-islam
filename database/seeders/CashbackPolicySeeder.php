<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountGradeLevel;
use App\Models\CashbackPolicy;
use Illuminate\Database\Seeder;

final class CashbackPolicySeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, array<string, mixed>> $policies */
        $policies = (array) config('rewards.cashback.policies', []);
        $defaultPeriod = (string) config('rewards.cashback.default_period', 'daily');
        $defaultBasis = (string) config('rewards.cashback.default_basis', 'real_net_loss');
        $defaultMinClaim = (string) config('rewards.cashback.default_min_claim', '10.00');
        $defaultExpiryDays = (int) config('rewards.cashback.default_expiry_days', 30);

        foreach (AccountGradeLevel::cases() as $grade) {
            /** @var array<string, mixed> $row */
            $row = is_array($policies[$grade->value] ?? null) ? $policies[$grade->value] : [];
            $period = (string) ($row['period'] ?? $defaultPeriod);

            CashbackPolicy::query()->updateOrCreate(
                [
                    'grade' => $grade->value,
                    'period' => $period,
                ],
                [
                    'rate_bps' => max(0, (int) ($row['rate_bps'] ?? 0)),
                    'basis' => (string) ($row['basis'] ?? $defaultBasis),
                    'min_claim' => bcadd((string) ($row['min_claim'] ?? $defaultMinClaim), '0.00', 2),
                    'max_per_period' => bcadd((string) ($row['max_per_period'] ?? '0.00'), '0.00', 2),
                    'expiry_days' => max(1, (int) ($row['expiry_days'] ?? $defaultExpiryDays)),
                    'active' => (bool) ($row['active'] ?? true),
                ],
            );
        }
    }
}
