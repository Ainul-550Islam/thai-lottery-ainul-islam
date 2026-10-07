<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportCase;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportMessage> */
final class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'support_case_id' => SupportCase::factory(),
            'sender_user_id' => User::factory(),
            'body' => fake()->sentence(),
            'internal' => false,
        ];
    }
}
