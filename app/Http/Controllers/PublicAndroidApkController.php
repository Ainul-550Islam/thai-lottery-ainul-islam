<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Media\AndroidApkReleaseService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public, fail-closed download endpoints for the signed Android release.
 *
 * android/README.md defines the contract: the /app/thai-apk.apk endpoint
 * remains fail-closed until the deployed binary matches its manifest. This
 * controller serves nothing unless AndroidApkReleaseService reports VERIFIED,
 * which requires the APK and app/thai-apk.manifest.json to agree on package
 * identity, semantic version, public HTTPS destination, archive structure,
 * byte length, and SHA-256 digest.
 *
 * Any missing file, unreadable or malformed manifest, tampered binary, or
 * internal disagreement is answered with a plain 404 and no-store caching.
 * The endpoint never falls back to a partial or unverified artifact.
 */
final class PublicAndroidApkController
{
    private const APK_FILENAME = 'thai-apk.apk';

    private const APK_CONTENT_TYPE = 'application/vnd.android.package-archive';

    public function __construct(private readonly AndroidApkReleaseService $release)
    {
    }

    public function apk(): BinaryFileResponse|JsonResponse
    {
        $metadata = $this->release->metadata();
        if ($metadata === null) {
            return $this->failClosed();
        }

        $response = response()->download(
            $this->release->apkFilePath(),
            self::APK_FILENAME,
            [
                'Content-Type' => self::APK_CONTENT_TYPE,
                'X-Content-Type-Options' => 'nosniff',
            ],
            'attachment'
        );

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    public function manifest(): JsonResponse
    {
        $metadata = $this->release->metadata();
        if ($metadata === null) {
            return $this->failClosed();
        }

        return response()->json(
            $metadata,
            200,
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
            JSON_UNESCAPED_SLASHES
        );
    }

    private function failClosed(): JsonResponse
    {
        return response()->json(
            [
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'No verified Android release is available.',
            ],
            404,
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
            JSON_UNESCAPED_SLASHES
        );
    }
}
