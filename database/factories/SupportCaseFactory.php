<?php

// TYPE: Test factory
// PURPOSE: Generate explicitly synthetic owner-scoped support cases for isolated automated tests only.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupportCase> */
final class SupportCaseFactory extends Factory
{
    protected $model = SupportCase::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'public_reference' => SupportCase::buildPublicReference(),
            'owner_user_id' => User::factory(),
            'category' => 'other',
            'priority' => SupportCase::PRIORITY_NORMAL,
            'status' => SupportCase::STATUS_OPEN,
            'subject' => 'Synthetic support case',
            'closed_at' => null,
        ];
    }
}
