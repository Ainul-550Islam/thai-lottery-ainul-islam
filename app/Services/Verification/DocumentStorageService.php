<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\KycDocumentType;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/*
 * PROMPT 3 — private, non-guessable document storage.
 *
 * The hardened conventions of the existing Security KYC service,
 * lifted into the canonical storage abstraction for the member
 * verification surface (front + back pair):
 *
 * - PRIVATE disk only (config-driven, default 'local' — never public/);
 * - the object key is 100% server-generated: prefix/user-id/40 random
 *   hex chars + a whitelisted extension derived from the SNIFFED
 *   content type, not from the client name;
 * - the client filename is stored as DISPLAY metadata only and is
 *   never used in any path;
 * - every byte is re-validated (content MIME vs extension) at write
 *   time — a mismatch refuses storage;
 * - retrieval is authorization-gated: only readForAuthorized() exists,
 *   and its callers enforce owner-or-reviewer policy. No public URL
 *   for a document is ever produced by this class.
 */
final class DocumentStorageService
{
    /**
     * Content-MIME -> allowed extension. The ONLY extension source.
     *
     * @var array<string, string>
     */
    private const MIME_EXTENSION = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function store(
        User $user,
        KycDocumentType $type,
        UploadedFile $file,
        ?string $documentNumber = null,
        ?string $ipAddress = null,
    ): KycDocument {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('Uploaded file is invalid or corrupted.');
        }

        $contentMime = $this->sniffContentMime($file->getRealPath());

        if ($contentMime === null || ! isset(self::MIME_EXTENSION[$contentMime])) {
            throw new InvalidArgumentException(
                'Invalid document file type. Allowed: PDF, JPG, PNG, WEBP.'
            );
        }

        $maxBytes = ((int) config('account_verification.uploads.max_file_kb', 10240)) * 1024;
        $size = (int) $file->getSize();

        if ($size <= 0 || $size > $maxBytes) {
            throw new InvalidArgumentException('Document exceeds maximum size limit.');
        }

        // Raster images must parse and be sane — corruption guard.
        if (str_starts_with($contentMime, 'image/')) {
            $info = @getimagesize($file->getRealPath());

            if ($info === false) {
                throw new InvalidArgumentException('Document image is corrupt or unreadable.');
            }
        }

        $diskName = (string) config('account_verification.storage.disk', 'local');
        $prefix = trim((string) config('account_verification.storage.path_prefix', 'kyc_documents'), '/');

        // The non-guessable object key: everything server-derived, in the
        // platform's established kyc_{userId}_{random}.{ext} convention
        // (32 random hex-charset chars = 128+ bits of entropy).
        $extension = self::MIME_EXTENSION[$contentMime];
        $safeName = sprintf('kyc_%d_%s.%s', $user->id, Str::random(32), $extension);
        $path = $prefix.'/'.$user->id.'/'.$safeName;

        $disk = Storage::disk($diskName);

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new InvalidArgumentException('Uploaded file could not be read.');
        }

        $disk->put($path, $stream);
        fclose($stream);

        // Read-back verification: the stored bytes carry the same
        // content type we accepted.
        $stored = $disk->get($path);

        if ($stored === null
            || $this->sniffContentOfBytes((string) $stored) !== $contentMime) {
            $disk->delete($path);

            throw new InvalidArgumentException('Document failed the storage integrity check.');
        }

        $document = KycDocument::create([
            'user_id' => $user->id,
            'document_type' => $type,
            'document_number' => $documentNumber !== null && $documentNumber !== '' ? $documentNumber : null,
            'file_path' => $path,
            // Display-only; never used in any path.
            'original_filename' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime_type' => $contentMime,
            'file_size' => $size,
            'status' => \App\Enums\KycStatus::Pending,
            'metadata' => [
                'submission_ip' => $ipAddress,
                'submitted_at' => now()->toIso8601String(),
                'storage_disk' => $diskName,
                'storage' => 'private',
            ],
        ]);

        return $document;
    }

    /**
     * Authorization-gated read. Callers MUST have proven owner-or-
     * reviewer before invoking; this method re-checks the subject
     * ownership belt-and-braces and never emits a public URL.
     *
     * @return array{contents: string, mime: string, name: string}
     */
    public function readForAuthorized(KycDocument $document, User $viewer, bool $isReviewer): array
    {
        $ownerId = (int) $document->user_id;

        if ($ownerId !== (int) $viewer->id && ! $isReviewer) {
            throw new InvalidArgumentException('Not authorized to read this document.');
        }

        $diskName = (string) (($document->metadata['storage_disk'] ?? null) ?? config('account_verification.storage.disk', 'local'));
        $disk = Storage::disk($diskName);

        if (! $disk->exists((string) $document->file_path)) {
            throw new InvalidArgumentException('Document is not available.');
        }

        $contents = $disk->get((string) $document->file_path);

        if ($contents === null) {
            throw new InvalidArgumentException('Document is not available.');
        }

        $extension = self::MIME_EXTENSION[(string) $document->mime_type] ?? 'bin';

        return [
            'contents' => $contents,
            'mime' => (string) $document->mime_type,
            'name' => 'verification-document.'.$extension,
        ];
    }

    /**
     * Does a path live under the private prefix? (Defence-in-depth for
     * anything tempted to build a URL from a stored path.)
     */
    public function isPrivatePath(string $path): bool
    {
        $prefix = trim((string) config('account_verification.storage.path_prefix', 'kyc_documents'), '/');

        return str_starts_with(trim($path, '/'), $prefix.'/');
    }

    private function sniffContentMime(string|false $path): ?string
    {
        if ($path === false || $path === '' || ! is_readable($path)) {
            return null;
        }

        return $this->sniffContentOfBytes((string) file_get_contents($path));
    }

    private function sniffContentOfBytes(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $finfo = @new \finfo(FILEINFO_MIME_TYPE);

        if (! $finfo instanceof \finfo) {
            return null;
        }

        $mime = $finfo->buffer($bytes);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }
}
