<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Canonical ticket identity normalisation for public verification (PROMPT 4).
 *
 * WHY THIS EXISTS SEPARATELY
 * Three different surfaces already parse ticket-ish strings: GloTicket's
 * ticket_reference builder, GloPublicTicketVerificationService's reference
 * splitter, and GloTicketChecker::normaliseTicket. They each do the right
 * thing for their own caller. What the public verification page needs, and
 * what none of them offered, is ONE normalisation that decides, before any
 * database access, three things:
 *
 *   1. which PRODUCT FAMILY the input belongs to (GLO vs this operator's own
 *      betting tickets) - these must never be blended, because calling an
 *      operator bet an "official GLO ticket" would be a lie;
 *   2. whether the input is within the configured size/character bounds; and
 *   3. what its canonical string form is.
 *
 * LEADING ZEROS ARE DATA
 * Every number here is a STRING from input to output. '000001' is not 1,
 * '01' is not '1', and there is no intval()/(int) cast on any number-carrying
 * value in this file. The only integer cast in the class is on a draw id,
 * which is a database key and not a lottery number.
 *
 * THIS CLASS TOUCHES NO DATABASE. It is pure normalisation, so it can run
 * before rate limiting and before any query, which is exactly what makes the
 * bounds useful as a denial-of-service and enumeration guard.
 */
final class TicketIdentityService
{
    public const KIND_NUMBER = 'number';

    public const KIND_REFERENCE = 'reference';

    public const KIND_BARCODE = 'barcode';

    public const PRODUCT_GLO_L6 = 'glo_l6';

    public const PRODUCT_GLO_N3 = 'glo_n3';

    public const PRODUCT_OPERATOR = 'operator';

    public const PRODUCT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Normalise a raw public input.
     *
     * @return array{
     *     valid: bool,
     *     kind: string,
     *     product: string,
     *     canonical: string,
     *     number: string|null,
     *     draw_id: int|null,
     *     set_series: string|null,
     *     reason: string|null,
     *     length: int
     * }
     */
    public function normalise(string $kind, string $raw, ?string $product = null): array
    {
        $kind = strtolower(trim($kind));

        return match ($kind) {
            self::KIND_NUMBER => $this->normaliseNumber($raw, $product),
            self::KIND_REFERENCE => $this->normaliseReference($raw),
            self::KIND_BARCODE => $this->normaliseBarcode($raw),
            default => $this->reject(self::KIND_NUMBER, 'UNSUPPORTED_MODE'),
        };
    }

    /**
     * A plain lottery number: 6 digits (GLO L6), 3 or 2 digits (GLO N3).
     *
     * @return array{valid: bool, kind: string, product: string, canonical: string, number: string|null, draw_id: int|null, set_series: string|null, reason: string|null, length: int}
     */
    public function normaliseNumber(string $raw, ?string $product = null): array
    {
        // Strip only formatting humans add: spaces and hyphens between digits.
        // Nothing else is "cleaned", because silently deleting characters is
        // how a wrong ticket becomes a right-looking one.
        $value = (string) preg_replace('/[\s\-]+/', '', $raw);

        $maxLength = (int) $this->config->get('ticket_verification.input.number.max_length', 6);

        if ($value === '' || strlen($value) > $maxLength) {
            return $this->reject(self::KIND_NUMBER, 'LENGTH_OUT_OF_BOUNDS');
        }

        $pattern = (string) $this->config->get('ticket_verification.input.number.pattern', '/^[0-9]{2,6}$/');

        if (! preg_match($pattern, $value)) {
            return $this->reject(self::KIND_NUMBER, 'MALFORMED_NUMBER');
        }

        /** @var list<int> $allowed */
        $allowed = (array) $this->config->get('ticket_verification.input.number.allowed_lengths', [2, 3, 6]);

        if (! in_array(strlen($value), array_map('intval', $allowed), true)) {
            return $this->reject(self::KIND_NUMBER, 'LENGTH_NOT_SUPPORTED');
        }

        $resolved = $product !== null && $product !== ''
            ? $this->constrainProduct($product, $value)
            : $this->productForNumberLength($value);

        if ($resolved === null) {
            return $this->reject(self::KIND_NUMBER, 'PRODUCT_LENGTH_MISMATCH');
        }

        return [
            'valid' => true,
            'kind' => self::KIND_NUMBER,
            'product' => $resolved,
            // Canonical form of a number is the digit string itself, zeros intact.
            'canonical' => $value,
            'number' => $value,
            'draw_id' => null,
            'set_series' => null,
            'reason' => null,
            'length' => strlen($value),
        ];
    }

