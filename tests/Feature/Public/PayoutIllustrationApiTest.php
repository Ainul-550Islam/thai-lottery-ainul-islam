<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/public/how-to-play/simulate — the public payout illustration.
 *
 * The lane is OFF by default and, while it is off, this endpoint must answer
 * the exact body it answered before the feature existed, whatever the input.
 * Switched on, it must return deterministic arithmetic from a published rate,
 * refuse bad input, and answer NOT_CONFIGURED for a bet type whose own
 * published sources contradict one another.
 */
final class PayoutIllustrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_BODY = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A public bet simulator is not configured. Educational content does not calculate or promise an outcome.',
    ];

    private function enableLane(): void
    {
        config([
            'payout_illustration.enabled' => true,
            'payout_illustration.min_stake' => '1.00',
            'payout_illustration.max_stake' => '100000.00',
            'payout_illustration.currency' => 'THB',
        ]);
    }

    public function test_with_the_lane_off_the_original_501_body_is_returned_verbatim(): void
    {
        config(['payout_illustration.enabled' => false]);

        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '100.00',
        ]);

        $response->assertStatus(501);
        $this->assertSame(json_encode(self::ORIGINAL_BODY), $response->getContent());
    }

    public function test_with_the_lane_off_an_invalid_payload_still_gets_the_original_501(): void
    {
        config(['payout_illustration.enabled' => false]);

        // Validation must not pre-empt the contract: a client written against
        // the old endpoint sends whatever it sends and gets the same answer.
        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => 'not-a-bet-type',
            'stake' => 'not-an-amount',
        ]);

        $response->assertStatus(501);
        $this->assertSame(json_encode(self::ORIGINAL_BODY), $response->getContent());
    }

    public function test_with_the_lane_on_a_published_rate_produces_the_exact_return(): void
    {
        $this->enableLane();

        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '100.00',
        ])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('state', 'READY')
            ->assertJsonPath('data.bet_type', '3d')
            ->assertJsonPath('data.stake', '100.00')
            ->assertJsonPath('data.multiplier', '900.0000')
            ->assertJsonPath('data.gross_return_if_matched', '90000.00')
            ->assertJsonPath('data.net_if_matched', '90000.00')
            ->assertJsonPath('data.currency', 'THB')
            ->assertJsonPath('data.disclaimer_key', 'payout_illustration.disclaimer');

        $this->assertStringContainsString(
            'not a prediction',
            (string) $response->json('data.disclaimer'),
        );
    }

    public function test_the_answer_is_deterministic_across_calls(): void
    {
        $this->enableLane();

        $payload = ['bet_type' => 'tod', 'stake' => '250.50'];

        $first = $this->postJson('/api/v1/public/how-to-play/simulate', $payload)->assertOk()->getContent();
        $second = $this->postJson('/api/v1/public/how-to-play/simulate', $payload)->assertOk()->getContent();

        $this->assertSame($first, $second);
        $this->assertSame(
            '11272.50',
            (string) json_decode($first, true)['data']['gross_return_if_matched'],
        );
    }

    public function test_a_bet_type_without_a_single_published_rate_answers_not_configured_with_http_200(): void
    {
        $this->enableLane();

        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => 'run',
            'stake' => '100.00',
        ])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('state', 'NOT_CONFIGURED')
            ->assertJsonPath('data.state', 'NOT_CONFIGURED')
            ->assertJsonPath('data.multiplier', null)
            ->assertJsonPath('data.gross_return_if_matched', null)
            ->assertJsonPath('data.reason_key', 'payout_illustration.not_configured_multiplier');

        // Even a refusal carries the disclaimer: nothing here is a prediction.
        $this->assertNotEmpty($response->json('data.disclaimer'));
    }

    public function test_invalid_input_is_rejected_with_422_when_the_lane_is_on(): void
    {
        $this->enableLane();

        $cases = [
            'missing stake' => ['bet_type' => '3d'],
            'negative stake' => ['bet_type' => '3d', 'stake' => '-10.00'],
            'malformed stake' => ['bet_type' => '3d', 'stake' => '10.001'],
            'above the published limit' => ['bet_type' => '3d', 'stake' => '1000000.00'],
            'unknown bet type' => ['bet_type' => 'l6', 'stake' => '10.00'],
        ];

        foreach ($cases as $label => $payload) {
            $this->postJson('/api/v1/public/how-to-play/simulate', $payload)
                ->assertStatus(422);
        }
    }

    public function test_a_stake_at_the_published_limit_is_accepted(): void
    {
        $this->enableLane();

        $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '2d',
            'stake' => '100000.00',
        ])->assertOk()
            ->assertJsonPath('data.stake', '100000.00')
            ->assertJsonPath('data.gross_return_if_matched', '9000000.00');
    }

    public function test_a_json_number_stake_and_an_uppercase_bet_type_are_accepted(): void
    {
        $this->enableLane();

        $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3D',
            'stake' => 25,
        ])->assertOk()
            ->assertJsonPath('data.bet_type', '3d')
            ->assertJsonPath('data.stake', '25.00')
            ->assertJsonPath('data.gross_return_if_matched', '22500.00');
    }

    public function test_the_illustration_language_follows_the_request(): void
    {
        $this->enableLane();
        config(['payout_illustration.max_stake' => '100000.00']);

        $thai = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '10.00',
            'locale' => 'th',
        ])->assertOk();

        $this->assertStringContainsString('ไม่ใช่การพยากรณ์', (string) $thai->json('data.disclaimer'));
        $this->assertSame('9000.00', (string) $thai->json('data.gross_return_if_matched'));
    }

    public function test_no_probability_or_outcome_language_appears_in_the_response(): void
    {
        $this->enableLane();

        $payload = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '10.00',
        ])->assertOk()->json();

        // The disclaimer copy itself states what the figure is NOT, so the scan
        // inspects the figures and the derivation, not that sentence.
        unset($payload['data']['disclaimer'], $payload['data']['basis']['net_basis']);

        $body = (string) json_encode($payload);

        foreach (['probability', 'odds', 'chance of', 'guaranteed', 'expected value', 'win rate'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body);
        }
    }
}
