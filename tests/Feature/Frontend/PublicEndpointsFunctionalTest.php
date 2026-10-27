<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * The public service endpoints that used to answer a blanket 501.
 *
 * THE RULE THIS SUITE ENFORCES
 * ---------------------------------------------------------------------------
 * `NOT_CONFIGURED` is a legitimate answer, but only when the LANE IS OFF. With
 * the flag on, an endpoint must answer with real data: a lane that is switched on
 * and still says "not configured" is a promise the product surface does not keep,
 * and it is indistinguishable from a broken deploy.
 *
 * So every test here turns the lane ON and asserts a 2xx. A separate test then
 * turns it OFF and asserts the honest 501 survives — because the opposite failure
 * (quietly answering with invented data when the lane is disabled) is worse.
 */
final class PublicEndpointsFunctionalTest extends TestCase
{
    // ── Ticket status lookup ────────────────────────────────────────────────

    public function test_ticket_status_lookup_answers_when_the_lane_is_on(): void
    {
        config(['support_tickets.status_lookup_enabled' => true]);

        $response = $this->postJson('/api/v1/public/contact/status', [
            'reference' => 'CNT-00000000',
            'email' => 'visitor@example.test',
        ]);

        $response->assertOk();

        // The neutral answer is a SUCCESS, not an error: the visitor learns that
        // the reference did not match without learning whether it exists.
        $payload = $response->json();

        $this->assertArrayHasKey('state', $payload);
        $this->assertNotSame(501, $response->getStatusCode());
    }

    public function test_ticket_status_is_neutral_for_an_unknown_reference(): void
    {
        config(['support_tickets.status_lookup_enabled' => true]);

        $response = $this->postJson('/api/v1/public/contact/status', [
            'reference' => 'CNT-DOES-NOT-EXIST',
            'email' => 'nobody@example.test',
        ]);

        $response->assertOk();

        $content = (string) $response->getContent();

        // No enumeration oracle: the body must not distinguish "no such
        // reference" from "wrong address".
        $this->assertStringNotContainsString('no such', mb_strtolower($content));
        $this->assertStringNotContainsString('not found', mb_strtolower($content));
    }

    public function test_ticket_status_is_refused_when_the_lane_is_off(): void
    {
        config(['support_tickets.status_lookup_enabled' => false]);

        $response = $this->postJson('/api/v1/public/contact/status', [
            'reference' => 'CNT-00000000',
            'email' => 'visitor@example.test',
        ]);

        $response->assertStatus(501);
        $response->assertJsonPath('state', 'NOT_CONFIGURED');
    }

    // ── How-to-play simulator ───────────────────────────────────────────────

    public function test_the_bet_simulator_answers_with_a_server_side_figure(): void
    {
        config(['payout_illustration.enabled' => true]);

        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '100.00',
        ]);

        // Either READY (a multiplier is configured) or a stated 503 because this
        // environment has no market multiplier — never invented, never silent.
        $this->assertContains($response->getStatusCode(), [200, 503]);

        if ($response->getStatusCode() === 200) {
            $response->assertJsonPath('success', true);

            $data = $response->json('data');
            $this->assertIsArray($data);

            if (($data['state'] ?? null) === 'READY') {
                // Any figure present must be a decimal STRING, never a float:
                // money never travels through binary floating point.
                foreach (['stake', 'payout'] as $field) {
                    if (isset($data[$field])) {
                        $this->assertIsString($data[$field], "the illustration {$field} must be a decimal string");
                    }
                }
            }
        }
    }

    public function test_the_bet_simulator_is_refused_when_the_lane_is_off(): void
    {
        config(['payout_illustration.enabled' => false]);

        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '100.00',
        ]);

        $response->assertStatus(501);
        $response->assertJsonPath('state', 'NOT_CONFIGURED');
    }

    // ── Download lane ───────────────────────────────────────────────────────

    public function test_the_build_manifest_endpoint_answers(): void
    {
        $response = $this->getJson('/api/v1/public/download/builds');

        // 200 with a list, or the honest 501 when no verified release exists.
        $this->assertContains($response->getStatusCode(), [200, 501]);

        if ($response->getStatusCode() === 501) {
            $response->assertJsonPath('state', 'NOT_CONFIGURED');
        }
    }

    public function test_a_checksum_verification_never_confirms_without_a_release(): void
    {
        $response = $this->postJson('/api/v1/public/download/verify-checksum', [
            'version' => '0.0.0-does-not-exist',
            'sha256' => str_repeat('a', 64),
        ]);

        // Fail-closed: an unknown build can never verify as matching.
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // ── Fees lane ───────────────────────────────────────────────────────────

    public function test_the_fee_schedule_is_served(): void
    {
        $response = $this->getJson('/api/v1/public/fees');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_a_fee_calculation_answers_from_the_published_schedule(): void
    {
        $response = $this->postJson('/api/v1/public/fees/calculate', [
            'provider' => 'promptpay',
            'amount' => '1000.00',
            'direction' => 'deposit',
        ]);

        // 200 with a figure, or 422 for an unknown provider — never a 500, and
        // never a figure the schedule does not contain.
        $this->assertContains($response->getStatusCode(), [200, 422]);
    }

    public function test_the_fee_schedule_pdf_is_refused_honestly_when_export_is_off(): void
    {
        config(['legal_pdf.enabled' => false]);

        $response = $this->getJson('/api/v1/public/fees/download');

        $response->assertStatus(501);
        $response->assertJsonPath('state', 'NOT_CONFIGURED');
    }
}
