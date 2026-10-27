<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\DownloadEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Media\AndroidApkReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Verifies Audit Findings D9, D10.1, D10.2, D10.3 & D10.4 end to end:
 *  - D9 / D10.1: Dynamic XML sitemap + Google Image Sitemap namespace & <image:image> nodes
 *  - D10.2: Real SHA-256 build manifest checksum verification + HMAC-anonymized download tracking
 *  - D10.3: Strict X-Robots-Tag + <meta name="robots" content="noindex, nofollow"> on auth/member/admin
 *  - D10.4: 301 canonical consolidation from /lotto/betting to /betting
 */
final class TechnicalSeoAndDownloadIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_declares_google_image_namespace_and_emits_verified_image_entries(): void
    {
        Article::query()->create([
            'slug' => 'how-thai-lottery-settlement-works',
            'locale' => 'en',
            'translation_group' => 'settlement-guide',
            'category' => ArticleCategory::Guide,
            'status' => ArticleStatus::Published,
            'title' => 'How Thai Lottery Settlement Works',
            'excerpt' => 'Transparent guide to draw settlement and payout verification.',
            'body' => '<figure><img src="https://cdn.example.com/articles/settlement-hero.png" alt="Settlement workflow"></figure><p>Verified settlement overview.</p>',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get('/sitemap.xml');
        $response->assertOk();

        $rawXml = (string) $response->getContent();
        $xml = simplexml_load_string($rawXml);
        $this->assertNotFalse($xml);

        $namespaces = $xml->getDocNamespaces(true);
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $namespaces[''] ?? null);
        $this->assertSame('http://www.google.com/schemas/sitemap-image/1.1', $namespaces['image'] ?? null);

        $this->assertStringContainsString('<image:image>', $rawXml);
        $this->assertStringContainsString('<image:loc>https://cdn.example.com/articles/settlement-hero.png</image:loc>', $rawXml);
        $this->assertStringContainsString('<image:title>How Thai Lottery Settlement Works</image:title>', $rawXml);

        // Trilingual blog indexes (en, th, bn) are included in the dynamic sitemap.
        $this->assertStringContainsString('/blog/en</loc>', $rawXml);
        $this->assertStringContainsString('/blog/th</loc>', $rawXml);
        $this->assertStringContainsString('/blog/bn</loc>', $rawXml);
    }

    public function test_download_checksum_and_tracking_report_not_configured_when_unconfigured(): void
    {
        config(['conversion_tooling.downloads.tracking_enabled' => false]);

        $this->postJson('/api/download/verify-checksum', [
            'sha256' => str_repeat('a', 64),
        ])->assertStatus(501)->assertJson([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
        ]);

        $this->postJson('/api/download/track', [
            'platform' => 'android',
        ])->assertStatus(501)->assertJson([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
        ]);
    }

    public function test_download_checksum_verification_and_hmac_telemetry_work_when_manifest_is_configured(): void
    {
        $tmpDir = sys_get_temp_dir().'/tl_apk_test_'.uniqid('', true);
        mkdir($tmpDir, 0775, true);

        $apkPath = $tmpDir.'/thai-apk.apk';
        $manifestPath = $tmpDir.'/thai-apk.manifest.json';

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($apkPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('AndroidManifest.xml', 'manifest-binary-stub');
        $zip->addFromString('classes.dex', 'dex-bytecode-stub');
        $zip->close();

        $realSha256 = (string) hash_file('sha256', $apkPath);
        $sizeBytes = (int) filesize($apkPath);

        file_put_contents($manifestPath, json_encode([
            'status' => 'VERIFIED',
            'version' => '1.4.2',
            'package_name' => AndroidApkReleaseService::PACKAGE_NAME,
            'web_app_url' => 'https://thailotto.example.com',
            'file' => AndroidApkReleaseService::APK_RELATIVE_PATH,
            'sha256' => $realSha256,
            'size_bytes' => $sizeBytes,
            'built_at' => '2026-10-21T06:00:00+00:00',
            'source_commit' => str_repeat('b', 40),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->app->singleton(AndroidApkReleaseService::class, fn () => new AndroidApkReleaseService($apkPath, $manifestPath));

        try {
            // 1. Matching SHA-256 -> MATCHED
            $this->postJson('/api/download/verify-checksum', [
                'sha256' => strtoupper($realSha256),
            ])->assertOk()->assertJson([
                'success' => true,
                'state' => 'MATCHED',
                'verified' => true,
                'version' => '1.4.2',
                'expected_sha256' => $realSha256,
            ]);

            // 2. Tampered SHA-256 -> MISMATCH
            $this->postJson('/api/download/verify-checksum', [
                'sha256' => str_repeat('0', 64),
            ])->assertOk()->assertJson([
                'success' => true,
                'state' => 'MISMATCH',
                'verified' => false,
                'expected_sha256' => $realSha256,
            ]);

            // 3. Public /download page renders real SHA-256 checksum & version
            $html = (string) $this->get('/download')->assertOk()->getContent();
            $this->assertStringContainsString($realSha256, $html);
            $this->assertStringContainsString('v1.4.2', $html);

            // 4. Download telemetry stores HMAC IP digest (never raw IP) & deduplicates repeats
            $first = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
                ->postJson('/api/download/track', ['platform' => 'android']);
            $first->assertStatus(201)->assertJson([
                'success' => true,
                'state' => 'RECORDED',
                'recorded' => true,
                'deduplicated' => false,
            ]);

            $duplicate = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
                ->postJson('/api/download/track', ['platform' => 'android']);
            $duplicate->assertStatus(201)->assertJson([
                'success' => true,
                'state' => 'RECORDED',
                'recorded' => false,
                'deduplicated' => true,
            ]);

            $this->assertSame(1, DownloadEvent::query()->count());
            $stored = DownloadEvent::query()->firstOrFail();
            $this->assertNotSame('203.0.113.42', $stored->ip_hash);
            $this->assertSame(64, strlen($stored->ip_hash));
        } finally {
            @unlink($apkPath);
            @unlink($manifestPath);
            @rmdir($tmpDir);
        }
    }

    public function test_auth_and_private_routes_emit_x_robots_tag_and_noindex_meta_without_hreflang(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password/sample-token'] as $guestAuthPath) {
            $res = $this->get($guestAuthPath);
            $res->assertOk();
            $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'), "Missing X-Robots-Tag on {$guestAuthPath}");
            $body = (string) $res->getContent();
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $body, "Missing meta robots on {$guestAuthPath}");
            $this->assertStringNotContainsString('hreflang=', $body, "noindex page {$guestAuthPath} must not emit hreflang");
        }

        $user = User::factory()->create();
        Wallet::factory()->for($user)->withBalance('500.00')->create(['currency' => 'THB']);

        foreach (['/dashboard', '/wallet', '/bets', '/profile', '/notifications', '/support'] as $memberPath) {
            $res = $this->actingAs($user)->get($memberPath);
            $res->assertOk();
            $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'), "Missing X-Robots-Tag on {$memberPath}");
            $body = (string) $res->getContent();
            $this->assertMatchesRegularExpression('/<meta\s+name="robots"\s+content="noindex,\s*nofollow"\s*\/?>/i', $body, "Missing meta robots on {$memberPath}");
            $this->assertStringNotContainsString('hreflang=', $body, "noindex page {$memberPath} must not emit hreflang");
        }

        // Public indexable homepage DOES NOT receive noindex X-Robots-Tag.
        $publicHome = $this->get('/');
        $publicHome->assertOk();
        $this->assertNull($publicHome->headers->get('X-Robots-Tag'));
    }

    public function test_duplicate_lotto_betting_route_redirects_with_301_to_canonical_betting(): void
    {
        $response = $this->get('/lotto/betting?lang=bn');

        $response->assertStatus(301);
        $this->assertStringEndsWith('/betting?lang=bn', (string) $response->headers->get('Location'));
    }
}
