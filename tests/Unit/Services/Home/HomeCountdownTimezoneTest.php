<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Home;

use App\Services\Home\HomeCountdownService;
use App\Services\Lottery\GloNextDrawService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Timezone boundary contract for the Home countdown.
 *
 * The countdown is a money-adjacent public surface: every draw deadline is an
 * Asia/Bangkok wall-clock instant, and the historical defect was the projection
 * silently coming back in UTC ("expected Asia/Bangkok, got UTC"). These cases
 * pin the two dangerous boundaries - Bangkok 23:59:59 and Bangkok 00:00:00 -
 * and prove the projection is byte-for-byte identical no matter what the PHP
 * process default timezone is, because every time object carries the business
 * timezone explicitly instead of inheriting the process default.
 *
 * Clock time is frozen with Carbon::setTestNow so remaining_seconds is an exact
 * integer; GloNextDrawService is mocked because this unit owns only the
 * adapter's projection rules.
 */
final class HomeCountdownTimezoneTest extends TestCase
{
    private const BANGKOK = 'Asia/Bangkok';

    /**
     * Deliberately far apart in offset and sign, so any hidden dependence on
     * the process default timezone produces different output.
     *
     * @var list<string>
     */
    private const DEFAULT_TIMEZONES = ['UTC', 'America/Los_Angeles', self::BANGKOK];

