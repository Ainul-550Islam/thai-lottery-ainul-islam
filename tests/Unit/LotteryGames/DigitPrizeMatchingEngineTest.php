<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\Enums\GameMatchMode;
use App\Services\LotteryGames\DigitPrizeMatchingEngine;
use Tests\TestCase;

/**
 * The matcher is pure, so these are hand-computed expectations rather than
 * "whatever the code does".
 */
final class DigitPrizeMatchingEngineTest extends TestCase
{
    private DigitPrizeMatchingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DigitPrizeMatchingEngine;
    }

    public function test_exact_requires_the_whole_string_including_leading_zeros(): void
    {
        $this->assertTrue($this->engine->evaluate(GameMatchMode::Exact, '0042', '0042')['won']);
        $this->assertFalse($this->engine->evaluate(GameMatchMode::Exact, '0042', '42')['won']);
        // The classic leading-zero defect: 42 must never be treated as 0042.
        $this->assertFalse($this->engine->evaluate(GameMatchMode::Exact, '42', '0042')['won']);
    }

    public function test_any_order_matches_the_same_multiset_only(): void
    {
        $this->assertTrue($this->engine->evaluate(GameMatchMode::AnyOrder, '0042', '4200')['won']);
        $this->assertTrue($this->engine->evaluate(GameMatchMode::AnyOrder, '0042', '0042')['won']);
        $this->assertFalse($this->engine->evaluate(GameMatchMode::AnyOrder, '0042', '0043')['won']);
        // Three zeros against two zeros is a different multiset.
        $this->assertFalse($this->engine->evaluate(GameMatchMode::AnyOrder, '0042', '0004')['won']);
    }

    public function test_prefix_and_suffix_are_positional(): void
    {
        $this->assertTrue($this->engine->evaluate(GameMatchMode::Prefix, '04', '0421')['won']);
        $this->assertFalse($this->engine->evaluate(GameMatchMode::Prefix, '21', '0421')['won']);

        $this->assertTrue($this->engine->evaluate(GameMatchMode::Suffix, '21', '0421')['won']);
        $this->assertFalse($this->engine->evaluate(GameMatchMode::Suffix, '04', '0421')['won']);
    }

    public function test_a_selection_longer_than_the_draw_never_wins(): void
    {
        $this->assertFalse($this->engine->evaluate(GameMatchMode::Exact, '00421', '0421')['won']);
        $this->assertFalse($this->engine->evaluate(GameMatchMode::Suffix, '00421', '0421')['won']);
    }

    public function test_non_digit_input_is_refused_rather_than_matched(): void
    {
        $result = $this->engine->evaluate(GameMatchMode::Exact, '00a2', '00a2');

        $this->assertFalse($result['won']);
        $this->assertSame('non_digit_input', $result['reason']);
    }

    public function test_duplicate_digit_arrangement_counts(): void
    {
        // 4 distinct digits -> 4! = 24 arrangements
        $this->assertSame(24, $this->engine->arrangements('0123'));
        // two zeros -> 4!/2! = 12
        $this->assertSame(12, $this->engine->arrangements('0012'));
        // three zeros -> 4!/3! = 4
        $this->assertSame(4, $this->engine->arrangements('0001'));
        // four zeros -> 1
        $this->assertSame(1, $this->engine->arrangements('0000'));
    }
}
