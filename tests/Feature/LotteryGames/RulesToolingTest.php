<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\RulesProfileResolver;
use Tests\TestCase;

/**
 * The two commands that turn "rules are outstanding" into "rules are installed".
 *
 * WHY THIS IS A FEATURE TEST AND NOT A UNIT TEST
 * The value of `rules:worksheet` and `rules:import` is that they agree with the GATE: the
 * worksheet lists exactly what validate-rules would fail on, and an import that the importer
 * accepts must make validate-rules pass. Asserting that agreement needs the real commands,
 * the real resolver layer and the real gate — a unit test of each piece separately would let
 * the three disagree while every test stayed green.
 *
 * The whole scenario below is the operator's actual path: run the worksheet, fill the
 * skeleton, import it, run the gate. It is the proof that the lane can be completed without
 * a single code change.
 */
final class RulesToolingTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }

        $this->cleanup = [];

        parent::tearDown();
    }

    private function overridePath(): string
    {
        $path = (string) app(RulesProfileResolver::class)->overridePath();

        $this->cleanup[] = $path;

        return $path;
    }

    /**
     * The document an operator would type, keyed the way the worksheet skeleton is.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'games' => [
                'speed_88' => [
                    'digits' => 4,
                    'draw_cron' => ['15 21 * * *'],
                    'cutoff_seconds' => 120,
                    'bet_types' => [
                        '4d_direct' => [
                            'selection_digits' => 4,
                            'match_mode' => 'exact',
                            'payout_multiplier' => '7000',
                        ],
                        '4d_any_order' => [
                            'selection_digits' => 4,
                            'match_mode' => 'any_order',
                            'payout_multiplier' => '240',
                        ],
                        '3d_direct' => [
                            'selection_digits' => 3,
                            'match_mode' => 'exact',
                            'payout_multiplier' => '900',
                        ],
                        '2d_direct' => [
                            'selection_digits' => 2,
                            'match_mode' => 'suffix',
                            'payout_multiplier' => '90',
                        ],
                    ],
                    'min_stake' => '10.00',
                    'max_stake' => '1000.00',
                    'max_liability_per_draw' => '500000.00',
                    'max_liability_per_number' => '50000.00',
                    'settlement_market_key' => '3d_direct',
                    'publication_bridge' => [
                        'first_prize' => 'digits_left_padded',
                        'bottom_two' => 'last:2',
                    ],
                    'result_source' => [
                        'driver' => 'official',
                        'base_url' => 'https://results.example.com/api',
                        'latest_path' => '/latest',
                        'by_draw_no_path' => '/draw/{draw_no}',
                        'draw_no_field' => 'draw_no',
                        'digits_field' => 'digits',
                        'drawn_at_field' => 'drawn_at',
                        'schema_version' => 'v1',
                    ],
                ],
            ],
        ];
    }

    public function test_the_worksheet_names_every_outstanding_value_with_its_source(): void
    {
        $this->artisan('lottery-games:rules:worksheet', ['--game' => 'speed_88'])
            ->expectsOutputToContain('digits')
            ->expectsOutputToContain('cutoff_seconds')
            ->expectsOutputToContain('official rules document')
            ->assertExitCode(0);
    }

    public function test_the_worksheet_writes_a_skeleton_the_importer_accepts(): void
    {
        $skeleton = sys_get_temp_dir().'/lane-skeleton-'.bin2hex(random_bytes(4)).'.json';
        $this->cleanup[] = $skeleton;

        $this->artisan('lottery-games:rules:worksheet', ['--write' => $skeleton])
            ->assertExitCode(0);

        $this->assertFileExists($skeleton);

        $decoded = json_decode((string) file_get_contents($skeleton), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('games', $decoded);
        $this->assertArrayHasKey('speed_88', $decoded['games']);
        $this->assertSame(
            GameDefinition::REQUIRED_INPUT,
            $decoded['games']['speed_88']['digits'],
            'the skeleton asks for the value rather than guessing one',
        );
    }

    public function test_require_complete_fails_while_anything_is_outstanding(): void
    {
        $this->artisan('lottery-games:rules:worksheet', ['--require-complete' => true])
            ->assertExitCode(1);
    }

    public function test_an_incomplete_document_is_refused_and_nothing_is_written(): void
    {
        $path = sys_get_temp_dir().'/lane-bad-'.bin2hex(random_bytes(4)).'.json';
        $this->cleanup[] = $path;

        file_put_contents($path, (string) json_encode([
            'games' => ['speed_88' => ['digits' => 4]], // nothing else: still a placeholder rule set
        ]));

        $override = $this->overridePath();
        @unlink($override);

        $this->artisan('lottery-games:rules:import', ['file' => $path])
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($override, 'a refused import must not leave a partial override behind');
    }

    public function test_a_dry_run_reports_the_result_and_writes_nothing(): void
    {
        $path = sys_get_temp_dir().'/lane-dry-'.bin2hex(random_bytes(4)).'.json';
        $this->cleanup[] = $path;

        file_put_contents($path, (string) json_encode($this->document()));

        $override = $this->overridePath();
        @unlink($override);

        $this->artisan('lottery-games:rules:import', ['file' => $path, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($override);
    }

    public function test_an_accepted_import_installs_the_rules_and_the_gate_then_passes(): void
    {
        $path = sys_get_temp_dir().'/lane-good-'.bin2hex(random_bytes(4)).'.json';
        $this->cleanup[] = $path;

        file_put_contents($path, (string) json_encode($this->document()));

        $override = $this->overridePath();
        @unlink($override);

        // The gate is red before the import: the shipped configuration is placeholders.
        $this->artisan('lottery-games:validate-rules', ['game' => 'speed_88'])
            ->assertExitCode(1);

        $this->artisan('lottery-games:rules:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertFileExists($override);

        // THE OPERATOR'S PATH, END TO END: the resolver picks the override up on the next
        // "request" (here: an explicit re-resolve), and the gate that was red is now green.
        app(RulesProfileResolver::class)->applyToRuntimeConfig();
        app()->forgetInstance(GameRegistry::class);

        $stored = app(RulesProfileResolver::class)->overrideGames();

        $this->assertIsArray($stored);
        $this->assertArrayHasKey('speed_88', $stored);
        $this->assertSame(
            'unverified',
            $stored['speed_88']['rules_status'],
            'an imported file never verifies itself: that stays an audited operator action',
        );

        $this->artisan('lottery-games:validate-rules', ['game' => 'speed_88'])
            ->assertExitCode(0);

        // The other game was not in the document and must be untouched, still fail-closed.
        $this->artisan('lottery-games:validate-rules', ['game' => 'lao_hanoi_4d'])
            ->assertExitCode(1);
    }

    public function test_the_uat_profile_makes_both_games_complete_without_touching_the_override(): void
    {
        $override = $this->overridePath();
        @unlink($override);

        config()->set('lottery_games.profile', 'uat');

        $resolved = app(RulesProfileResolver::class)->resolve();

        $this->assertSame('profile', $resolved['applied']['speed_88'] ?? null);
        $this->assertSame('profile', $resolved['applied']['lao_hanoi_4d'] ?? null);

        app(RulesProfileResolver::class)->applyToRuntimeConfig();
        app()->forgetInstance(GameRegistry::class);

        $this->artisan('lottery-games:validate-rules')->assertExitCode(0);

        $this->assertFileDoesNotExist($override, 'a profile is configuration, not an operator document');
    }
}
