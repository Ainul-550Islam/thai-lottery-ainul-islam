<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\RulesImportValidator;
use Tests\TestCase;

/**
 * What an operator's typed document has to survive before it can become a rule set.
 *
 * Every case here is a real way a human-filled document goes wrong: a key that is absent,
 * a key that is misspelled (and therefore silently ignored by every reader), a market left
 * as a placeholder, a multiplier that breaches the ceiling, a schedule that cannot parse.
 */
final class RulesImportValidatorTest extends TestCase
{
    private function validator(): RulesImportValidator
    {
        return app(RulesImportValidator::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function complete(): array
    {
        return [
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
        ];
    }

    public function test_a_complete_document_is_accepted_and_its_rtp_is_reported(): void
    {
        $report = $this->validator()->inspect('speed_88', $this->complete());

        $this->assertTrue($report['valid'], implode(' | ', $report['problems']));
        $this->assertSame([], $report['missing']);
        $this->assertNotNull($report['rtp']);
        $this->assertTrue($report['rtp']['within_ceiling']);
        $this->assertSame('0.70000000', $report['rtp']['bet_types']['4d_direct']['rtp']);
    }

    public function test_a_missing_top_level_key_is_named_individually(): void
    {
        $block = $this->complete();
        unset($block['cutoff_seconds']);

        $report = $this->validator()->inspect('speed_88', $block);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('"cutoff_seconds"', implode(' ', $report['problems']));
    }

    public function test_a_misspelled_key_is_reported_because_every_reader_would_ignore_it(): void
    {
        $block = $this->complete();
        $block['bet_types']['4d_direct']['payout_multipllier'] = '7000';
        unset($block['bet_types']['4d_direct']['payout_multiplier']);

        $report = $this->validator()->inspect('speed_88', $block);

        // The misspelled key is a warning; the missing multiplier is what fails the block.
        $this->assertStringContainsString('unknown key', implode(' ', $report['warnings']));
        $this->assertFalse($report['valid']);
    }

    public function test_a_placeholder_left_in_the_document_is_reported_as_outstanding_input(): void
    {
        $block = $this->complete();
        $block['max_liability_per_draw'] = GameDefinition::REQUIRED_INPUT;

        $report = $this->validator()->inspect('speed_88', $block);

        $this->assertFalse($report['valid']);
        $this->assertContains('max_liability_per_draw', $report['missing']);
        $this->assertNull($report['rtp'], 'an unpriced block has no RTP to report');
    }

    public function test_a_multiplier_that_breaches_the_ceiling_is_refused(): void
    {
        $block = $this->complete();
        // p = 1/10000 -> 0.0001 * 20000 = 2.0, far above the 0.98 ceiling.
        $block['bet_types']['4d_direct']['payout_multiplier'] = '20000';

        $report = $this->validator()->inspect('speed_88', $block);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('RTP ceiling', implode(' ', $report['problems']));
        $this->assertFalse($report['rtp']['within_ceiling']);
    }

    public function test_an_unparseable_schedule_is_refused(): void
    {
        $block = $this->complete();
        $block['draw_cron'] = ['every evening'];

        $report = $this->validator()->inspect('speed_88', $block);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('draw_cron.0', implode(' ', $report['problems']));
    }

    public function test_a_cutoff_that_cannot_fit_the_cadence_is_refused(): void
    {
        $block = $this->complete();
        $block['cutoff_seconds'] = 90000; // a daily draw leaves 86400

        $report = $this->validator()->inspect('speed_88', $block);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('cutoff_seconds', implode(' ', $report['problems']));
    }

    public function test_a_document_can_never_declare_itself_verified(): void
    {
        $block = $this->complete();
        $block['rules_status'] = 'verified';

        $normalised = $this->validator()->normalise('speed_88', $block);

        $this->assertSame('verified', $normalised['rules_status'], 'normalise states the value');

        // ...and the import command overwrites it: a file is not an operator action.
        $this->assertSame('speed_88', $normalised['code']);
    }
}
