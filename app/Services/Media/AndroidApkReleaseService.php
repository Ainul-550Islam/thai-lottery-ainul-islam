<?php

declare(strict_types=1);

namespace App\Services\Media;

use DateTimeImmutable;
use JsonException;
use ZipArchive;

/**
 * Reads and validates the Android APK release artifact that is served publicly.
 *
 * A binary alone is not a configured download. The release workflow places a
 * matching, generated manifest at app/thai-apk.manifest.json. This service only
 * considers the APK ready when its package identity, version, deployment URL,
 * archive structure, byte length, and SHA-256 digest agree with that manifest.
 */
final class AndroidApkReleaseService
{
    public const APK_RELATIVE_PATH = 'app/thai-apk.apk';

    public const MANIFEST_RELATIVE_PATH = 'app/thai-apk.manifest.json';

    public const PACKAGE_NAME = 'com.ainul550.thailottery';

    private readonly string $apkFilePath;

    private readonly string $manifestFilePath;

    public function __construct(?string $apkFilePath = null, ?string $manifestFilePath = null)
    {
        $this->apkFilePath = $apkFilePath ?? base_path(self::APK_RELATIVE_PATH);
        $this->manifestFilePath = $manifestFilePath ?? base_path(self::MANIFEST_RELATIVE_PATH);
    }

    /**
     * Return release metadata only when the APK and its build manifest agree.
     *
     * @return array{
     *     status: string,
     *     version: string,
     *     package_name: string,
     *     web_app_url: string,
     *     file: string,
     *     sha256: string,
     *     size_bytes: int,
     *     built_at: string,
     *     source_commit: string
     * }|null
     */
    public function metadata(): ?array
    {
        if (! is_file($this->apkFilePath) || ! is_readable($this->apkFilePath)) {
            return null;
        }

        if (! is_file($this->manifestFilePath) || ! is_readable($this->manifestFilePath)) {
            return null;
        }

        $manifestContents = file_get_contents($this->manifestFilePath);
        if (! is_string($manifestContents) || $manifestContents === '') {
            return null;
        }

        try {
            $manifest = json_decode($manifestContents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($manifest)
            || ($manifest['status'] ?? null) !== 'VERIFIED'
            || ($manifest['package_name'] ?? null) !== self::PACKAGE_NAME
            || ($manifest['file'] ?? null) !== self::APK_RELATIVE_PATH) {
            return null;
        }

        $version = $manifest['version'] ?? null;
        if (! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', $version) !== 1) {
            return null;
        }

        $webAppUrl = $this->validatedWebAppUrl($manifest['web_app_url'] ?? null);
        if ($webAppUrl === null) {
            return null;
        }

        $expectedSha256 = strtolower((string) ($manifest['sha256'] ?? ''));
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
            return null;
        }

        clearstatcache(true, $this->apkFilePath);
        $actualSize = filesize($this->apkFilePath);
        $manifestSize = filter_var($manifest['size_bytes'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($actualSize) || $actualSize <= 0 || $manifestSize === false || $manifestSize !== $actualSize) {
            return null;
        }

        if (! $this->hasAndroidPackageStructure()) {
            return null;
        }

        $actualSha256 = hash_file('sha256', $this->apkFilePath);
        if (! is_string($actualSha256) || ! hash_equals($expectedSha256, strtolower($actualSha256))) {
            return null;
        }

        $builtAt = $manifest['built_at'] ?? null;
        if (! is_string($builtAt) || $builtAt === '' || strtotime($builtAt) === false) {
            return null;
        }

        $sourceCommit = strtolower((string) ($manifest['source_commit'] ?? ''));
        if (preg_match('/\A[a-f0-9]{40}\z/', $sourceCommit) !== 1) {
            return null;
        }

        return [
            'status' => 'VERIFIED',
            'version' => $version,
            'package_name' => self::PACKAGE_NAME,
            'web_app_url' => $webAppUrl,
            'file' => self::APK_RELATIVE_PATH,
            'sha256' => strtolower($actualSha256),
            'size_bytes' => $actualSize,
            'built_at' => (new DateTimeImmutable($builtAt))->format(DATE_ATOM),
            'source_commit' => $sourceCommit,
        ];
    }

    public function apkFilePath(): string
    {
        return $this->apkFilePath;
    }

    private function validatedWebAppUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);
        $parts = parse_url($url);
        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return null;
        }

        return $url;
    }

    private function hasAndroidPackageStructure(): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $archive = new ZipArchive;
        $opened = @$archive->open($this->apkFilePath);
        if ($opened !== true) {
            return false;
        }

        $hasManifest = $archive->locateName('AndroidManifest.xml') !== false;
        $hasDex = false;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->getNameIndex($index);
            if (is_string($entry) && preg_match('/\Aclasses(?:[1-9][0-9]*)?\.dex\z/', $entry) === 1) {
                $hasDex = true;
                break;
            }
        }

        $archive->close();

        return $hasManifest && $hasDex;
    }
}
