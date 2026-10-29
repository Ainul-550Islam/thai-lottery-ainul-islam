<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PrivacyRequestStatus;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataSubjectRequestEvent> */
class DataSubjectRequestEventFactory extends Factory
{
    protected $model = DataSubjectRequestEvent::class;

    public function definition(): array
    {
        return [
            'request_id' => DataSubjectRequest::factory(),
            'actor_type' => DataSubjectRequestEvent::ACTOR_SYSTEM,
            'actor_id' => null,
            'event' => DataSubjectRequestEvent::EVENT_SUBMITTED,
            'from_status' => null,
            'to_status' => PrivacyRequestStatus::Received->value,
            'note_encrypted' => null,
            'created_at' => now(),
        ];
    }
}
