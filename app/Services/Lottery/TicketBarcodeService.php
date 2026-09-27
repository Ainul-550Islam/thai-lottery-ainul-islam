<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Barcode / QR / Data Matrix verification ADAPTER (PROMPT 4).
 *
 * WHAT THIS IS NOT
 * It is not a decoder. The proprietary GLO Data Matrix encoding is not
 * publicly documented, and this project already refuses to guess it: the
 * existing App\Services\Lottery\GloDataMatrixParser validates an envelope and
 * answers UNSUPPORTED_FORMAT unless an authorised schema is configured. That
 * decision is not re-litigated here and no second parser is introduced.
 *
 * WHAT THIS IS
 * The single place that turns whatever the underlying adapters say into the
 * four provider states the public page is allowed to show:
 *
 *   SUPPORTED          a real, configured provider read the payload
 *   NOT_CONFIGURED     no provider is wired for this format
 *   UNSUPPORTED_FORMAT a provider exists but cannot read this encoding
 *   INVALID            the payload is malformed, oversized or fails its schema
 *
 * SYNTHETIC FIXTURES ARE ALWAYS FLAGGED
 * A payload accepted under SYNTHETIC_FIXTURE_V1 is development data. It comes
 * back with fixture = true, source_state = fixture and official = false, and
 * the page prints the fixture label. A fixture decode is NEVER reported as
 * SUPPORTED and never as an official ticket read.
 *
 * BOUNDS BEFORE WORK
 * Size and character-set limits are enforced by TicketIdentityService before
 * a parser is constructed, so an oversized or hostile payload costs nothing.
 */
final class TicketBarcodeService
{
    public const SUPPORTED = 'SUPPORTED';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const UNSUPPORTED_FORMAT = 'UNSUPPORTED_FORMAT';

    public const INVALID = 'INVALID';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly TicketIdentityService $identity,
        private readonly GloDataMatrixParserInterface $gloDataMatrix,
    ) {}

    /**
     * Provider availability, without submitting a payload. Used by the page to
     * describe the scanner honestly before anyone scans anything.
     *
     * @return array{glo_data_matrix: string, operator_qr: string, fixture_schema: string, mode: string}
     */
    public function providerStates(): array
    {
        $mode = $this->gloDataMatrix->mode();

        return [
            'glo_data_matrix' => $mode === 'not_configured' ? self::NOT_CONFIGURED : self::SUPPORTED,
            // The operator QR attestation service is part of this application,
            // so it is available whenever ticket verification is enabled.
            'operator_qr' => (bool) $this->config->get('ticket_verification.enabled', true)
                ? self::SUPPORTED
                : self::NOT_CONFIGURED,
            'fixture_schema' => (string) $this->config->get(
                'ticket_verification.barcode.fixture_schema_version',
                'SYNTHETIC_FIXTURE_V1',
            ),
            'mode' => $mode,
        ];
    }

    /**
     * Read a scanned payload.
     *
     * @return array{
     *     state: string,
     *     product: string,
     *     number: string|null,
     *     draw_number: string|null,
     *     fixture: bool,
     *     official: bool,
     *     source_state: string|null,
     *     schema: string|null,
     *     reason: string|null
     * }
     */
    public function read(string $raw): array
    {
        $normalised = $this->identity->normaliseBarcode($raw);

        if ($normalised['valid'] === false) {
            return $this->result(
                state: self::INVALID,
                reason: (string) $normalised['reason'],
            );
        }

        $payload = $normalised['canonical'];

        // Operator QR/attestation envelopes carry this platform's own marker
        // and are handled by the existing ownership/attestation service via
        // TicketAuthenticityService; here we only classify the format.
        if ($this->looksLikeOperatorEnvelope($payload)) {
            return $this->result(
                state: self::SUPPORTED,
                product: TicketIdentityService::PRODUCT_OPERATOR,
                reason: null,
                official: false,
            );
        }

        $parsed = $this->gloDataMatrix->parse($payload);
        $status = (string) $parsed['status'];
        $schema = $parsed['schema'] ?? null;
        $sourceState = (string) $parsed['source_state'];

        if ($status === 'FIXTURE_PARSED' && is_array($parsed['payload'])) {
            $number = isset($parsed['payload']['ticket_number'])
                ? (string) $parsed['payload']['ticket_number']
                : null;

            $drawNumber = isset($parsed['payload']['draw_number']) && $parsed['payload']['draw_number'] !== null
                ? (string) $parsed['payload']['draw_number']
                : null;

            return $this->result(
                // A fixture read is deliberately NOT "SUPPORTED": the real
                // provider is still not configured.
                state: self::NOT_CONFIGURED,
                product: TicketIdentityService::PRODUCT_GLO_L6,
                number: $number,
                drawNumber: $drawNumber,
                fixture: true,
                official: false,
                sourceState: $sourceState,
                schema: is_string($schema) ? $schema : null,
                reason: (string) ($parsed['reason'] ?? ''),
            );
        }

        if ($status === self::INVALID) {
            return $this->result(
                state: self::INVALID,
                sourceState: $sourceState,
                schema: is_string($schema) ? $schema : null,
                reason: $parsed['reason'] !== null ? (string) $parsed['reason'] : null,
            );
        }

        // UNSUPPORTED_FORMAT from the parser means one of two different things
        // to a reader: nothing is wired at all, or something is wired and
        // cannot read this. The source state distinguishes them.
        $state = $sourceState === 'not_configured' ? self::NOT_CONFIGURED : self::UNSUPPORTED_FORMAT;

        return $this->result(
            state: $state,
            sourceState: $sourceState,
            schema: is_string($schema) ? $schema : null,
            reason: $parsed['reason'] !== null ? (string) $parsed['reason'] : null,
        );
    }

    /**
     * This platform's own QR envelopes are JSON carrying a ticket_reference
     * and a fingerprint, which App\Services\Ticket\TicketQrVerificationService
     * owns. Detection only - no verification happens in this class.
     */
    private function looksLikeOperatorEnvelope(string $payload): bool
    {
        if (! str_starts_with($payload, '{')) {
            return false;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return false;
        }

        return array_key_exists('ticket_reference', $decoded)
            && array_key_exists('fingerprint', $decoded);
    }

    /**
     * @return array{state: string, product: string, number: string|null, draw_number: string|null, fixture: bool, official: bool, source_state: string|null, schema: string|null, reason: string|null}
     */
    private function result(
        string $state,
        string $product = TicketIdentityService::PRODUCT_UNKNOWN,
        ?string $number = null,
        ?string $drawNumber = null,
        bool $fixture = false,
        bool $official = false,
        ?string $sourceState = null,
        ?string $schema = null,
        ?string $reason = null,
    ): array {
        return [
            'state' => $state,
            'product' => $product,
            'number' => $number,
            'draw_number' => $drawNumber,
            'fixture' => $fixture,
            // Nothing in this project is an authorised official GLO reader,
            // so this flag is false everywhere until one genuinely exists.
            'official' => $official && false,
            'source_state' => $sourceState,
            'schema' => $schema,
            'reason' => $reason === '' ? null : $reason,
        ];
    }
}
