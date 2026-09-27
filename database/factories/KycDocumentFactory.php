<?php

namespace Database\Factories;

use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KycDocument>
 */
class KycDocumentFactory extends Factory
{
    public function definition(): array
    {
        $fingerprint = hash('sha256', Str::random(40));

        return [
            'user_id' => User::factory(),
            'document_type' => KycDocumentType::NationalId,
            'document_number' => null,
            'file_path' => 'kyc/'.Str::random(32).'.jpg',
            'original_filename' => 'national-id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 204800,
            'status' => KycStatus::Pending,
            'verification_status' => 'uploaded',
            'issuer_country' => 'TH',
            'document_fingerprint' => $fingerprint,
            'verification_reference' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KycStatus::Verified,
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Document unreadable'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KycStatus::Rejected,
            'verification_status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
    }
}
