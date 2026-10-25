<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactMessage;
use App\Models\ContactMessageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic support-inbox timeline entries.
 *
 * @extends Factory<ContactMessageEvent>
 */
class ContactMessageEventFactory extends Factory
{
    protected $model = ContactMessageEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_message_id' => ContactMessage::factory(),
            'actor_id' => null,
            'type' => ContactMessageEvent::TYPE_NOTE,
            'from_status' => null,
            'to_status' => null,
            'body_encrypted' => 'Synthetic internal note created by a factory, not a real operator.',
            'created_at' => now(),
        ];
    }

    public function statusChange(string $from, string $to): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ContactMessageEvent::TYPE_STATUS_CHANGE,
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }

    public function reply(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ContactMessageEvent::TYPE_REPLY,
            'body_encrypted' => 'Synthetic operator reply created by a factory.',
        ]);
    }
}
