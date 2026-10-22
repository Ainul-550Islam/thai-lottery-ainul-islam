<?php

declare(strict_types=1);

namespace Tests\Unit\Promotions;

use App\Services\Promotions\PublicBonusService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicBonusServiceTest extends TestCase
{
    private function campaign(array $overrides = []): array
    {
        return array_merge([
            'id' => 'spring',
            'title' => 'Spring offer',
            'description' => 'Details',
            'valid_from' => Carbon::now()->subDay()->toIso8601String(),
            'valid_until' => Carbon::now()->addDay()->toIso8601String(),
            'cta_label' => 'View',
            'cta_url' => '/offers',
        ], $overrides);
    }

    private function activeFor(array $campaigns, ?int $max = null): array
    {
        config()->set('home.campaigns', $campaigns);
        if ($max !== null) {
            config()->set('home.campaigns_max', $max);
        }

        return (new PublicBonusService)->activeCampaigns();
    }

    public function test_empty_catalogue_reports_unavailable_and_never_invents_an_offer(): void
    {
        $result = $this->activeFor([]);

        $this->assertSame('UNAVAILABLE', $result['status']);
        $this->assertSame([], $result['campaigns']);
        $this->assertSame('No active promotions', $result['message']);
    }

    public function test_active_campaign_is_returned_with_its_fields(): void
    {
        $result = $this->activeFor([$this->campaign()]);

        $this->assertSame('VERIFIED', $result['status']);
        $this->assertCount(1, $result['campaigns']);
        $this->assertSame('spring', $result['campaigns'][0]['id']);
        $this->assertSame('Spring offer', $result['campaigns'][0]['title']);
        $this->assertSame('View', $result['campaigns'][0]['cta_label']);
        $this->assertSame('/offers', $result['campaigns'][0]['cta_url']);
        $this->assertSame('active', $result['campaigns'][0]['status']);
    }

    public function test_campaign_that_has_not_started_yet_is_hidden(): void
    {
        $future = $this->campaign(['valid_from' => Carbon::now()->addDay()->toIso8601String()]);

        $this->assertSame('UNAVAILABLE', $this->activeFor([$future])['status']);
    }

    public function test_expired_campaign_is_hidden(): void
    {
        $expired = $this->campaign(['valid_until' => Carbon::now()->subMinute()->toIso8601String()]);

        $this->assertSame('UNAVAILABLE', $this->activeFor([$expired])['status']);
    }

    public function test_campaign_without_title_is_hidden(): void
    {
        $this->assertSame('UNAVAILABLE', $this->activeFor([$this->campaign(['title' => '   '])])['status']);
    }

    public function test_non_array_entries_are_skipped_and_valid_ones_kept(): void
    {
        $result = $this->activeFor(['garbage', 42, $this->campaign()]);

        $this->assertSame('VERIFIED', $result['status']);
        $this->assertCount(1, $result['campaigns']);
    }

    public function test_number_of_campaigns_is_capped_at_campaigns_max(): void
    {
        $many = [];
        for ($i = 0; $i < 5; $i++) {
            $many[] = $this->campaign(['id' => 'c'.$i, 'title' => 'Offer '.$i]);
        }

        $result = $this->activeFor($many, 2);

        $this->assertCount(2, $result['campaigns']);
        $this->assertSame('c0', $result['campaigns'][0]['id']);
        $this->assertSame('c1', $result['campaigns'][1]['id']);
    }

    public static function allowedUrlProvider(): array
    {
        return [
            'https absolute' => ['https://example.test/offers'],
            'site-relative path' => ['/offers'],
            'relative with query' => ['/offers?id=3'],
            'https with surrounding spaces is trimmed' => ['  https://example.test/a  '],
        ];
    }

    #[DataProvider('allowedUrlProvider')]
    public function test_safe_cta_urls_are_kept(string $url): void
    {
        $result = $this->activeFor([$this->campaign(['cta_url' => $url])]);

        $this->assertSame(trim($url), $result['campaigns'][0]['cta_url']);
    }

    public static function unsafeUrlProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'uppercase javascript scheme' => ['JAVASCRIPT:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'plain http is refused' => ['http://example.test/offers'],
            'protocol relative' => ['//evil.test/phish'],
            'backslash trick' => ['/\\evil.test'],
            'embedded newline' => ["/offers\nevil"],
            'https without host' => ['https:///path'],
            'bare text' => ['not a url'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'empty string' => [''],
        ];
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_unsafe_cta_urls_are_dropped_to_null(string $url): void
    {
        $result = $this->activeFor([$this->campaign(['cta_url' => $url])]);

        $this->assertSame('VERIFIED', $result['status']);
        $this->assertNull($result['campaigns'][0]['cta_url']);
    }

    public function test_non_string_cta_url_is_dropped(): void
    {
        $result = $this->activeFor([$this->campaign(['cta_url' => ['https://example.test']])]);

        $this->assertNull($result['campaigns'][0]['cta_url']);
    }

    public function test_missing_cta_url_stays_null(): void
    {
        $campaign = $this->campaign();
        unset($campaign['cta_url']);

        $this->assertNull($this->activeFor([$campaign])['campaigns'][0]['cta_url']);
    }
}
