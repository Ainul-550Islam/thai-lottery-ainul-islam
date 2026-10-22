<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VisionContentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_vision_page_does_not_claim_unverified_institutional_identity(): void
    {
        $content = (string) $this->get(route('vision'))->assertOk()->getContent();

        foreach ([
            'Government Owned',
            'Government Authorized',
            'Government Licensed',
            'Official Partner',
            'PAGCOR',
            'ISO 27001',
            'MGA Malta Gaming Authority',
            'CERTIFIED BY THE GOVERNMENT',
        ] as $claim) {
            // Matched on word boundaries, not as a bare substring: the hero
            // disclaimer denies an "official partnership claim", and a
            // substring test read that denial as the claim itself. A real
            // "Official Partner" badge is still caught.
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($claim, '/').'\b/i',
                $content,
                'The vision page must not claim '.$claim.'.',
            );
        }

        $this->assertStringContainsString('not a government identity', $content);
        $this->assertStringContainsString('not a promise of absolute safety', $content);
    }

    public function test_governance_uses_configured_controls_only(): void
    {
        $content = (string) $this->get(route('vision'))->assertOk()->getContent();

        foreach (['idempotency', 'transactional_ledger', 'immutable_audit', 'kyc_gates', 'payout_holds'] as $control) {
            $this->assertStringContainsString('data-pp-control="'.$control.'"', $content);
        }
        $this->assertStringNotContainsString('100% secure', $content);
        $this->assertStringNotContainsString('unhackable', $content);
        $this->assertStringNotContainsString('zero-loss', $content);
    }

    public function test_no_unescaped_html_is_rendered_from_vision_content(): void
    {
        $content = (string) $this->get(route('vision'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<script(?![^>]*\\bsrc=)/i',
            $content,
            'The page must carry no inline script: content is rendered escaped, and an '
                .'inline block would also force a CSP to allow unsafe-inline sitewide.',
        );
        $this->assertStringNotContainsString('href="#"', $content);
        $this->assertStringNotContainsString('250,000+', $content);
        $this->assertStringNotContainsString('150M+', $content);
    }
}
