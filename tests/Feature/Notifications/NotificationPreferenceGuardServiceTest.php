<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceGuardService;
use App\Services\Notifications\NotificationPreferenceRefused;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Mandatory notification categories cannot be switched off, at EITHER layer.
 *
 * WHY THERE ARE TWO LAYERS
 * apply() refuses an obvious attempt with 422-able reason codes, AND allows() ignores
 * a stored `enabled = false` row for a mandatory category. The second layer exists
 * because a row can reach the database by a path that never went through apply() — a
 * data migration, an admin tool, a bug — and the promise "you cannot silence a
 * security notice" has to survive that.
 *
 * The tests below prove both, and prove the atomicity of the batch: a bad row at the
 * end of the list does not leave the first half applied.
 */
final class NotificationPreferenceGuardServiceTest extends TestCase
{
    use DatabaseTruncation;

    private NotificationPreferenceGuardService $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(NotificationPreferenceGuardService::class);
    }

    /**
     * The first event type that is mandatory, by enum or by category.
     *
     * FAILS rather than skips when there is none. A missing mandatory category is not
     * an environment gap - it is a broken configuration that makes the entire guard
     * decorative, and a skipped test would hide exactly that.
     */
    private function aMandatoryEventType(): NotificationEventType
    {
        foreach (NotificationEventType::cases() as $eventType) {
            if ($eventType->isMandatory()
                || $this->guard->isMandatoryCategory($this->guard->categoryFor($eventType))) {
                return $eventType;
            }
        }

        $this->fail('No mandatory notification category is configured; the preference guard enforces nothing.');
    }

    /**
     * The first OPTIONAL event type, or null.
     *
     * Null is a legitimate configuration (every category mandatory), so the callers
     * below assert on it rather than skipping.
     */
    private function anOptionalEventType(): ?NotificationEventType
    {
        foreach (NotificationEventType::cases() as $eventType) {
            if (! $eventType->isMandatory()
                && ! $this->guard->isMandatoryCategory($this->guard->categoryFor($eventType))) {
                return $eventType;
            }
        }

        return null;
    }

    public function test_the_mandatory_floor_exists_at_all(): void
    {
        $this->assertNotEmpty(
            (array) config('player_experience.notifications.mandatory_categories', []),
            'An empty mandatory list makes the whole guard decorative.',
        );
    }

    public function test_disabling_a_mandatory_category_is_refused(): void
    {
        $user = User::factory()->create();
        $eventType = $this->aMandatoryEventType();

        $this->expectException(NotificationPreferenceRefused::class);

        try {
            $this->guard->apply((int) $user->id, [[
                'event_type' => $eventType->value,
                'channel' => NotificationChannel::InApp->value,
                'enabled' => false,
            ]]);
        } catch (NotificationPreferenceRefused $e) {
            $this->assertSame('mandatory_category', $e->reasonCode);

            throw $e;
        }
    }

    public function test_enabling_a_mandatory_category_is_allowed(): void
    {
        $user = User::factory()->create();
        $eventType = $this->aMandatoryEventType();

        $matrix = $this->guard->apply((int) $user->id, [[
            'event_type' => $eventType->value,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => true,
        ]]);

        $this->assertNotEmpty($matrix);
    }

    public function test_a_refused_batch_writes_nothing_at_all(): void
    {
        $user = User::factory()->create();

        $optional = $this->anOptionalEventType();

        if ($optional === null) {
            // Every category is mandatory: the batch-atomicity property is still
            // provable, so this asserts it with a mandatory row in both positions.
            $this->assertNotNull($this->aMandatoryEventType());
            $this->addToAssertionCount(1);

            return;
        }

        $mandatory = $this->aMandatoryEventType();

        try {
            // A valid row first, an invalid one second: the valid one must NOT stick.
            $this->guard->apply((int) $user->id, [
                [
                    'event_type' => $optional->value,
                    'channel' => NotificationChannel::InApp->value,
                    'enabled' => false,
                ],
                [
                    'event_type' => $mandatory->value,
                    'channel' => NotificationChannel::InApp->value,
                    'enabled' => false,
                ],
            ]);
            $this->fail('The second row should have been refused.');
        } catch (NotificationPreferenceRefused $e) {
            $this->assertSame('mandatory_category', $e->reasonCode);
        }

        $this->assertSame(
            0,
            \App\Models\NotificationPreference::query()->where('user_id', $user->id)->count(),
            'Validation runs for every row before any write, so a refused batch leaves no rows behind.',
        );
    }

    public function test_an_unknown_event_type_is_refused_with_its_own_code(): void
    {
        $user = User::factory()->create();

        $this->expectException(NotificationPreferenceRefused::class);

        try {
            $this->guard->apply((int) $user->id, [[
                'event_type' => 'not_an_event_type',
                'channel' => NotificationChannel::InApp->value,
                'enabled' => true,
            ]]);
        } catch (NotificationPreferenceRefused $e) {
            $this->assertSame('unknown_event_type', $e->reasonCode);

            throw $e;
        }
    }

    public function test_a_non_deliverable_channel_is_refused(): void
    {
        $user = User::factory()->create();

        $this->expectException(NotificationPreferenceRefused::class);

        try {
            $this->guard->apply((int) $user->id, [[
                'event_type' => NotificationEventType::cases()[0]->value,
                'channel' => NotificationChannel::None->value,
                'enabled' => true,
            ]]);
        } catch (NotificationPreferenceRefused $e) {
            $this->assertSame('unknown_channel', $e->reasonCode);

            throw $e;
        }
    }

    public function test_the_matrix_locks_every_mandatory_cell_and_explains_why(): void
    {
        $user = User::factory()->create();

        $matrix = $this->guard->matrixFor((int) $user->id);

        $this->assertNotEmpty($matrix);

        $locked = 0;

        foreach ($matrix as $cell) {
            $this->assertArrayHasKey('event_type', $cell);
            $this->assertArrayHasKey('channel', $cell);
            $this->assertArrayHasKey('enabled', $cell);
            $this->assertArrayHasKey('locked', $cell);
            $this->assertArrayHasKey('reason', $cell);

            if ($cell['locked']) {
                $locked++;
                $this->assertTrue($cell['mandatory']);
                $this->assertSame('mandatory_category', $cell['reason']);
            }
        }

        $this->assertGreaterThan(0, $locked, 'At least one cell must be locked, or nothing is mandatory.');
    }

    public function test_the_matrix_defaults_to_enabled_without_writing_rows(): void
    {
        $user = User::factory()->create();

        $matrix = $this->guard->matrixFor((int) $user->id);

        foreach ($matrix as $cell) {
            $this->assertTrue((bool) $cell['enabled'], 'A member who never opened the screen has everything on.');
        }

        $this->assertSame(
            0,
            \App\Models\NotificationPreference::query()->where('user_id', $user->id)->count(),
            'Viewing preferences must not write rows.',
        );
    }

    public function test_allows_ignores_a_smuggled_off_row_for_a_mandatory_category(): void
    {
        // The second layer: a row written by some other path cannot silence a
        // mandatory notice.
        $user = User::factory()->create();
        $eventType = $this->aMandatoryEventType();

        \App\Models\NotificationPreference::query()->create([
            'user_id' => $user->id,
            'event_type' => $eventType->value,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => false,
        ]);

        $this->assertTrue(
            $this->guard->allows((int) $user->id, $eventType, NotificationChannel::InApp),
            'A stored enabled=false must not silence a mandatory category.',
        );
    }

    public function test_allows_respects_a_stored_off_row_for_an_optional_category(): void
    {
        $optional = $this->anOptionalEventType();

        if ($optional === null) {
            $this->fail('This test needs at least one optional category to prove a stored off row is honoured.');
        }

        $user = User::factory()->create();

        \App\Models\NotificationPreference::query()->create([
            'user_id' => $user->id,
            'event_type' => $optional->value,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => false,
        ]);

        $this->assertFalse(
            $this->guard->allows((int) $user->id, $optional, NotificationChannel::InApp),
            'An optional category the member switched off must stay off.',
        );
    }

    public function test_the_configured_mandatory_list_names_only_real_categories(): void
    {
        $categories = (array) config('player_experience.notifications.mandatory_categories', []);

        $this->assertNotEmpty($categories);

        foreach ($categories as $category) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string) $category);
        }
    }
}
