<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Media\AndroidApkReleaseService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * THE RELEASE EVIDENCE CONTRACT (android/README.md).
 *
 * metadata() is the single gate the public download depends on. It must return
 * null for every kind of disagreement between app/thai-apk.apk and
 * app/thai-apk.manifest.json — a missing half, a wrong package name, a stale
 * version, a local destination, a size that almost matches, a digest that
 * almost matches — and a VERIFIED projection only when every field agrees.
 * "Almost" is the enemy here: an almost-correct manifest that serves a binary
 * is exactly the failure this class exists to prevent.
 */
class AndroidApkReleaseServiceTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = sys_get_temp_dir().'/thai-apk-service-'.bin2hex(random_bytes(8));
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
    public function a_verified_apk_and_manifest_produce_the_full_evidence_projection(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();

        $metadata = (new AndroidApkReleaseService($apk, $manifest))->metadata();

        $this->assertIsArray($metadata);
        $this->assertSame('VERIFIED', $metadata['status']);
        $this->assertSame('1.0.0', $metadata['version']);
        $this->assertSame('com.ainul550.thailottery', $metadata['package_name']);
        $this->assertSame('https://lottery.example.com', $metadata['web_app_url']);
        $this->assertSame('app/thai-apk.apk', $metadata['file']);
        $this->assertSame(hash_file('sha256', $apk), $metadata['sha256']);
        $this->assertSame(filesize($apk), $metadata['size_bytes']);
        $this->assertNotFalse(strtotime($metadata['built_at']));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/', $metadata['source_commit']);
    }

    #[Test]
    public function either_file_missing_is_not_a_release(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();

        $this->assertNull((new AndroidApkReleaseService($apk, $this->workDirectory.'/nope.json'))->metadata());

        unlink($apk);
        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_manifest_that_is_not_json_is_refused(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();
        file_put_contents($manifest, 'not json at all');

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_package_name_the_platform_does_not_own_is_refused(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair(['package_name' => 'club.lottery-platform.official']);

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_local_or_cleartext_deployment_url_is_refused(): void
    {
        foreach (['http://lottery.example.com', 'https://localhost', 'https://host.internal', 'https://printer.local'] as $url) {
            [$apk, $manifest] = $this->writeVerifiedPair(['web_app_url' => $url]);

            $this->assertNull(
                (new AndroidApkReleaseService($apk, $manifest))->metadata(),
                $url.' must never be treated as a production destination.',
            );
        }
    }

    #[Test]
    public function a_size_mismatch_is_refused_even_when_the_digest_would_match(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair(['size_bytes' => 999999]);

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_digest_mismatch_is_refused(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair(['sha256' => str_repeat('b', 64)]);

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_status_other_than_verified_is_refused(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair(['status' => 'PENDING']);

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_binary_without_the_android_archive_shape_is_refused(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair();

        // Replace the archive with raw bytes of identical length: the size in
        // the manifest still matches and the digest is recomputed, so only the
        // archive-structure check can catch this.
        $size = filesize($apk);
        file_put_contents($apk, str_repeat('A', $size));
        $payload = json_decode((string) file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        $payload['sha256'] = (string) hash_file('sha256', $apk);
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    #[Test]
    public function a_bad_version_shape_is_refused(): void
    {
        [$apk, $manifest] = $this->writeVerifiedPair(['version' => 'v4.8.2 (Build 4820)']);

        $this->assertNull((new AndroidApkReleaseService($apk, $manifest))->metadata());
    }

    /**
     * @param  array<string, mixed>  $manifestOverrides
     * @return array{0: string, 1: string}
     */
    private function writeVerifiedPair(array $manifestOverrides = []): array
    {
        $apk = $this->workDirectory.'/thai-apk.apk';
        $manifest = $this->workDirectory.'/thai-apk.manifest.json';

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($apk, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('AndroidManifest.xml', '<manifest package="com.ainul550.thailottery"/>');
        $archive->addFromString('classes.dex', 'dex');
        $this->assertTrue($archive->close());

        $payload = array_merge([
            'status' => 'VERIFIED',
            'version' => '1.0.0',
            'package_name' => 'com.ainul550.thailottery',
            'web_app_url' => 'https://lottery.example.com',
            'file' => 'app/thai-apk.apk',
            'sha256' => (string) hash_file('sha256', $apk),
            'size_bytes' => filesize($apk),
            'built_at' => '2026-10-16T00:00:00Z',
            'source_commit' => str_repeat('a', 40),
        ], $manifestOverrides);

        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR));

        return [$apk, $manifest];
    }
}
