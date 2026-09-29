<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/*
 * PROMPT 3 — hardened identity-document upload validation.
 *
 * NEVER trusted: the original filename, the client extension, the
 * client MIME header. Verified here: real content MIME (finfo), the
 * extension whitelist, the size ceiling, image dimension sanity and
 * corruption (getimagesize), executable/double-extension tricks and
 * path traversal payloads in the client-supplied name.
 *
 * This rule runs BEFORE any byte reaches storage. Storage itself
 * (DocumentStorageService) re-derives the object key server-side, so
 * even a name that slips through here cannot choose its path.
 */
final class DocumentUploadRule implements ValidationRule
{
    /** Executable / dangerous filename fragments, checked case-insensitively. */
    private const DANGEROUS_NAME_PATTERNS = [
        '/\.ph(p[0-9]?|t|ar|ps)$/i',
        '/\.(exe|com|bat|cmd|sh|bash|cgi|pl|py|rb|jar|war|so|dll|msi|scr|vbs|js|html?|svg)$/i',
        '/\.phtml$/i',
        '/\.htaccess$/i',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('account_services.verification_error_file_required')->translate();

            return;
        }

        if (! $value->isValid()) {
            $fail('account_services.verification_error_file_invalid')->translate();

            return;
        }

        $allowedExtensions = (array) config('account_verification.uploads.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png', 'webp']);
        $allowedMimes = (array) config('account_verification.uploads.allowed_mime_types', [
            'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        ]);
        $maxBytes = ((int) config('account_verification.uploads.max_file_kb', 10240)) * 1024;

        $originalName = (string) $value->getClientOriginalName();

        // 1) Filename hygiene: traversal + executable/double extensions.
        if ($originalName !== '' && preg_match('/(\.\.|\/|\\\\)/', $originalName) === 1) {
            $fail('account_services.verification_error_filename')->translate();

            return;
        }

        foreach (self::DANGEROUS_NAME_PATTERNS as $pattern) {
            if ($originalName !== '' && preg_match($pattern, $originalName) === 1) {
                $fail('account_services.verification_error_filename')->translate();

                return;
            }
        }

        // 2) Extension whitelist (server-side, from the uploaded path info).
        $extension = mb_strtolower((string) $value->getClientOriginalExtension());
        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            $fail('account_services.verification_error_mimetype')->translate();

            return;
        }

        // 3) Size ceiling (a second line — the request-level max: rule is first).
        $size = (int) $value->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            $fail('account_services.verification_error_size')->translate();

            return;
        }

        // 4) Content MIME: finfo on the real bytes, never the header the client sent.
        $contentMime = $this->sniffContentMime($value->getRealPath());
        if ($contentMime === null || ! in_array($contentMime, $allowedMimes, true)) {
            $fail('account_services.verification_error_mimetype')->translate();

            return;
        }

        // 5) Image sanity: raster images must parse, be non-absurd and
        //    non-trivial; anything that fails getimagesize is corrupt or
        //    a disguised non-image.
        if (str_starts_with((string) $contentMime, 'image/')) {
            $info = @getimagesize($value->getRealPath());

            if ($info === false) {
                $fail('account_services.verification_error_corrupt')->translate();

                return;
            }

            $width = (int) ($info[0] ?? 0);
            $height = (int) ($info[1] ?? 0);
            $minW = (int) config('account_verification.uploads.min_image_width', 60);
            $minH = (int) config('account_verification.uploads.min_image_height', 60);
            $maxW = (int) config('account_verification.uploads.max_image_width', 10000);
            $maxH = (int) config('account_verification.uploads.max_image_height', 10000);

            if ($width < $minW || $height < $minH || $width > $maxW || $height > $maxH) {
                $fail('account_services.verification_error_dimensions')->translate();

                return;
            }
        }
    }

    /**
     * Real content type from the bytes on disk. Null when sniffing is
     * impossible (already-vanished temp file etc.).
     */
    private function sniffContentMime(string|false $path): ?string
    {
        if ($path === false || $path === '' || ! is_readable($path)) {
            return null;
        }

        $finfo = @new \finfo(FILEINFO_MIME_TYPE);

        if (! $finfo instanceof \finfo) {
            return null;
        }

        $mime = $finfo->file($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }
}