    /**
     * A ticket reference: either {drawId}-{product}-{number}[-{series}] as
     * built by GloTicket::buildReference, or this platform's own opaque
     * ticket_number for an operator ticket.
     *
     * @return array{valid: bool, kind: string, product: string, canonical: string, number: string|null, draw_id: int|null, set_series: string|null, reason: string|null, length: int}
     */
    public function normaliseReference(string $raw): array
    {
        $value = (string) preg_replace('/\s+/', '', $raw);

        $maxLength = (int) $this->config->get('ticket_verification.input.reference.max_length', 64);

        if ($value === '' || strlen($value) > $maxLength) {
            return $this->reject(self::KIND_REFERENCE, 'LENGTH_OUT_OF_BOUNDS');
        }

        $pattern = (string) $this->config->get(
            'ticket_verification.input.reference.pattern',
            '/^[0-9A-Za-z][0-9A-Za-z._:-]{2,63}$/',
        );

        if (! preg_match($pattern, $value)) {
            return $this->reject(self::KIND_REFERENCE, 'MALFORMED_REFERENCE');
        }

        // GLO shape first: it is the only shape whose parts carry meaning.
        $gloPattern = (string) $this->config->get(
            'glo.public_status.reference_pattern',
            '/^\d{1,10}-(l6|n3)-[0-9A-Za-z]{1,16}$/',
        );

        $parts = explode('-', $value);

        if (count($parts) >= 3 && preg_match('/^\d{1,10}$/', $parts[0]) === 1) {
            $drawId = (int) $parts[0];          // database key, not a lottery number
            $productToken = strtolower($parts[1]);
            $number = $parts[2];                 // STRING, zeros preserved
            $series = $parts[3] ?? null;

            $product = match ($productToken) {
                'l6' => self::PRODUCT_GLO_L6,
                'n3' => self::PRODUCT_GLO_N3,
                default => null,
            };

            if ($product === null) {
                return $this->reject(self::KIND_REFERENCE, 'UNKNOWN_PRODUCT_TOKEN');
            }

            if ($drawId < 1) {
                return $this->reject(self::KIND_REFERENCE, 'INVALID_DRAW');
            }

            if (! preg_match('/^[0-9A-Za-z]{1,16}$/', $number)) {
                return $this->reject(self::KIND_REFERENCE, 'MALFORMED_NUMBER');
            }

            if ($series !== null) {
                $seriesPattern = (string) $this->config->get(
                    'ticket_verification.input.set_series.pattern',
                    '/^[0-9A-Za-z]{1,16}$/',
                );

                if (! preg_match($seriesPattern, $series)) {
                    return $this->reject(self::KIND_REFERENCE, 'MALFORMED_SERIES');
                }
            }

            // The canonical GLO reference is lower-cased in its product token
            // only; the number keeps its exact characters.
            $canonical = $drawId.'-'.$productToken.'-'.$number.($series !== null ? '-'.$series : '');

            if ($gloPattern !== '' && $series === null && ! preg_match($gloPattern, $canonical)) {
                return $this->reject(self::KIND_REFERENCE, 'MALFORMED_REFERENCE');
            }

            return [
                'valid' => true,
                'kind' => self::KIND_REFERENCE,
                'product' => $product,
                'canonical' => $canonical,
                'number' => $number,
                'draw_id' => $drawId,
                'set_series' => $series,
                'reason' => null,
                'length' => strlen($canonical),
            ];
        }

        // Anything else in bounds is treated as an operator ticket reference.
        // It is NOT assumed to exist and it is never labelled a GLO ticket.
        return [
            'valid' => true,
            'kind' => self::KIND_REFERENCE,
            'product' => self::PRODUCT_OPERATOR,
            'canonical' => $value,
            'number' => null,
            'draw_id' => null,
            'set_series' => null,
            'reason' => null,
            'length' => strlen($value),
        ];
    }

