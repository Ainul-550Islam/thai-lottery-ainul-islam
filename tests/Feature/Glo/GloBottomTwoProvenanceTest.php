<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Services\Lottery\GloFixtureResultProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Part 72 — where the two-digit number comes from, and when it must be absent.
 *
 * THE DEFECT
 * `DrawResultIngestionService` refuses a result with no `bottom_two`, and it
 * refuses to derive one from the first prize. That rule is correct and it stays:
 * the two are drawn separately, and published draws give first prize 730640 with
 * a bottom two of 28, 287184 with 48, 074646 with 58. Deriving it would settle
 * the two-digit market against a number the GLO never drew.
 *
 * But neither GLO provider ever set the field. Both parsed the source's
 * `last_two` entry into `tiers.last_two` and stopped there, so every GLO import
 * reached ingestion without `bottom_two` and was refused as malformed — the
 * eight errors in `GloResultImportTest` were all
 * `bottom_two is required and is absent for first_prize […]`. The platform could
 * not ingest a real announcement, and the operator was told correct data was
 * wrong.
 *
 * WHAT IS PROVEN
 *  1. The payload now carries `bottom_two`, taken from the source's own
 *     two-digit entry.
 *  2. It is NOT the last two digits of the first prize: the case where the two
 *     differ is asserted with numbers where deriving would give a different
 *     answer, because that is the exact mistake the ingestion rule exists to
 *     stop.
 *  3. When the source carries no two-digit entry — or more than one — the field
 *     is null, so ingestion refuses rather than picking one.
 *  4. `bottom_two` and `tiers.last_two` cannot disagree: they are read once.
 */
final class GloBottomTwoProvenanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_fixture_provider_carries_the_two_digit_number_it_was_given(): void
    {
        $payload = $this->fixturePayload([
            'first_prize' => '730640',
            'last_two' => ['28'],
        ]);

        $this->assertSame('imported', $payload['status']);
        $this->assertSame('28', $payload['bottom_two']);

        // Deriving it from the first prize would have produced '40'. That is the
        // whole point of carrying the field: 730640 was published with 28.
        $this->assertNotSame(substr((string) $payload['first_prize'], -2), $payload['bottom_two']);
    }

    #[Test]
    public function the_two_digit_field_and_the_tier_list_are_the_same_read(): void
    {
        $payload = $this->fixturePayload([
            'first_prize' => '287184',
            'last_two' => ['48'],
        ]);

        $this->assertSame('48', $payload['bottom_two']);
        $this->assertSame(['48'], $payload['tiers']['last_two']);
    }

    #[Test]
    public function a_source_with_no_two_digit_entry_leaves_the_field_absent(): void
    {
        $payload = $this->fixturePayload([
            'first_prize' => '074646',
            'last_two' => [],
        ]);

        // Null, not '46'. The ingestion refuses this draw's two-digit market
        // until the real number is supplied — which is the correct outcome.
        $this->assertNull($payload['bottom_two']);
        $this->assertSame([], $payload['tiers']['last_two']);
    }

    #[Test]
    public function an_ambiguous_two_digit_entry_is_not_resolved_by_picking_one(): void
    {
        $payload = $this->fixturePayload([
            'first_prize' => '074646',
            'last_two' => ['58', '17'],
        ]);

        $this->assertNull(
            $payload['bottom_two'],
            'two candidate numbers means the platform does not know which one was drawn',
        );
        $this->assertSame(['58', '17'], $payload['tiers']['last_two']);
    }

    #[Test]
    public function the_official_provider_applies_the_same_rule(): void
    {
        // The official provider reads the same source shape. Its HTTP transport
        // cannot be reached from this environment, so what is asserted here is
        // that the mapping exists and keeps the same ambiguity rule — a source
        // change that dropped it would otherwise only show up in production.
        $source = (string) file_get_contents(
            base_path('app/Services/Lottery/GloOfficialResultProvider.php'),
        );

        $this->assertStringContainsString("'bottom_two' => \$bottomTwo", $source);
        $this->assertStringContainsString(
            'count($lastTwo) === 1 ? $lastTwo[0] : null',
            $source,
            'the official provider must leave the field absent when the source is ambiguous',
        );
    }

    /**
     * A fixture payload built from the given source body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function fixturePayload(array $body): array
    {
        $dir = storage_path('app/glo-bottom-two-test');

        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        // The fixture provider always reads this one filename from the
        // configured directory; the draw number is carried in the body.
        $file = $dir.'/lottery_result.json';
        file_put_contents($file, (string) json_encode($body));

        Config::set('glo.official_source.fixture_path', $dir);

        try {
            $payload = app(GloFixtureResultProvider::class)->fetch('DRAW-72');
        } finally {
            @unlink($file);
            @rmdir($dir);
        }

        return $payload;
    }
}
