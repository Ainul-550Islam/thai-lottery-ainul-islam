<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use App\Models\User;
use App\Services\Privacy\PrivacyPolicyService;
use App\Services\PublicPages\PublicPageDataService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Public disclosure/version isolation, not legal approval or consent evidence. */
final class TermsRevisionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public static function languages(): array
    {
        return [
            'English' => ['en', 'not the official GLO website', 'do not claim government ownership'],
            'Thai' => ['th', 'ไม่ใช่เว็บไซต์ทางการของสำนักงานสลากกินแบ่งรัฐบาล', 'ไม่กล่าวอ้างการเป็นเจ้าของ'],
        ];
    }

    #[DataProvider('languages')]
    public function test_the_actual_disclaimer_section_contains_non_affiliation_in_each_language(string $locale, string $siteDenial, string $ownershipDenial): void
    {
        $response = $this->get('/terms?lang='.$locale)->assertOk();
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()));
            $xpath = new DOMXPath($dom);
            $sections = $xpath->query('//section[@id="disclaimer"]');
            self::assertSame(1, $sections->length);
            $copy = $sections->item(0)->textContent;
            self::assertStringContainsString($siteDenial, $copy);
            self::assertStringContainsString($ownershipDenial, $copy);
            $note = $xpath->query('//p[contains(@class,"terms-identity-note")]');
            self::assertSame(1, $note->length);
            self::assertSame(trans('public_pages.terms_identity_note', [], $locale), trim($note->item(0)->textContent));
            self::assertStringNotContainsString('approved public legal content', $response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $data = $this->getJson('/api/v1/public/terms?lang='.$locale)->assertOk()->json('data');
        self::assertSame($locale, $data['locale']);
        $disclaimer = array_values(array_filter($data['sections'], fn (array $row): bool => $row['id'] === 'disclaimer'));
        self::assertCount(1, $disclaimer);
        self::assertStringContainsString($siteDenial, $disclaimer[0]['body']);
        self::assertStringContainsString($ownershipDenial, $disclaimer[0]['body']);
        self::assertSame('v1.0.1', $data['version']);
        self::assertSame($data['version'], $data['legal_version']);
    }

    public function test_changing_only_terms_revision_invalidates_cache_without_reversioning_privacy(): void
    {
        Cache::flush();
        config(['public_pages.cache_ttl_seconds' => 300]);
        $pages = app(PublicPageDataService::class);
        $privacy = $pages->privacy('en');
        $old = $pages->terms('en');
        self::assertSame('v1.0.1', $old['version']);
        config(['legal.terms.version' => 'test-terms-next', 'legal.terms.content_version' => 'test-next']);
        $new = $pages->terms('en');
        self::assertSame('test-terms-next', $new['version']);
        self::assertSame('test-terms-next', $new['legal_version']);
        self::assertSame($privacy, $pages->privacy('en'));
        self::assertSame('v1.0.0', app(PrivacyPolicyService::class)->getVersion());

        // Even an operator keeping the display version fixed must get a new
        // cache key when only the terms content revision changes.
        $keys = [];
        Cache::shouldReceive('remember')->twice()->andReturnUsing(
            static function (string $key, int $ttl, callable $producer) use (&$keys): array {
                $keys[] = $key;

                return $producer();
            }
        );
        $pages->terms('en');
        config(['legal.terms.content_version' => 'test-content-only']);
        $pages->terms('en');
        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    public function test_terms_metadata_change_does_not_leave_a_stale_cached_effective_date(): void
    {
        Cache::flush();
        config(['public_pages.cache_ttl_seconds' => 300]);
        $pages = app(PublicPageDataService::class);
        $old = $pages->terms('en');
        self::assertSame('2026-10-14', $old['effective_at']);
        // Synthetic metadata change, not an actual legal approval/release.
        config(['legal.terms.effective_at' => '2030-01-01', 'legal.terms.updated_at' => '2030-01-02']);
        $fresh = $pages->terms('en');
        self::assertSame('2030-01-01', $fresh['effective_at']);
        self::assertSame('2030-01-02', $fresh['updated_at']);
        self::assertSame('2026-09-24', app(PrivacyPolicyService::class)->getEffectiveDate());
    }

    public function test_zero_cache_terms_api_metadata_still_uses_the_terms_revision(): void
    {
        config(['public_pages.cache_ttl_seconds' => 0]);
        $this->getJson('/api/v1/public/terms')->assertOk()
            ->assertJsonPath('data.version', 'v1.0.1')->assertJsonPath('data.legal_version', 'v1.0.1');
        $this->getJson('/api/v1/public/privacy')->assertOk()->assertJsonPath('data.version', 'v1.0.0');

        config(['public_pages.cache_ttl_seconds' => 300]);
        Cache::shouldReceive('remember')->once()->andThrow(new \RuntimeException('Synthetic cache outage'));
        $unavailable = app(PublicPageDataService::class)->terms('en');
        self::assertSame('UNAVAILABLE', $unavailable['status']);
        self::assertSame('v1.0.1', $unavailable['legal_version']);
    }

    public function test_english_and_thai_cache_entries_are_independent(): void
    {
        Cache::flush();
        $pages = app(PublicPageDataService::class);
        $en = $pages->terms('en');
        $th = $pages->terms('th');
        self::assertSame('en', $en['locale']);
        self::assertSame('th', $th['locale']);
        self::assertNotSame($en['sections'][8]['body'], $th['sections'][8]['body']);
        self::assertSame($en, $pages->terms('en'));
        self::assertSame($th, $pages->terms('th'));
    }

    public function test_reading_revised_terms_does_not_fabricate_or_rewrite_account_consent(): void
    {
        $user = User::factory()->create(['preferences' => [
            'terms_accepted_at' => '2025-01-01T00:00:00+00:00',
            'terms_version' => '1',
            'custom_setting' => 'unchanged',
        ]]);
        $before = $user->refresh()->getRawOriginal();
        $this->actingAs($user)->get('/terms')->assertOk();
        $this->getJson('/api/v1/public/terms')->assertOk();
        $this->postJson('/api/v1/public/terms/accept')->assertStatus(501)->assertJsonPath('state', 'NOT_CONFIGURED');
        self::assertSame($before, $user->fresh()->getRawOriginal());
        self::assertSame('1', $user->fresh()->preferences['terms_version']);
    }
}
