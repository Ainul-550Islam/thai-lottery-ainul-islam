<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use App\Models\UserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every lane in this batch ships OFF, and while it is off the endpoints must
 * answer exactly what they answered before the feature existed — same status,
 * same JSON, same message. This suite pins the old contract byte for byte,
 * because the cheapest way to break a live client is a "harmless" improvement
 * to a 501 body.
 *
 * It also re-asserts the deliberate compliance decision that a public,
 * unauthenticated consent POST records nothing: the endpoint stays 501 and the
 * consent table stays empty.
 */
final class PublicLegalApiHonestyTest extends TestCase
{
    use RefreshDatabase;

    /** The exact body GET /api/v1/public/terms/download answered before this batch. */
    private const TERMS_DOWNLOAD = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A verified Terms PDF export is not configured for this environment. Use the browser print or save control.',
    ];

    /** The exact body GET /api/v1/public/fees/download answered before this batch. */
    private const FEES_DOWNLOAD = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A verified fee-schedule PDF export is not configured. Use the browser print or save control.',
    ];

    /** The exact body POST /api/v1/public/how-to-play/simulate answered before this batch. */
    private const HOW_TO_PLAY_SIMULATE = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A public bet simulator is not configured. Educational content does not calculate or promise an outcome.',
    ];

    /** The exact body POST /api/v1/public/terms/accept answered before this batch. */
    private const TERMS_ACCEPT = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'Legal acknowledgement is recorded through the authenticated consent flow, not this public endpoint.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The shipped defaults: both lanes off.
        config([
            'legal_pdf.enabled' => false,
            'payout_illustration.enabled' => false,
        ]);
    }

    public function test_terms_download_answers_the_original_501_body_byte_for_byte(): void
    {
        $response = $this->get('/api/v1/public/terms/download');

        $response->assertStatus(501);
        $this->assertSame(json_encode(self::TERMS_DOWNLOAD), $response->getContent());
        $this->assertStringNotContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_fees_download_answers_the_original_501_body_byte_for_byte(): void
    {
        $response = $this->get('/api/v1/public/fees/download');

        $response->assertStatus(501);
        $this->assertSame(json_encode(self::FEES_DOWNLOAD), $response->getContent());
        $this->assertStringNotContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_how_to_play_simulate_answers_the_original_501_body_byte_for_byte(): void
    {
        $response = $this->postJson('/api/v1/public/how-to-play/simulate', [
            'bet_type' => '3d',
            'stake' => '100.00',
        ]);

        $response->assertStatus(501);
        $this->assertSame(json_encode(self::HOW_TO_PLAY_SIMULATE), $response->getContent());
    }

    public function test_the_public_consent_endpoint_stays_501_and_writes_no_consent_row(): void
    {
        $response = $this->postJson('/api/v1/public/terms/accept', [
            'document_type' => 'terms',
            'document_version' => 'v1.0.1',
        ]);

        $response->assertStatus(501);
        $this->assertSame(json_encode(self::TERMS_ACCEPT), $response->getContent());
        $this->assertSame(0, UserConsent::query()->count());
    }

    public function test_no_download_endpoint_serves_a_pdf_while_the_lanes_are_off(): void
    {
        foreach (['/api/v1/public/terms/download', '/api/v1/public/fees/download'] as $url) {
            $response = $this->get($url);

            $response->assertStatus(501);
            $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
            $this->assertNull($response->headers->get('X-Document-Version'));
            $this->assertNull($response->headers->get('ETag'));
        }
    }
}