    private string $originalDefaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalDefaultTimezone);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    public static function bangkokBoundaryProvider(): array
    {
        return [
            'last second of the Bangkok day (23:59:59)' => [
                '2026-11-01 23:59:59', // target wall clock, Asia/Bangkok
                '2026-11-01 23:59:00', // frozen now wall clock, Asia/Bangkok
                59,                    // exact remaining seconds
                '2026-11-01T23:59:59+07:00',
                '1 Nov 2026, 23:59 +07',
            ],
            'first second of the next Bangkok day (00:00:00)' => [
                '2026-11-02 00:00:00',
                '2026-11-01 23:59:00',
                60,
                '2026-11-02T00:00:00+07:00',
                '2 Nov 2026, 00:00 +07',
            ],
        ];
    }

    #[DataProvider('bangkokBoundaryProvider')]
    public function test_boundary_projection_is_identical_under_every_process_default_timezone(
        string $bangkokTarget,
        string $bangkokNow,
        int $expectedRemainingSeconds,
        string $expectedIso,
        string $expectedFormatted,
    ): void {
        $targetInstant = CarbonImmutable::parse($bangkokTarget, self::BANGKOK);
        $results = [];

        foreach (self::DEFAULT_TIMEZONES as $defaultTimezone) {
            date_default_timezone_set($defaultTimezone);
            Carbon::setTestNow(CarbonImmutable::parse($bangkokNow, self::BANGKOK));

            $service = new HomeCountdownService($this->sourceReturning([
                'status' => 'AVAILABLE',
                'draw_number' => 'GLO-2026-11-01',
                'draw_status' => 'scheduled',
                'scheduled_at_iso' => $targetInstant->toIso8601String(),
                'timezone' => self::BANGKOK,
                'message' => null,
            ], 1));

            $results[$defaultTimezone] = $service->getNextDrawTarget();
        }

        $baseline = $results['UTC'];

        foreach (self::DEFAULT_TIMEZONES as $defaultTimezone) {
            $this->assertSame($baseline, $results[$defaultTimezone], sprintf(
                'The countdown projection changed with the PHP default timezone %s; all time math must be bound to %s.',
                $defaultTimezone,
                self::BANGKOK,
            ));
        }

        $this->assertSame('AVAILABLE', $baseline['status']);
        $this->assertTrue($baseline['has_next_draw']);
        $this->assertSame($expectedIso, $baseline['scheduled_at_iso']);
        $this->assertSame($expectedFormatted, $baseline['scheduled_at_formatted']);
        $this->assertSame($expectedRemainingSeconds, $baseline['remaining_seconds']);
        $this->assertSame(self::BANGKOK, $baseline['timezone']);
        $this->assertSame('GLO-2026-11-01', $baseline['draw_number']);
        $this->assertFalse($baseline['is_open_for_betting']);
    }

    #[DataProvider('bangkokBoundaryProvider')]
    public function test_legacy_next_draw_countdown_contract_matches_the_target_contract(
        string $bangkokTarget,
        string $bangkokNow,
        int $expectedRemainingSeconds,
        string $expectedIso,
        string $expectedFormatted,
    ): void {
        date_default_timezone_set('America/Los_Angeles');
        Carbon::setTestNow(CarbonImmutable::parse($bangkokNow, self::BANGKOK));

        $service = new HomeCountdownService($this->sourceReturning([
            'status' => 'AVAILABLE',
            'draw_number' => 'GLO-2026-11-01',
            'draw_status' => 'open',
            'scheduled_at_iso' => CarbonImmutable::parse($bangkokTarget, self::BANGKOK)->toIso8601String(),
            'timezone' => self::BANGKOK,
            'message' => null,
        ], 2));

        $countdown = $service->nextDrawCountdown();
        $target = $service->getNextDrawTarget();

        $this->assertSame($target, $countdown);
        $this->assertSame($expectedIso, $countdown['scheduled_at_iso']);
        $this->assertSame($expectedFormatted, $countdown['scheduled_at_formatted']);
        $this->assertSame($expectedRemainingSeconds, $countdown['remaining_seconds']);
        $this->assertTrue($countdown['is_open_for_betting']);
    }

    public function test_bangkok_midnight_target_is_the_correct_absolute_instant_not_a_local_midnight_shift(): void
    {
        // Bangkok midnight on 2 Nov 2026 is 17:00:00 UTC on 1 Nov 2026. A
        // timezone-naive implementation would render this as 00:00 UTC.
        $bangkokMidnight = CarbonImmutable::parse('2026-11-02 00:00:00', self::BANGKOK);
        $cases = [];

        foreach (self::DEFAULT_TIMEZONES as $defaultTimezone) {
            date_default_timezone_set($defaultTimezone);
            Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 23:59:00', self::BANGKOK));

            $service = new HomeCountdownService($this->sourceReturning([
                'status' => 'AVAILABLE',
                'draw_number' => 'GLO-2026-11-02',
                'draw_status' => 'scheduled',
                'scheduled_at_iso' => $bangkokMidnight->toIso8601String(),
                'timezone' => self::BANGKOK,
                'message' => null,
            ], 1));

            $cases[$defaultTimezone] = $service->getNextDrawTarget()['scheduled_at_iso'];
        }

        foreach ($cases as $defaultTimezone => $iso) {
            $asUtc = CarbonImmutable::parse($iso)->utc();

            $this->assertSame(
                '2026-11-01T17:00:00+00:00',
                $asUtc->toIso8601String(),
                sprintf('Under default timezone %s the Bangkok midnight instant shifted in UTC.', $defaultTimezone),
            );
            $this->assertSame(
                '2026-11-02T00:00:00+07:00',
                CarbonImmutable::parse($iso)->setTimezone(self::BANGKOK)->toIso8601String(),
            );
        }
    }

    public function test_unavailable_payload_still_advertises_bangkok_under_a_foreign_default_timezone(): void
    {
        date_default_timezone_set('UTC');
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 23:59:00', self::BANGKOK));

        // No timezone key at all: the adapter must fall back to the canonical
        // business timezone rather than the process default.
        $service = new HomeCountdownService($this->sourceReturning([
            'status' => 'UNAVAILABLE',
            'scheduled_at_iso' => null,
            'message' => 'No upcoming draw is scheduled.',
        ], 1));

        $result = $service->getNextDrawTarget();

        $this->assertSame('UNAVAILABLE', $result['status']);
        $this->assertFalse($result['has_next_draw']);
        $this->assertSame(0, $result['remaining_seconds']);
        $this->assertNull($result['scheduled_at_iso']);
        $this->assertNull($result['scheduled_at_formatted']);
        $this->assertSame(self::BANGKOK, $result['timezone']);
        $this->assertFalse($result['is_open_for_betting']);
    }

    public function test_a_past_target_clamps_remaining_seconds_to_zero_under_every_default_timezone(): void
    {
        $pastTarget = CarbonImmutable::parse('2026-11-01 00:00:00', self::BANGKOK);

        foreach (self::DEFAULT_TIMEZONES as $defaultTimezone) {
            date_default_timezone_set($defaultTimezone);
            Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 23:59:00', self::BANGKOK));

            $service = new HomeCountdownService($this->sourceReturning([
                'status' => 'AVAILABLE',
                'draw_number' => 'GLO-2026-11-01',
                'draw_status' => 'closed',
                'scheduled_at_iso' => $pastTarget->toIso8601String(),
                'timezone' => self::BANGKOK,
                'message' => null,
            ], 1));

            $result = $service->getNextDrawTarget();

            $this->assertSame(0, $result['remaining_seconds'], sprintf(
                'A past target must clamp to zero remaining seconds under default timezone %s.',
                $defaultTimezone,
            ));
            $this->assertSame(self::BANGKOK, $result['timezone']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sourceReturning(array $payload, int $expectedCalls): GloNextDrawService
    {
        $source = Mockery::mock(GloNextDrawService::class);
        $source->shouldReceive('nextDraw')->times($expectedCalls)->andReturn($payload);

        return $source;
    }
}
