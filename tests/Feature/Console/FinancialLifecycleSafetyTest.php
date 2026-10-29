<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Draw;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FinancialLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('nonTestingEnvironments')]
    public function test_synthetic_proof_refuses_before_writes_outside_testing(string $environment): void
    {
        $this->app['env'] = $environment;
        $users = User::query()->count();
        $draws = Draw::query()->count();
        $this->artisan('fintech:verify-full-lifecycle')
            ->expectsOutputToContain('Refusing to run the synthetic financial lifecycle')
            ->assertExitCode(1);
        $this->assertSame($users, User::query()->count());
        $this->assertSame($draws, Draw::query()->count());
    }

    public static function nonTestingEnvironments(): array
    {
        return [['production'], ['staging'], ['local']];
    }
}
