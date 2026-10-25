<?php

declare(strict_types=1);

namespace App\DTOs\Legal;

/**
 * A rendered, hash-stamped legal document export.
 *
 * THE CONTRACT: App\Services\PublicPages\LegalPdfExportService::export() returns
 * this object, and a caller (the public download endpoints, the Terms/Fees
 * controllers, or the privacy-request PDF lane) streams $contents with
 * $filename, quotes $sha256 as the ETag, and shows $version as the document
 * revision. Nothing in here is operator-editable after the fact: the hash is
 * computed from the bytes that are actually returned, so a client can verify
 * that the file it stores is the file this deployment produced.
 *
 * IMMUTABILITY
 * Readonly on purpose. A response object that could be mutated after the hash
 * was taken is a response object whose hash may not describe its bytes.
 */
final readonly class LegalPdfDocument
{
    /**
     * @param  string  $contents  raw PDF bytes, exactly as they are streamed
     * @param  string  $filename  ASCII, attachment-safe file name
     * @param  string  $sha256    64-character lowercase hex digest of $contents
     * @param  string  $version   the document revision the PDF was rendered from
     */
    public function __construct(
        public string $contents,
        public string $filename,
        public string $sha256,
        public string $version,
    ) {
    }

    public function length(): int
    {
        return strlen($this->contents);
    }

    /**
     * The ETag value for this export, quoted per RFC 9110.
     */
    public function etag(): string
    {
        return '"'.$this->sha256.'"';
    }

    /**
     * A short, human-readable hash fragment (used in the page footer).
     */
    public function shortHash(int $chars = 16): string
    {
        $chars = max(0, min(64, $chars));

        return substr($this->sha256, 0, $chars);
    }

    /**
     * The document had better start with the PDF magic number: a caller that
     * streams an HTML error page with a .pdf name is a caller that lies.
     */
    public function isPdf(): bool
    {
        return str_starts_with($this->contents, '%PDF-');
    }
}
