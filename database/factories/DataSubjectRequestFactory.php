<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Models\DataSubjectRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Synthetic data-subject requests.
 *
 * EVERY ADDRESS IS @example.test, the domain reserved for exactly this, so a
 * misconfigured environment can never mail a real person from a fixture.
 *
 * The address is written through the encrypted cast and its hash is computed
 * the same way the service computes it - a factory that stored a hash of
 * something else would produce rows the lookup could never find, and tests
 * would pass against data production cannot represent.
 *
 * @extends Factory<DataSubjectRequest>
 */
class DataSubjectRequestFactory extends Factory
{
    protected $model = DataSubjectRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->userName().'@example.test';
        $type = fake()->randomElement(PrivacyRequestType::cases());

        return [
            'user_id' => null,
            'email_hash' => DataSubjectRequest::hashEmail($email),
            'email_encrypted' => $email,
            'locale' => 'en',
            'type' => $type,
            'status' => PrivacyRequestStatus::Received,
            'details_encrypted' => 'Synthetic development request. Not a real person.',
            'verified_at' => Carbon::now(),
            'received_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(max(1, (int) config('privacy_requests.sla_days', 30))),
            'completed_at' => null,
            'rejected_reason_code' => null,
            'assigned_to' => null,
            'legal_hold_reason' => null,
            'ip_hash' => null,
        ];
    }

    /** A public intake that has not verified its address yet. */
    public function pendingVerification(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PrivacyRequestStatus::PendingVerification,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);
    }

    /** A request already past its statutory due date. */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PrivacyRequestStatus::InReview,
            'received_at' => Carbon::now()->subDays(40),
            'verified_at' => Carbon::now()->subDays(40),
            'due_at' => Carbon::now()->subDays(10),
        ]);
    }

    /** A request inside the reminder window and not yet due. */
    public function dueSoon(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PrivacyRequestStatus::InReview,
            'due_at' => Carbon::now()->addDays(2),
        ]);
    }

    public function ofType(PrivacyRequestType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }

    public function withStatus(PrivacyRequestStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /** Bind the request to a real account and use that account's address. */
    public function forUser(\App\Models\User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => (int) $user->getKey(),
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'email_encrypted' => (string) $user->email,
            'verified_at' => Carbon::now(),
        ]);
    }
}
