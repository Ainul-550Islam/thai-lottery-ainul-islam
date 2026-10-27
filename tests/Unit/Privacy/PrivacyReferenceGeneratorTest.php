<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Models\DataSubjectRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reference a person quotes when they phone the compliance desk.
 *
 * It has to be: stable (the same row always produces the same string, or a retry
 * mints a second one), unique (it is the route key), short enough to read over a
 * phone, and free of anything about the person. `PRV-<year>-<id>` satisfies all
 * four; a random token would satisfy the last two badly and the first not at all.
 */
final class PrivacyReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_format_is_prefix_year_and_a_six_digit_number(): void
    {
        $reference = DataSubjectRequest::buildReference(42, Carbon::parse('2026-10-25 09:00:00'));

        $this->assertSame('PRV-2026-000042', $reference);
        $this->assertMatchesRegularExpression('/^PRV-\d{4}-\d{6}$/', $reference);
    }

    public function test_the_same_row_and_moment_always_produce_the_same_reference(): void
    {
        $at = Carbon::parse('2026-10-25 09:00:00');

        $this->assertSame(
            DataSubjectRequest::buildReference(7, $at),
            DataSubjectRequest::buildReference(7, $at),
            'The generator must be deterministic; a retried write must not mint a second reference.',
        );
    }

    public function test_ids_beyond_six_digits_still_produce_a_readable_reference(): void
    {
        $this->assertSame('PRV-2026-1000000', DataSubjectRequest::buildReference(1000000, Carbon::parse('2026-01-01')));
    }

    public function test_padding_and_ordering_keep_references_sortable(): void
    {
        $at = Carbon::parse('2026-10-25');

        $this->assertLessThan(
            DataSubjectRequest::buildReference(10, $at),
            DataSubjectRequest::buildReference(9, $at),
        );
    }

    public function test_a_stored_request_carries_the_reference_of_its_own_id(): void
    {
        $request = DataSubjectRequest::factory()->create();

        $this->assertSame(
            DataSubjectRequest::buildReference((int) $request->getKey(), $request->created_at),
            $request->reference,
        );
        $this->assertStringStartsWith('PRV-', (string) $request->reference);
        $this->assertNotSame((string) $request->uuid, (string) $request->reference);
    }

    public function test_the_route_key_is_the_reference_not_the_id(): void
    {
        $this->assertSame('reference', (new DataSubjectRequest)->getRouteKeyName());
    }

    public function test_two_requests_never_share_a_reference(): void
    {
        $a = DataSubjectRequest::factory()->create();
        $b = DataSubjectRequest::factory()->create();

        $this->assertNotSame($a->reference, $b->reference);

        // And the database agrees: the column is unique, so a colliding insert
        // fails rather than producing two rows a lookup could confuse.
        $this->assertSame(
            2,
            DataSubjectRequest::query()->whereIn('reference', [$a->reference, $b->reference])->count(),
        );

        $indexes = collect(DB::select("PRAGMA index_list('data_subject_requests')"));

        $unique = $indexes->contains(function (object $index): bool {
            if (! (bool) ($index->unique ?? false)) {
                return false;
            }

            $columns = collect(DB::select("PRAGMA index_info('".$index->name."')"))
                ->pluck('name')
                ->all();

            return in_array('reference', $columns, true);
        });

        $this->assertTrue($unique, 'data_subject_requests.reference must be uniquely indexed.');
    }

    public function test_the_reference_reveals_nothing_about_the_person(): void
    {
        // A second account, so the user id and the request id cannot coincide
        // and the assertion below means what it says.
        \App\Models\User::factory()->create();
        $user = \App\Models\User::factory()->create(['email' => 'someone@example.test']);

        $request = DataSubjectRequest::factory()->create([
            'user_id' => (int) $user->getKey(),
            'email_hash' => DataSubjectRequest::hashEmail('someone@example.test'),
        ]);

        $this->assertStringNotContainsString('someone', (string) $request->reference);
        $this->assertStringNotContainsString((string) $request->email_hash, (string) $request->reference);
        $this->assertStringNotContainsString('@', (string) $request->reference);

        // It names the REQUEST, not the person behind it.
        $this->assertStringContainsString(
            sprintf('%06d', (int) $request->getKey()),
            (string) $request->reference,
        );
        $this->assertSame(
            DataSubjectRequest::buildReference((int) $request->getKey(), $request->created_at),
            $request->reference,
        );
    }
}
