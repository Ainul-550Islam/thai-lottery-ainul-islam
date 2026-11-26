<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Exceptions\LotteryGameException;
use App\Models\GameNumberExposure;
use App\Services\LotteryGames\LiabilityExposureService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * Two placements racing for the last unit of headroom.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM LiabilityExposureTest
 * Every rule about ceilings is a statement about CONCURRENT writers, and a single-threaded
 * test cannot falsify it. The classic defect is invisible to sequential tests and fatal in
 * production:
 *
 *     reader A: SELECT potential_payout_total ... -> 41,000, room left 9,000  (ok)
 *     reader B: SELECT potential_payout_total ... -> 41,000, room left 9,000  (ok)
 *     writer A: UPDATE ... 50,000
 *     writer B: UPDATE ... 50,000    <- 18,000 of obligation recorded as 9,000
 *
 * Both placements were individually correct against what they read. The operator is now
 * carrying twice the liability it believes it is carrying, on the one number that decides
 * whether the draw is survivable. So this suite exercises the real mechanism — separate
 * OS processes, separate database connections, genuinely simultaneous writes — and asserts
 * the property that matters:
 *
 *     THE SUM OF ACCEPTED RESERVATIONS NEVER EXCEEDS THE CEILING, WHATEVER THE INTERLEAVING.
 *
 * HOW THE RACE IS RUN
 * pcntl_fork gives each racer its own process and therefore its own connection; the child
 * reconnects (a forked process inherits a file handle it must not share) and then hammers
 * the same reserve() call. A bounded retry loop absorbs SQLite's SQLITE_BUSY, which is a
 * lock-contention signal, not an overspend: a retry that eventually succeeds or is refused
 * by the CAP is the outcome under test. The parent aggregates every child's verdict and
 * asserts on the totals, so a refusal is data rather than a failure.
 *
 * WHY THE UNIQUE KEY IS TESTED DIRECTLY
 * The lock is the correctness mechanism; UNIQUE(draw_id, number, bet_type) is the second
 * line of defence that makes "two counter rows for one number" impossible even if a future
 * writer forgets the lock. It is cheap to assert and it is the constraint the whole design
 * leans on.
 *
 * SKIPPING IS EXPLICIT AND LOUD
 * Without pcntl (or on an in-memory database, which cannot be shared across processes) the
 * race cannot be constructed at all. The test then marks itself skipped WITH THE REASON,
 * rather than passing silently as though the property had been proven.
 */
final class LiabilityConcurrencyTest extends GameLaneTestCase
{
    /** Each racer reserves one bet worth this much potential payout: 10.00 at 900x. */
    private const RACER_PAYOUT = '9000.00';

    private const RACER_STAKE = '10.00';

    private function exposure(): LiabilityExposureService
    {
        return app(LiabilityExposureService::class);
    }

