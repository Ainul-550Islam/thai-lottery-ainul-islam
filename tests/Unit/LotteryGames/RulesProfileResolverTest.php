<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\RulesProfileResolver;
use Tests\TestCase;

/**
 * Which rule set is in force, and the three refusals that keep a reference set from ever
 * being mistaken for an official one.
 *
 * The resolver's layering is tested as a pure function (applyLayers) plus the two decisions
 * that need the environment (a profile in production) and the filesystem (a malformed
 * override). No database, no money, no HTTP.
 */
final class RulesProfileResolverTest extends TestCase
{
    private function resolver(): RulesProfileResolver
    {
        return app(RulesProfileResolver::class);
    }

    /**
     * A minimal "declared" block full of placeholders, exactly like the shipped config.
     *
     * @return array<string, array<string, mixed>>
     */
    private function base(): array
    {
        return [
            'speed_88' => [
                'code' => 'speed_88',
                'name' => 'Speed 88',
                'digits' => GameDefinition::REQUIRED_INPUT,
                'bet_types' => ['4d_direct' => ['payout_multiplier' => GameDefinition::REQUIRED_INPUT]],
                'rules_status' => 'unverified',
            ],
        ];
    }

    /**
     * A complete block: nothing left as REQUIRED_INPUT.
     *
     * @return array<string, mixed>
     */
    private function complete(string $marker = 'ok'): array
    {
        return [
            'code' => 'speed_88',
            'name' => 'Speed 88',
            'digits' => 4,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['15 21 * * *'],
            'cutoff_seconds' => 120,
            'bet_types' => [
                '4d_direct' => [
                    'selection_digits' => 4,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '7000',
                ],
            ],
            'min_stake' => '10.00',
            'max_stake' => '1000.00',
            'max_liability_per_draw' => '500000.00',
            'max_liability_per_number' => '50000.00',
            'rules_status' => 'verified',
            'settlement_market_key' => '3d_direct',
            'publication_bridge' => ['first_prize' => 'digits_left_padded', 'bottom_two' => 'last:2'],
            'result_source' => ['driver' => 'official'],
            '_marker' => $marker,
        ];
    }

    public function test_with_no_layers_the_declared_configuration_is_returned_untouched(): void
    {
        $resolved = $this->resolver()->applyLayers($this->base(), null, null);

        $this->assertSame([], $resolved['applied']);
        $this->assertSame(GameDefinition::REQUIRED_INPUT, $resolved['games']['speed_88']['digits']);
    }

    public function test_a_complete_profile_replaces_the_whole_block_and_can_never_claim_verification(): void
    {
        $profile = ['speed_88' => $this->complete()];

        $resolved = $this->resolver()->applyLayers($this->base(), $profile, null);

        $this->assertSame('profile', $resolved['applied']['speed_88']);
        $this->assertSame(4, $resolved['games']['speed_88']['digits']);
        $this->assertSame(
            'unverified',
            $resolved['games']['speed_88']['rules_status'],
            'a reference set must not be able to declare itself verified; only the audited row can',
        );
    }

    public function test_an_incomplete_layer_is_refused_whole_rather_than_merged_field_by_field(): void
    {
        $partial = ['speed_88' => ['digits' => 4, 'cutoff_seconds' => 120]];

        $resolved = $this->resolver()->applyLayers($this->base(), $partial, null);

        $this->assertSame([], $resolved['applied'], 'a partial layer is never applied');
        $this->assertSame(
            GameDefinition::REQUIRED_INPUT,
            $resolved['games']['speed_88']['digits'],
            'the block must be exactly the declared one, not a blend of the two',
        );
        $this->assertNotSame([], $resolved['notes']);
        $this->assertStringContainsString('REQUIRED_INPUT', implode(' ', $resolved['notes']));
    }

    public function test_a_layer_may_not_introduce_a_game_the_deployment_never_declared(): void
    {
        $resolved = $this->resolver()->applyLayers(
            $this->base(),
            ['ghost_game' => $this->complete()],
            null,
        );

        $this->assertArrayNotHasKey('ghost_game', $resolved['games']);
        $this->assertSame([], $resolved['applied']);
        $this->assertStringContainsString('not declared', implode(' ', $resolved['notes']));
    }

    public function test_the_operator_override_wins_over_a_reference_profile(): void
    {
        $resolved = $this->resolver()->applyLayers(
            $this->base(),
            ['speed_88' => $this->complete('profile')],
            ['speed_88' => $this->complete('override')],
        );

        $this->assertSame('override', $resolved['applied']['speed_88']);
        $this->assertSame('override', $resolved['games']['speed_88']['_marker']);
    }

    public function test_a_profile_is_refused_outright_when_the_environment_is_production(): void
    {
        // A profile ships inside config/, so the guard has to be code rather than a comment
        // asking operators to be careful.
        $this->app['env'] = 'production';

        config()->set('lottery_games.profile', 'uat');

        $resolved = $this->resolver()->resolve();

        $this->assertSame([], $resolved['applied'], 'no profile may be applied in production');
        $this->assertStringContainsString('refused in production', implode(' ', $resolved['notes']));
        $this->assertSame(
            GameDefinition::REQUIRED_INPUT,
            $resolved['games']['speed_88']['digits'],
            'production keeps the declared, fail-closed configuration',
        );
    }

    public function test_a_malformed_override_file_is_ignored_rather_than_fatal(): void
    {
        $path = $this->resolver()->overridePath();
        $this->assertNotNull($path);

        if (! is_dir(dirname((string) $path))) {
            mkdir(dirname((string) $path), 0775, true);
        }

        file_put_contents((string) $path, '{"games": {broken json');

        try {
            $resolved = $this->resolver()->resolve();

            $this->assertSame([], $resolved['applied']);
            $this->assertStringContainsString('not valid JSON', implode(' ', $resolved['notes']));
        } finally {
            @unlink((string) $path);
        }
    }
}
