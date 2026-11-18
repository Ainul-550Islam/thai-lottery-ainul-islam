<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /me/notification-preferences — a batch of (event, channel, enabled).
 *
 * WHY A BATCH AND NOT A SINGLE ROW
 * The screen is a matrix and the member saves it once. A row-at-a-time API would
 * make a partially saved matrix a normal state, and "which of my preferences
 * survived" a question nobody can answer.
 *
 * WHAT IT REFUSES AT VALIDATION TIME
 * An unknown event type, an unknown channel, and a nested object where a boolean
 * belongs. The one thing it CANNOT decide here is whether a category is mandatory —
 * that is policy, it lives in the guard service, and the guard refuses with a
 * reason the client can display. Validation checks shape; the guard checks law.
 */
final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $events = array_map(
            static fn (NotificationEventType $e): string => $e->value,
            NotificationEventType::cases(),
        );

        $channels = array_values(array_filter(
            array_map(
                static fn (NotificationChannel $c): string => $c->value,
                NotificationChannel::cases(),
            ),
            static fn (string $c): bool => $c !== NotificationChannel::None->value,
        ));

        return [
            'preferences' => ['required', 'array', 'min:1', 'max:200'],
            'preferences.*.event_type' => ['required', 'string', 'in:'.implode(',', $events)],
            'preferences.*.channel' => ['required', 'string', 'in:'.implode(',', $channels)],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.quiet_hours' => ['nullable', 'array'],
            'preferences.*.quiet_hours.start' => ['required_with:preferences.*.quiet_hours', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'preferences.*.quiet_hours.end' => ['required_with:preferences.*.quiet_hours', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ];
    }
}
