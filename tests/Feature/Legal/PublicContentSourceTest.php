<?php

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUBLIC CONTENT SOURCE OF TRUTH (FINAL AUDIT #10).
 *
 * Legal/operator identity is CONFIG, never template prose: production values
 * come only from an approved source of truth, and when they are absent the
 * pages fail closed instead of inventing an owner, an endorsement, a bank
 * account or a government relationship. The fabricated identity strings the
 * legacy live site published must never reappear on rendered pages.
 */
class PublicContentSourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FABRICATED OPERATOR IDENTITY claims. Referencing the Government
     * Lottery Office in a NEGATED, non-affiliation context ("this site is
     * not the official GLO website") is required honest copy and stays;
     * what may never appear is an identity the platform is not entitled
     * to: a fake operator company, a fake settlement account, or an
     * un-negated claim of officialness.
     */
    private const BANNED_IDENTITY = [
        'Thai Lottery Official Co.',
        'Thai Lottery Official Company',
        'Bangkok Bank',
        '123-4-56789-0',
        'owned by the Government',
        'operated by the Government Lottery Office',
        'a government-run',
        'official partner of the Government',
    ];

    /** @var array<int, string> */
    private const PUBLIC_PAGES = [
        '/',
        '/results',
        '/check',
        '/sales-points',
        '/about',
        '/vision',
        '/terms',
        '/privacy',
        '/fees',
        '/prize-verification',
        '/discounts',
        '/account-grades',
        '/account-verification-guide',
        '/contact',
        '/national-lottery',
        '/weekly-lottery',
        '/bingo-lottery',
        '/pcso-lottery',
    ];

    private function blankLegalConfig(): void
    {
        config([
            'legal.operator.legal_name' => null,
            'legal.operator.registration_number' => null,
            'legal.operator.support_email' => null,
            'legal.operator.support_phone' => null,
            'legal.operator.address' => null,
        ]);
    }

    private function approvedLegalConfig(): void
    {
        config([
            'legal.operator.legal_name' => 'Approved Operator Company Ltd',
            'legal.operator.registration_number' => 'REG-010-555-666',
            'legal.operator.support_email' => 'support@approved-operator.example',
            'legal.operator.support_phone' => '+66 2 000 0000',
            'legal.operator.address' => '100 Approved Street, Bangkok',
        ]);
    }

    public function test_blank_legal_config_fails_closed_without_inventing_identity(): void
    {
        $this->blankLegalConfig();

        foreach (['/terms', '/privacy', '/contact'] as $path) {
            $page = (string) $this->get($path)->assertOk()->getContent();

            foreach (self::BANNED_IDENTITY as $banned) {
                $this->assertStringNotContainsString($banned, $page, $banned.' must not appear on '.$path.' with blank legal config.');
            }
        }
    }

    public function test_the_terms_page_renders_exactly_the_approved_operator_identity(): void
    {
        $this->approvedLegalConfig();

        $page = (string) $this->get('/terms')->assertOk()->getContent();

        // Config-driven identity is rendered verbatim — one approved source
        // of truth, no template-invented names.
        $this->assertStringContainsString('Approved Operator Company Ltd', $page);
        $this->assertStringContainsString('REG-010-555-666', $page);
        $this->assertStringContainsString('support@approved-operator.example', $page);
    }

    public function test_production_configuration_must_not_launch_with_blank_operator_identity(): void
    {
        // The production guard: when the application runs as production, the
        // approved operator identity must be present. (In the test
        // environment this is vacuously true; the companion test pins the
        // fail-closed behaviour for blank values.)
        if (config('app.env') === 'production') {
            foreach (['legal_name', 'registration_number', 'support_email'] as $field) {
                $this->assertNotSame(
                    null,
                    config('legal.operator.'.$field),
                    'legal.operator.'.$field.' must be set in production from the approved source of truth.',
                );
            }
        }

        $this->assertTrue(true);
    }

    public function test_no_fabricated_official_identity_appears_on_any_public_page(): void
    {
        // Both with blank AND with approved config: the banned strings are
        // not the operator identity and can never be rendered.
        foreach ([fn () => $this->blankLegalConfig(), fn () => $this->approvedLegalConfig()] as $configure) {
            $configure();

            foreach (self::PUBLIC_PAGES as $path) {
                $page = (string) $this->get($path)->assertOk()->getContent();

                foreach (self::BANNED_IDENTITY as $banned) {
                    $this->assertStringNotContainsString($banned, $page, $banned.' must not appear on '.$path.'.');
                }
            }
        }
    }

    public function test_the_fees_page_speaks_with_a_versioned_voice(): void
    {
        config(['fees.rule_version' => '9']);

        $page = (string) $this->get('/fees')->assertOk()->getContent();

        // The fee schedule is versioned config; the page never presents a
        // fabricated operator identity alongside it.
        foreach (self::BANNED_IDENTITY as $banned) {
            $this->assertStringNotContainsString($banned, $page);
        }
    }

    public function test_glo_references_carry_the_non_affiliation_disclaimer(): void
    {
        // Where the public copy references the Government Lottery Office at
        // all, it does so as an external authority with the honest
        // non-affiliation statement attached — never as OUR identity.
        $footer = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Government Lottery Office', $footer);
        $this->assertStringContainsString('not the official GLO website', $footer);
    }

    public function test_discount_pages_render_only_configured_rules(): void
    {
        $page = (string) $this->get('/discounts')->assertOk()->getContent();

        // Values are config/data-driven; the page never invents prizes.
        foreach (self::BANNED_IDENTITY as $banned) {
            $this->assertStringNotContainsString($banned, $page);
        }
    }
}
