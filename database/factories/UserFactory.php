<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        // Faker's in-memory unique registry is process-local. Real concurrency
        // tests create users in child PHP processes against one shared MySQL
        // database, so a short username can collide despite unique(). A
        // cryptographically random token keeps every unique column independent
        // across processes without retrying or weakening database constraints.
        $token = bin2hex(random_bytes(10));
        $phoneDigits = str_pad((string) (hexdec(substr($token, 0, 8)) % 1_000_000_000), 9, '0', STR_PAD_LEFT);

        return [
            'name' => fake()->name(),
            'email' => 'user.'.$token.'@example.test',
            'username' => 'u_'.$token,
            'phone' => '+8801'.$phoneDigits,
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'status' => UserStatus::PendingVerification,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
        ]);
    }
}