    /**
     * @return array{draw: \App\Models\Draw}
     */
    private function game(string $perNumberCap = '50000.00'): array
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'max_liability_per_number' => $perNumberCap,
            'max_liability_per_draw' => '1000000.00',
        ]));

        return ['draw' => $this->openDraw('lane_game')];
    }

    // =========================================================================
    // The invariant under simultaneous writers
    // =========================================================================

    public function test_simultaneous_reservations_on_one_number_never_exceed_the_ceiling(): void
    {
        ['draw' => $draw] = $this->game('50000.00');

        $this->requireForkedRace();

        $racers = 8;                 // 8 x 9,000 = 72,000 against a 50,000 ceiling
        $expectedAccepted = 5;       // floor(50,000 / 9,000)
        $results = $this->race($draw->getKey(), 'lane_game', $racers);

        $accepted = count(array_filter($results, static fn (string $outcome): bool => $outcome === 'accepted'));
        $refused = count(array_filter($results, static fn (string $outcome): bool => $outcome === 'game_liability_exceeded'));

        $this->assertSame($racers, count($results), 'every racer must report exactly one verdict');
        $this->assertSame($expectedAccepted, $accepted, 'exactly the bets that fit under the ceiling were accepted');
        $this->assertSame($racers - $expectedAccepted, $refused, 'every other racer was refused by the cap');
        $this->assertSame([], array_filter(
            $results,
            static fn (string $outcome): bool => ! in_array($outcome, ['accepted', 'game_liability_exceeded'], true),
        ), 'no racer may fail for any other reason (a lock must retry, not surface)');

        // The counter is exactly the accepted total: not one satang more.
        $row = $this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct');

        $this->assertSame('45000.00', $row->potentialPayoutTotal());
        $this->assertSame('50000.00', $this->exposure()->headroom($draw->getKey(), 'lane_game')['per_number_cap']);
        $this->assertSame('5000.00', $this->exposure()->headroom($draw->getKey(), 'lane_game')['numbers']['3d_direct/123']['remaining']);

        // The version is the number of accepted increments: proof that no update was lost.
        $this->assertSame($expectedAccepted, (int) $row->version);
    }

    public function test_simultaneous_reservations_across_numbers_never_exceed_the_draw_ceiling(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'max_liability_per_number' => '50000.00',
            'max_liability_per_draw' => '27000.00',   // exactly three racers' worth
        ]));
        $draw = $this->openDraw('lane_game');

        $this->requireForkedRace();

        // Different numbers, so the per-number cap never binds; only the draw total does.
        $results = $this->race($draw->getKey(), 'lane_game', 6, distinctNumbers: true);

        $accepted = count(array_filter($results, static fn (string $outcome): bool => $outcome === 'accepted'));

        $this->assertSame(3, $accepted, 'exactly three racers fit under the draw ceiling');
        $this->assertSame('27000.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
        $this->assertSame('0.00', $this->exposure()->headroom($draw->getKey(), 'lane_game')['draw_remaining']);
    }

    // =========================================================================
    // The structural guarantees
    // =========================================================================

    public function test_two_counter_rows_can_never_exist_for_one_number(): void
    {
        ['draw' => $draw] = $this->game();

        $this->exposure()->reserve($draw->getKey(), 'lane_game', [[
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => self::RACER_STAKE,
        ]]);

        // The lock prevents this in practice; the unique key makes it impossible in fact.
        $this->expectException(QueryException::class);

        GameNumberExposure::query()->create([
            'draw_id' => $draw->getKey(),
            'game_code' => 'lane_game',
            'number' => '123',
            'bet_type' => '3d_direct',
            'stake_total' => '0.00',
            'potential_payout_total' => '0.00',
            'version' => 0,
        ]);
    }

    public function test_increments_from_two_connections_are_both_visible_and_counted(): void
    {
        ['draw' => $draw] = $this->game();

        // A second connection to the same database: the interleaving is sequential here,
        // but the point is that neither connection holds a stale read across the other's
        // commit, because the counter total is recomputed under lock on every write.
        config()->set('database.connections.lane_second', config('database.connections.'.config('database.default')));
        $second = DB::connection('lane_second');

        $this->exposure()->reserve($draw->getKey(), 'lane_game', [[
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => self::RACER_STAKE,
        ]]);

        $seenBySecond = $second->table('game_number_exposures')
            ->where('draw_id', $draw->getKey())
            ->where('number', '123')
            ->value('potential_payout_total');

        // sqlite hands the column back without trailing scale; the amount is normalised
        // with bcmath rather than trusted to survive the round trip as a formatted string.
        $this->assertSame('9000.00', bcadd((string) $seenBySecond, '0', 2));

        $this->exposure()->reserve($draw->getKey(), 'lane_game', [[
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => self::RACER_STAKE,
        ]]);

        $this->assertSame('18000.00', bcadd((string) $second->table('game_number_exposures')
            ->where('draw_id', $draw->getKey())
            ->where('number', '123')
            ->value('potential_payout_total'), '0', 2));
    }

    public function test_a_release_racing_an_increase_never_drives_the_counter_negative(): void
    {
        ['draw' => $draw] = $this->game();

        $this->exposure()->reserve($draw->getKey(), 'lane_game', [[
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => self::RACER_STAKE,
        ]]);

        $this->requireForkedRace();

        // Both operations released and re-reserved in separate processes: whatever the
        // order, the counter must end at a value the caps allow and never below zero.
        $outcomes = $this->releaseAndReserveRace($draw->getKey(), 'lane_game');

        $this->assertContains('ok', $outcomes);

        $row = $this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct');

        if ($row instanceof GameNumberExposure) {
            $this->assertGreaterThanOrEqual(0, bccomp($row->potentialPayoutTotal(), '0', 2));
            $this->assertLessThanOrEqual(0, bccomp($row->potentialPayoutTotal(), '50000.00', 2));
        } else {
            $this->assertNull($row, 'a row that was fully released is deleted, not driven negative');
        }
    }

    // =========================================================================
    // The race harness
    // =========================================================================

    /**
     * Run $racyers forked processes, each attempting ONE reservation, and collect their
     * verdicts as error codes.
     *
     * @return list<string>
     */
    private function race(int $drawId, string $gameCode, int $racers, bool $distinctNumbers = false): array
    {
        $directory = storage_path('framework/testing/lane-race-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $verdictFile = $directory.'/verdicts.jsonl';
        touch($verdictFile);

        $children = [];

        for ($index = 0; $index < $racers; $index++) {
            $number = $distinctNumbers
                ? str_pad((string) (100 + $index), 3, '0', STR_PAD_LEFT)
                : '123';

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('could not fork a racer');
            }

            if ($pid === 0) {
                // CHILD: never inherit the parent's connection.
                $this->childReserve($drawId, $gameCode, $number, $verdictFile);
                exit(0);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $verdicts = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) file_get_contents($verdictFile)),
        )));

        @unlink($verdictFile);
        @rmdir($directory);

        return $verdicts;
    }

    /**
     * One reservation attempt in a child process, retrying only on lock contention.
     */
    private function childReserve(int $drawId, string $gameCode, string $number, string $verdictFile): void
    {
        DB::purge();
        DB::reconnect();
        DB::statement('PRAGMA busy_timeout = 15000');

        $outcome = 'gave_up';

        for ($attempt = 1; $attempt <= 25; $attempt++) {
            try {
                app(LiabilityExposureService::class)->reserve($drawId, $gameCode, [[
                    'number' => $number,
                    'bet_type' => '3d_direct',
                    'stake' => self::RACER_STAKE,
                ]]);

                $outcome = 'accepted';

                break;
            } catch (LotteryGameException $e) {
                // A cap refusal is a legitimate verdict, not a lock problem.
                $outcome = $e->errorCode();

                break;
            } catch (\Throwable $e) {
                if (! $this->isLockContention($e)) {
                    $outcome = 'error:'.get_class($e).':'.$e->getMessage();

                    break;
                }

                usleep(20_000);
            }
        }

        file_put_contents($verdictFile, $outcome."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * A release and a reserve in two processes, to prove the counter cannot invert.
     *
     * @return list<string>
     */
    private function releaseAndReserveRace(int $drawId, string $gameCode): array
    {
        $directory = storage_path('framework/testing/lane-race-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $verdictFile = $directory.'/verdicts.jsonl';
        touch($verdictFile);

        $children = [];

        foreach (['release', 'reserve'] as $action) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                DB::statement('PRAGMA busy_timeout = 15000');

                $outcome = 'error';

                for ($attempt = 1; $attempt <= 25; $attempt++) {
                    try {
                        if ($action === 'release') {
                            app(LiabilityExposureService::class)->release($drawId, $gameCode, [[
                                'number' => '123',
                                'bet_type' => '3d_direct',
                                'stake' => self::RACER_STAKE,
                                'potential_payout' => self::RACER_PAYOUT,
                            ]]);
                        } else {
                            app(LiabilityExposureService::class)->reserve($drawId, $gameCode, [[
                                'number' => '123',
                                'bet_type' => '3d_direct',
                                'stake' => self::RACER_STAKE,
                            ]]);
                        }

                        $outcome = 'ok';

                        break;
                    } catch (LotteryGameException $e) {
                        $outcome = $e->errorCode();

                        break;
                    } catch (\Throwable $e) {
                        if (! $this->isLockContention($e)) {
                            $outcome = 'error:'.$e->getMessage();

                            break;
                        }

                        usleep(20_000);
                    }
                }

                file_put_contents($verdictFile, $outcome."\n", FILE_APPEND | LOCK_EX);
                exit(0);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $verdicts = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) file_get_contents($verdictFile)),
        )));

        @unlink($verdictFile);
        @rmdir($directory);

        return $verdicts;
    }

    /**
     * Whether a throwable is the database telling us to come back later.
     */
    private function isLockContention(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'locked')
            || str_contains($message, 'busy')
            || str_contains($message, 'deadlock');
    }

    /**
     * The race needs processes and a database file they can share.
     */
    private function requireForkedRace(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available, so the race cannot be constructed in this environment.');
        }

        if (str_contains((string) DB::connection()->getDatabaseName(), ':memory:')) {
            $this->markTestSkipped('an in-memory database cannot be shared by two processes, so the race cannot be constructed.');
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('the race harness in this suite is written against sqlite lock semantics.');
        }

        DB::statement('PRAGMA busy_timeout = 15000');
    }
}