    /**
     * A scanned payload. Product is UNKNOWN until an adapter that actually
     * supports the format says otherwise - this method only enforces bounds.
     *
     * @return array{valid: bool, kind: string, product: string, canonical: string, number: string|null, draw_id: int|null, set_series: string|null, reason: string|null, length: int}
     */
    public function normaliseBarcode(string $raw): array
    {
        $value = trim($raw);

        $maxLength = (int) $this->config->get('ticket_verification.input.barcode.max_length', 512);
        $maxBytes = (int) $this->config->get('ticket_verification.input.barcode.max_bytes', 4096);

        if ($value === '') {
            return $this->reject(self::KIND_BARCODE, 'EMPTY_PAYLOAD');
        }

        if (strlen($value) > $maxLength || strlen($value) > $maxBytes) {
            return $this->reject(self::KIND_BARCODE, 'PAYLOAD_TOO_LARGE');
        }

        $pattern = (string) $this->config->get(
            'ticket_verification.input.barcode.pattern',
            '/^[0-9A-Za-z+\/=:_.\-{}",\[\]\s]{8,512}$/',
        );

        if (! preg_match($pattern, $value)) {
            return $this->reject(self::KIND_BARCODE, 'MALFORMED_PAYLOAD');
        }

        return [
            'valid' => true,
            'kind' => self::KIND_BARCODE,
            'product' => self::PRODUCT_UNKNOWN,
            'canonical' => $value,
            'number' => null,
            'draw_id' => null,
            'set_series' => null,
            'reason' => null,
            'length' => strlen($value),
        ];
    }

    /**
     * Keyed hash of a canonical input, for evidence and rate-limit keys.
     *
     * The raw query is never stored or logged: the evidence trail must not
     * become a list of the numbers the public checked.
     */
    public function fingerprint(string $canonical): string
    {
        $algorithm = (string) $this->config->get('ticket_verification.evidence.hash_algorithm', 'sha256');
        $key = (string) ($this->config->get('ticket_verification.evidence.hash_key')
            ?? $this->config->get('app.key', ''));

        if (! in_array($algorithm, hash_algos(), true)) {
            $algorithm = 'sha256';
        }

        return hash_hmac($algorithm, $canonical, $key);
    }

    /**
     * Product families a length may belong to. Six digits is unambiguously
     * L6; two or three digits is the N3 family.
     */
    private function productForNumberLength(string $number): ?string
    {
        return match (strlen($number)) {
            6 => self::PRODUCT_GLO_L6,
            3, 2 => self::PRODUCT_GLO_N3,
            default => null,
        };
    }

    /**
     * Honour a caller-supplied product only when the length agrees with it.
     */
    private function constrainProduct(string $product, string $number): ?string
    {
        $product = strtolower(trim($product));
        $length = strlen($number);

        return match (true) {
            $product === self::PRODUCT_GLO_L6 && $length === 6 => self::PRODUCT_GLO_L6,
            $product === self::PRODUCT_GLO_N3 && ($length === 3 || $length === 2) => self::PRODUCT_GLO_N3,
            default => null,
        };
    }

    /**
     * @return array{valid: false, kind: string, product: string, canonical: string, number: null, draw_id: null, set_series: null, reason: string, length: int}
     */
    private function reject(string $kind, string $reason): array
    {
        return [
            'valid' => false,
            'kind' => $kind,
            'product' => self::PRODUCT_UNKNOWN,
            'canonical' => '',
            'number' => null,
            'draw_id' => null,
            'set_series' => null,
            'reason' => $reason,
            'length' => 0,
        ];
    }
}
