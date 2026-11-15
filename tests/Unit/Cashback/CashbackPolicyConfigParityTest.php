<?php

declare(strict_types=1);

namespace Tests\Unit\Cashback;

use App\Enums\AccountGradeLevel;
use Tests\TestCase;

/**
 * CashbackPolicySeeder creates one policy per AccountGradeLevel from
 * config/rewards.php. A grade without a config row would silently seed at
 * rate 0, so the two lists must stay identical.
 */
final class CashbackPolicyConfigParityTest extends TestCase
{
    public function test_every_account_grade_has_a_configured_cashback_policy_and_nothing_extra(): void
    {
        $grades = array_map(static fn (AccountGradeLevel $grade): string => $grade->value, AccountGradeLevel::cases());
        $configured = array_keys((array) config('rewards.cashback.policies', []));

        sort($grades);
        sort($configured);

        $this->assertSame($grades, $configured);

        foreach ((array) config('rewards.cashback.policies') as $grade => $row) {
            $this->assertGreaterThan(0, (int) ($row['rate_bps'] ?? 0), "rate_bps must be > 0 for {$grade}");
        }
    }
}
