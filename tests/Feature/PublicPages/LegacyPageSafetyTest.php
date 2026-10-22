<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use Tests\TestCase;

final class LegacyPageSafetyTest extends TestCase
{
    public function test_lotto_discount_alias_does_not_publish_stale_unsupported_claims_or_dead_social_links(): void
    {
        $html = (string) $this->get('/lotto-discount')->assertOk()->getContent();

        $this->assertStringContainsString('data-next-public-page="discounts"', $html);
        $this->assertStringNotContainsString('Officially licensed and regulated', $html);
        $this->assertStringNotContainsString('GLO Certified Results', $html);
        $this->assertDoesNotMatchRegularExpression('~href=[\\x22\\x27]#[\\x22\\x27]~', $html);
    }

    public function test_contact_us_alias_uses_the_configured_contact_page_not_the_legacy_mockup(): void
    {
        $html = (string) $this->get('/contact-us')->assertOk()->getContent();

        $this->assertStringContainsString('data-next-public-page="contact"', $html);
        $this->assertStringNotContainsString('@thailotto_official', $html);
        $this->assertStringNotContainsString('Bangkok Operations', $html);
        $this->assertDoesNotMatchRegularExpression('~href=[\\x22\\x27]#[\\x22\\x27]~', $html);
    }
}
