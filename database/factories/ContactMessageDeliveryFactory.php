<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactMessage;
use App\Models\ContactMessageDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic delivery attempts (PROMPT 10).
 *
 * NO CREDENTIAL AND NO PROVIDER TEXT, even in a fixture. error_class holds a
 * class name and error_code holds one of our own short codes, exactly as the
 * real service writes them - a factory that invented a realistic SMTP error
 * string would be the one place a host or a username could enter the table.
 *
 * @extends Factory<ContactMessageDelivery>
 */
class ContactMessageDeliveryFactory extends Factory
{
    protected $model = ContactMessageDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_message_id' => ContactMessage::factory(),
            'provider' => 'mail',
            'state' => ContactMessage::DELIVERY_NOT_CONFIGURED,
            'attempted_at' => now(),
            'error_code' => 'PROVIDER_NOT_CONFIGURED',
            'error_class' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ContactMessage::DELIVERY_SENT,
            'error_code' => null,
            'error_class' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ContactMessage::DELIVERY_FAILED,
            'error_code' => 'TRANSPORT_FAILURE',
            'error_class' => \RuntimeException::class,
        ]);
    }
}
