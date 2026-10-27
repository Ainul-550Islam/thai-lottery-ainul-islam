<?php

declare(strict_types=1);

namespace Tests\Unit\Velocity;

use App\Services\Velocity\VelocityWindow;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VelocityWindowTest extends TestCase
{
    public function test_expiration_boundary_sorting_duplicates_and_cap(): void
    {
        $window = new VelocityWindow;
        self::assertSame([100], $window->append([], 100, 60, 3));
        self::assertSame([41, 90, 100], $window->append([90, 40, 41], 100, 60, 3));
        self::assertSame([99, 100, 100], $window->append([41, 99, 100], 100, 60, 3));
        self::assertSame([161], $window->append([100], 161, 60, 3));
    }

    public static function invalidInputs(): array
    {
        return [[[], 0, 3], [[], 86401, 3], [[], 60, 0], [[], 60, 10002], [['99'], 60, 3], [[101], 60, 3], [['x' => 99], 60, 3], [array_fill(0, 10002, 99), 60, 3]];
    }

    #[DataProvider('invalidInputs')]
    public function test_bad_state_is_not_silently_accepted(array $timestamps, int $seconds, int $capacity): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VelocityWindow)->append($timestamps, 100, $seconds, $capacity);
    }
}
