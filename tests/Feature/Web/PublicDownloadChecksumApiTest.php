<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Services\Media\AndroidApkReleaseService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * DOWNLOAD CHECKSUM API AND THE FAIL-CLOSED PAGE STATE (finding F17).
 *
 * The checksum endpoint may only answer against a release the server itself
 * considers verified. With no verified release it reports NOT_CONFIGURED at
 * 501 — never a guessed digest. With a verified release it compares the
 * submitted digest to the served binary's digest in constant time and answers
 * MATCH or NO_MATCH, and the download page surfaces the same verified evidence.
 */
class PublicDownloadChecksumApiTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = sys_get_temp_dir().'/thai-apk-checksum-'.bin2hex(random_bytes(8));
        mkdir($this->workDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDirectory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->workDirectory);

        parent::tearDown();
    }

    #[Test]
    public function without_a_verified_release_the_endpoint_stays_not_configured(): void
    {
        $this->bindRelease($this->workDirectory.'/missing.apk', $this->workDirectory.'/missing.json');

        $this->postJson('/api/v1/public/download/verify-checksum', ['sha256' => str_repeat('a', 64)])
            ->assertStatus(501)
            ->assertJsonPath('state', 'NOT_CONFIGURED');
    }

    #[Test]
    public function a_matching_digest_is_reported_as_a_match(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();
        $this->bindRelease($apk, $manifest);

        $this->postJson('/api/v1/public/download/verify-checksum', ['sha256' => strtoupper(hash_file('sha256', $apk))])
            ->assertOk()
            ->assertJsonPath('state', 'MATCH')
            ->assertJsonPath('success', true)
            ->assertJsonPath('version', '1.0.0')
            ->assertJsonPath('file', 'app/thai-apk.apk');
    }

    #[Test]
    public function a_foreign_digest_is_reported_as_no_match(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();
        $this->bindRelease($apk, $manifest);

        $this->postJson('/api/v1/public/download/verify-checksum', ['sha256' => str_repeat('c', 64)])
            ->assertOk()
            ->assertJsonPath('state', 'NO_MATCH')
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function malformed_digests_are_refused_without_comparing_anything(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();
        $this->bindRelease($apk, $manifest);

        foreach (['', 'zz', str_repeat('a', 63), "'; DROP TABLE users; --"] as $bad) {
            $this->postJson('/api/v1/public/download/verify-checksum', ['sha256' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('state', 'INVALID_INPUT');
        }
    }

    #[Test]
    public function the_download_page_reports_the_verified_release_and_links_the_apk(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();
        $this->bindRelease($apk, $manifest);

        $page = (string) $this->get('/download')->assertOk()->getContent();

        $this->assertStringContainsString('VERIFIED', $page);
        $this->assertStringContainsString('1.0.0', $page);
        $this->assertStringContainsString(hash_file('sha256', $apk), $page);
    }

    #[Test]
    public function without_a_verified_release_the_page_shows_the_honest_empty_state(): void
    {
        $this->bindRelease($this->workDirectory.'/missing.apk', $this->workDirectory.'/missing.json');

        $page = (string) $this->get('/download')->assertOk()->getContent();

        $this->assertStringNotContainsString('VERIFIED', $page);
        $this->assertStringContainsString('NOT_CONFIGURED', $page);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function writeVerifiedPair(): array
    {
        $apk = $this->workDirectory.'/thai-apk.apk';
        $manifest = $this->workDirectory.'/thai-apk.manifest.json';

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($apk, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('AndroidManifest.xml', '<manifest package="com.ainul550.thailottery"/>');
        $archive->addFromString('classes.dex', 'dex');
        $this->assertTrue($archive->close());

        file_put_contents($manifest, json_encode([
            'status' => 'VERIFIED',
            'version' => '1.0.0',
            'package_name' => 'com.ainul550.thailottery',
            'web_app_url' => 'https://lottery.example.com',
            'file' => 'app/thai-apk.apk',
            'sha256' => (string) hash_file('sha256', $apk),
            'size_bytes' => filesize($apk),
            'built_at' => '2026-10-16T00:00:00Z',
            'source_commit' => str_repeat('a', 40),
        ], JSON_THROW_ON_ERROR));

        return [$apk, $manifest];
    }

    private function bindRelease(string $apkPath, string $manifestPath): void
    {
        $this->app->instance(
            AndroidApkReleaseService::class,
            new AndroidApkReleaseService($apkPath, $manifestPath)
        );
    }
}
