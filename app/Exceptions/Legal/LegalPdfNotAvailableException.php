<?php

declare(strict_types=1);

namespace App\Exceptions\Legal;

use RuntimeException;

/**
 * A legal document PDF could not be produced.
 *
 * THROWN FOR EVERY HONEST REASON, NEVER TO PAPER OVER A FAILURE
 *  - the lane is switched off (LEGAL_PDF_ENABLED=false);
 *  - the type or locale is not supported;
 *  - no document is published for that type/locale;
 *  - the stored content hash does not match the stored content (tampering);
 *  - the renderer produced no PDF bytes.
 *
 * The caller maps it to a safe 404/503 answer. The message is operator-facing
 * diagnostics and must never be shown to a public client verbatim: the reason
 * code is the only part a client is allowed to branch on.
 */
final class LegalPdfNotAvailableException extends RuntimeException
{
    /**
     * The stable machine code for "no verified export is available".
     */
    public const CODE = 'legal_pdf_not_available';

    /** The lane is off in configuration. */
    public const REASON_DISABLED = 'legal_pdf_disabled';

    /** The requested document type is not one this exporter renders. */
    public const REASON_UNSUPPORTED_TYPE = 'legal_pdf_unsupported_type';

    /** The requested locale is not a supported document language. */
    public const REASON_UNSUPPORTED_LOCALE = 'legal_pdf_unsupported_locale';

    /** Nothing is published for this type/locale. */
    public const REASON_NOT_PUBLISHED = 'legal_pdf_not_published';

    /** The stored content hash disagrees with the stored content. */
    public const REASON_HASH_MISMATCH = 'legal_pdf_content_hash_mismatch';

    /** The renderer returned no bytes, or returned something that is not a PDF. */
    public const REASON_RENDER_FAILED = 'legal_pdf_render_failed';

    /** The font files the renderer needs are missing. */
    public const REASON_FONTS_MISSING = 'legal_pdf_fonts_missing';

    private function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(
            self::REASON_DISABLED,
            'Legal PDF export is disabled in this environment (LEGAL_PDF_ENABLED=false).',
        );
    }

    public static function unsupportedType(string $type): self
    {
        return new self(
            self::REASON_UNSUPPORTED_TYPE,
            sprintf('Legal PDF export does not render document type "%s".', $type),
        );
    }

    public static function unsupportedLocale(string $locale): self
    {
        return new self(
            self::REASON_UNSUPPORTED_LOCALE,
            sprintf('Legal PDF export does not render locale "%s".', $locale),
        );
    }

    public static function notPublished(string $type, string $locale): self
    {
        return new self(
            self::REASON_NOT_PUBLISHED,
            sprintf('No verified %s document is published for locale %s.', $type, $locale),
        );
    }

    public static function hashMismatch(string $type, string $locale, string $version): self
    {
        return new self(
            self::REASON_HASH_MISMATCH,
            sprintf(
                'The stored content hash of %s (%s, %s) does not match its stored content; '
                .'the export is refused rather than stamping an unverified document.',
                $type,
                $locale,
                $version,
            ),
        );
    }

    public static function renderFailed(string $reason): self
    {
        return new self(
            self::REASON_RENDER_FAILED,
            'The PDF renderer produced no usable document: '.$reason,
        );
    }

    public static function fontsMissing(string $path): self
    {
        return new self(
            self::REASON_FONTS_MISSING,
            'The PDF font files are missing from '.$path.'; nothing is rendered with a fallback font.',
        );
    }

    /**
     * Code string a client (or a sibling service) may branch on.
     */
    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * Which of the REASON_* constants caused this refusal.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
