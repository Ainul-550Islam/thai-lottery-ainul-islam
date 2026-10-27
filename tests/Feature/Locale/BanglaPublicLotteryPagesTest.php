<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class BanglaPublicLotteryPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_lottery_hub_renders_bangla_product_cards(): void
    {
        config([
            'national_lottery.enabled' => true,
            'weekly_lottery.enabled' => true,
            'bingo_lottery.enabled' => true,
            'pcso_lottery.enabled' => true,
        ]);

        $this->get('/lotteries?lang=bn')
            ->assertOk()
            ->assertSee('লটারি বিভাগ বেছে নিন')
            ->assertSee('জাতীয় লটারি')
            ->assertSee('সাপ্তাহিক লটারি')
            ->assertSee('মেগা লটারি')
            ->assertSee('PCSO লটারি');
    }

    public function test_dedicated_glo_l6_page_renders_bangla_without_enabling_purchase(): void
    {
        $this->get('/glo-l6?lang=bn')
            ->assertOk()
            ->assertSee('GLO L6 পাবলিক তথ্য')
            ->assertSee('সর্বশেষ ফলাফল')
            ->assertSee('কেনাকাটা অনুপলভ্য')
            ->assertDontSee('কেনাকাটা উপলভ্য');
    }

    public function test_dynamic_lottery_hub_states_and_products_have_bangla_values(): void
    {
        foreach ([
            'RESULT_AVAILABLE', 'RESULT_FOUND', 'NO_PUBLIC_DATA', 'RESULT_NOT_FOUND',
            'RESULT_UNAVAILABLE', 'INVALID_QUERY', 'UNAVAILABLE',
        ] as $state) {
            $this->assertNotSame(
                trans('lottery_hub.state.'.$state, [], 'en'),
                trans('lottery_hub.state.'.$state, [], 'bn'),
                'Lottery hub state must have a Bangla label: '.$state,
            );
        }

        foreach (['national_lottery', 'weekly_lottery', 'bingo_lottery', 'pcso_lottery'] as $product) {
            foreach (['title', 'description'] as $field) {
                $key = 'lottery_hub.products.'.$product.'.'.$field;
                $this->assertTrue(Lang::has($key, 'bn'), 'Missing Bangla product copy: '.$key);
                $this->assertNotSame(trans($key, [], 'en'), trans($key, [], 'bn'), 'Bangla product copy fell back to English: '.$key);
            }
        }
    }

    public function test_dynamic_glo_l6_states_and_result_labels_have_bangla_values(): void
    {
        foreach ([
            'official_source_verified', 'internal_reconciled', 'fixture_only', 'not_configured', 'unavailable',
        ] as $state) {
            $key = 'glo_l6.source_state.'.$state;
            $this->assertNotSame(
                trans($key, [], 'en'),
                trans($key, [], 'bn'),
                'GLO L6 source state must have a Bangla label: '.$state,
            );
        }

        foreach ([
            'first_prize_label', 'front_three_label', 'last_three_label', 'last_two_label',
            'adjacent_label', 'second_prize_label', 'third_prize_label', 'fourth_prize_label', 'fifth_prize_label',
        ] as $key) {
            $this->assertNotSame(
                trans('glo_l6.'.$key, [], 'en'),
                trans('glo_l6.'.$key, [], 'bn'),
                'GLO L6 result label must have a Bangla value: '.$key,
            );
        }
    }
}
