<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Exceptions\LotteryGameException;
use App\Services\LotteryGames\DigitNumberValidator;
use Tests\TestCase;

final class DigitNumberValidatorTest extends TestCase
{
    private DigitNumberValidator $validator;

    private GameDefinition $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DigitNumberValidator;
        $this->game = GameDefinition::fromConfig('test_game', [
            'code' => 'test_game',
            'name' => 'Test Game',
            'digits' => 4,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['0 21 * * *'],
            'cutoff_seconds' => 300,
            'bet_types' => [
                '4d_direct' => ['selection_digits' => 4, 'match_mode' => 'exact', 'payout_multiplier' => '9000', 'market_key' => '3d_direct'],
                '2d_direct' => ['selection_digits' => 2, 'match_mode' => 'suffix', 'payout_multiplier' => '90', 'market_key' => '2d_top'],
            ],
            'min_stake' => '1',
            'max_stake' => '1000',
            'max_liability_per_draw' => '100000',
            'max_liability_per_number' => '10000',
            'rules_status' => 'unverified',
            'settlement_market_key' => '2d_top',
            'publication_bridge' => ['first_prize' => 'all', 'bottom_two' => 'last:2'],
            'result_source' => [],
        ]);
    }

    public function test_leading_zeros_survive_as_a_string(): void
    {
        $this->assertSame('0042', $this->validator->normalize($this->game, '4d_direct', '0042'));
        $this->assertIsString($this->validator->normalize($this->game, '4d_direct', '0042'));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('0042', $this->validator->normalize($this->game, '4d_direct', "  0042\n"));
    }

    public function test_the_wrong_length_is_refused_not_padded(): void
    {
        $this->expectException(LotteryGameException::class);

        try {
            $this->validator->normalize($this->game, '4d_direct', '42');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_number_invalid', $e->errorCode());
            $this->assertStringContainsString('expected 4 digits', (string) $e->context()['reason']);

            throw $e;
        }
    }

    public function test_non_digits_are_refused(): void
    {
        $this->expectException(LotteryGameException::class);
        $this->validator->normalize($this->game, '4d_direct', '00a2');
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(LotteryGameException::class);
        $this->validator->normalize($this->game, '2d_direct', '   ');
    }

    public function test_a_drawn_result_of_the_wrong_width_is_a_payload_failure(): void
    {
        try {
            $this->validator->validateDrawnDigits($this->game, '042');
            $this->fail('a 3-digit draw was accepted for a 4-digit game');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_result_payload_invalid', $e->errorCode());
        }
    }

    public function test_a_correctly_sized_drawn_result_is_accepted_verbatim(): void
    {
        $this->assertSame('0042', $this->validator->validateDrawnDigits($this->game, '0042'));
    }
}
