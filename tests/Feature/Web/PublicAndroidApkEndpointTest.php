<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Services\Media\AndroidApkReleaseService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * SIGNED ANDROID APK ENDPOINT FAILS CLOSED (android/README.md contract).
 *
 * The /app/thai-apk.apk endpoint and its manifest endpoint may only serve a
 * release whose binary and app/thai-apk.manifest.json agree on package
 * identity, semantic version, public HTTPS destination, archive structure,
 * byte length, and SHA-256. Every disagreement - a missing file, a tampered
 * binary, a wrong status, a fabricated digest - answers 404 and serves
 * nothing. A partial or unverified artifact is never a fallback.
 */
class PublicAndroidApkEndpointTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = sys_get_temp_dir().'/thai-apk-endpoint-'.bin2hex(random_bytes(8));
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

    public function test_missing_artifacts_answer_not_found_on_both_endpoints(): void
    {
        $this->bindRelease($this->workDirectory.'/missing.apk', $this->workDirectory.'/missing.json');

        $this->get('/app/thai-apk.apk')->assertNotFound();
        $this->get('/app/thai-apk.manifest.json')->assertNotFound();
    }

    public function test_manifest_without_apk_fails_closed(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);
        $this->writeManifest($manifestPath, $apkPath);
        unlink($apkPath);

        $this->bindRelease($apkPath, $manifestPath);

        $this->get('/app/thai-apk.apk')->assertNotFound();
        $this->get('/app/thai-apk.manifest.json')->assertNotFound();
    }

    public function test_apk_without_manifest_fails_closed(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);

        $this->bindRelease($apkPath, $manifestPath);

        $this->get('/app/thai-apk.apk')->assertNotFound();
        $this->get('/app/thai-apk.manifest.json')->assertNotFound();
    }

    public function test_tampered_binary_must_not_serve(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);
        $this->writeManifest($manifestPath, $apkPath);

        // Same byte length as the manifested binary, different content: only a
        // real SHA-256 comparison can catch this, not a size check.
        $bytes = file_get_contents($apkPath);
        $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'A' ? 'B' : 'A';
        file_put_contents($apkPath, $bytes);

        $this->bindRelease($apkPath, $manifestPath);

        $this->get('/app/thai-apk.apk')->assertNotFound();
        $this->get('/app/thai-apk.manifest.json')->assertNotFound();
    }

    public function test_unverified_manifest_status_fails_closed(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);
        $manifest = $this->manifestPayload($apkPath);
        $manifest['status'] = 'PENDING';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->bindRelease($apkPath, $manifestPath);

        $this->get('/app/thai-apk.apk')->assertNotFound();
        $this->get('/app/thai-apk.manifest.json')->assertNotFound();
    }

    public function test_local_or_cleartext_deployment_urls_fail_closed(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);
        $manifest = $this->manifestPayload($apkPath);
        $manifest['web_app_url'] = 'https://localhost:8443';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->bindRelease($apkPath, $manifestPath);

        $this->get('/app/thai-apk.apk')->assertNotFound();
        $this->get('/app/thai-apk.manifest.json')->assertNotFound();
    }

    public function test_verified_release_serves_apk_and_manifest(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);
        $this->writeManifest($manifestPath, $apkPath);

        $this->bindRelease($apkPath, $manifestPath);

        $apkResponse = $this->get('/app/thai-apk.apk');
        $apkResponse->assertOk();
        $apkResponse->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $apkResponse->assertDownload('thai-apk.apk');

        $baseResponse = $apkResponse->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $baseResponse);
        $this->assertSame(
            realpath($apkPath),
            realpath($baseResponse->getFile()->getPathname())
        );

        $manifestResponse = $this->get('/app/thai-apk.manifest.json');
        $manifestResponse->assertOk();
        $manifestResponse->assertHeader('Content-Type', 'application/json');

        $payload = $manifestResponse->json();
        $this->assertIsArray($payload);
        $this->assertSame('VERIFIED', $payload['status']);
        $this->assertSame('1.0.0', $payload['version']);
        $this->assertSame('com.ainul550.thailottery', $payload['package_name']);
        $this->assertSame('https://lottery.example.com', $payload['web_app_url']);
        $this->assertSame('app/thai-apk.apk', $payload['file']);
        $this->assertSame(hash_file('sha256', $apkPath), $payload['sha256']);
        $this->assertSame(filesize($apkPath), $payload['size_bytes']);
    }

    public function test_verified_release_sha256_matches_downloaded_bytes(): void
    {
        $manifestPath = $this->workDirectory.'/thai-apk.manifest.json';
        $apkPath = $this->workDirectory.'/thai-apk.apk';
        $this->writeApk($apkPath);
        $this->writeManifest($manifestPath, $apkPath);

        $this->bindRelease($apkPath, $manifestPath);

        $expected = hash_file('sha256', $apkPath);
        $this->assertSame($expected, (string) $this->get('/app/thai-apk.manifest.json')->json('sha256'));
    }

    private function bindRelease(string $apkPath, string $manifestPath): void
    {
        $this->app->instance(
            AndroidApkReleaseService::class,
            new AndroidApkReleaseService($apkPath, $manifestPath)
        );
    }

    private function writeApk(string $apkPath): void
    {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($apkPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('AndroidManifest.xml', '<manifest package="com.ainul550.thailottery"/>');
        $archive->addFromString('classes.dex', 'dex');
        $this->assertTrue($archive->close());
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestPayload(string $apkPath): array
    {
        return [
            'status' => 'VERIFIED',
            'version' => '1.0.0',
            'package_name' => 'com.ainul550.thailottery',
            'web_app_url' => 'https://lottery.example.com',
            'file' => 'app/thai-apk.apk',
            'sha256' => (string) hash_file('sha256', $apkPath),
            'size_bytes' => filesize($apkPath),
            'built_at' => '2026-10-16T00:00:00Z',
            'source_commit' => str_repeat('a', 40),
        ];
    }

    private function writeManifest(string $manifestPath, string $apkPath): void
    {
        file_put_contents(
            $manifestPath,
            json_encode($this->manifestPayload($apkPath), JSON_THROW_ON_ERROR)
        );
    }
}
