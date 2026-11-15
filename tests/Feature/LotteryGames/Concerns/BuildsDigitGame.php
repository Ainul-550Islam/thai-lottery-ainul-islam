<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames\Concerns;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\GameRulesStatus;
use App\Models\Draw;
use App\Models\LotteryGame;
use App\Models\User;
use App\Services\LotteryGames\GameRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one place the batch-2 tests build a COMPLETE, self-consistent fake rule set.
 *
 * WHY A FAKE RULE SET IS LEGITIMATE HERE
 * The shipped configuration is deliberately full of REQUIRED_INPUT: no operator has
 * supplied the official rules. A test that needed the real rules could therefore never
 * run, and the framework's behaviour would be untestable until launch. The fake set
 * below is clearly labelled as such, uses the EXISTING market keys (2d_top / 3d_direct)
 * so the existing settlement resolver is genuinely exercised, and never leaks into a
 * production config.
 */
trait BuildsDigitGame
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function completeGameConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'code' => 'speed_88',
            'name' => 'Speed 88 (test rules)',
            'digits' => 3,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['0 21 * * *'],
            'cutoff_seconds' => 300,
            'bet_types' => [
                '2d_direct' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90',
                    'market_key' => '2d_top',
                ],
                '3d_direct' => [
                    'selection_digits' => 3,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '900',
                    'market_key' => '3d_direct',
                ],
            ],
            'min_stake' => '1.00',
            'max_stake' => '1000.00',
            'max_liability_per_draw' => '100000.00',
            'max_liability_per_number' => '10000.00',
            'rules_status' => 'verified',
            'settlement_market_key' => '2d_top',
            // The bridge maps the 3-digit draw onto the boundary's shape: first_prize
            // is the whole string (3 digits) and bottom_two the trailing two. The
            // boundary validates those lengths itself, so a wrong mapping is refused
            // there rather than accepted here.
            'publication_bridge' => [
                'first_prize' => 'all',
                'bottom_two' => 'last:2',
            ],
            'result_source' => [
                'driver' => 'official',
                'base_url' => 'https://results.example.test',
                'latest_path' => '/v1/latest',
                'by_draw_no_path' => '/v1/draw/{draw_no}',
                'draw_no_field' => 'data.draw_no',
                'digits_field' => 'data.digits',
                'drawn_at_field' => 'data.drawn_at',
                'schema_version' => '1',
            ],
        ], $overrides);
    }

    /**
     * Install the fake rules and mark the game verified against that exact
     * configuration (the checksum has to match, or GameRegistry reports the game as
     * unverified by design).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function useVerifiedGame(array $overrides = [], string $code = 'speed_88'): GameDefinition
    {
        $config = $this->completeGameConfig(array_replace_recursive(['code' => $code, 'name' => $code], $overrides));

        config()->set('lottery_games.master_enabled', true);
        config()->set('lottery_games.games.'.$code, $config);
        config()->set('real_money.enabled', true);

        $game = GameDefinition::fromConfig($code, $config);

        LotteryGame::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $game->name,
                'status' => GameRulesStatus::Verified->value,
                'enabled' => true,
                'rules_checksum' => $game->checksum(),
                'verified_at' => now(),
            ],
        );

        // GameRegistry memoises per request; the test app is one request.
        $this->app->forgetInstance(GameRegistry::class);

        return $game;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function openGameDraw(string $code = 'speed_88', array $attributes = []): Draw
    {
        // draw_code is UNIQUE, and a test may legitimately open several draws of one
        // game inside the same minute, so the suffix is not just the minute.
        $number = 'GAME-'.strtoupper($code).'-'.now()->format('YmdHis').'-'.substr(uniqid(), -4);

        return Draw::query()->create(array_merge([
            'draw_number' => $number,
            'draw_code' => $number,
            'type' => DrawType::TwoD,
            'game_type' => $code,
            'status' => DrawStatus::Open,
            'currency' => 'THB',
            'scheduled_at' => now()->addHours(2),
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
            'metadata' => ['game_code' => $code],
        ], $attributes));
    }

    /**
     * Publish the legal documents the admission gate requires.
     */
    protected function publishLegalDocuments(): void
    {
        if (! Schema::hasTable('legal_documents') || ! Schema::hasTable('legal_document_publications')) {
            return;
        }

        foreach (['terms', 'privacy'] as $type) {
            $documentId = DB::table('legal_documents')->insertGetId([
                'document_type' => $type,
                'locale' => 'en',
                'version' => '1.0',
                'title' => ucfirst($type),
                'meta_title' => ucfirst($type),
                'meta_description' => ucfirst($type).' document',
                // A published document with NO sections is "UNAVAILABLE" to the consent
                // service, so the admission fixture must publish real content.
                'sections' => json_encode([[
                    'heading' => ucfirst($type),
                    'body' => 'Digit-game test '.$type.' notice.',
                ]]),
                'content_hash' => hash('sha256', $type),
                'effective_at' => now(),
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('legal_document_publications')->insert([
                'document_type' => $type,
                'locale' => 'en',
                'legal_document_id' => $documentId,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function gamePlayer(): User
    {
        return User::factory()->create();
    }
}
