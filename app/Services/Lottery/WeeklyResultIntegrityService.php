<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Laravel side of the Weekly result integrity boundary (PROMPT 6).
 *
 * WHAT THIS CLASS DOES
 * ---------------------------------------------------------------------------
 * 1. Builds the WKLY1 canonical byte string for a result, in PHP.
 * 2. Hashes it with SHA-256. That hash is the normalized_fingerprint stored on
 *    every version row.
 * 3. When the Rust verifier is configured and present, hands it the same field
 *    values and asks it to rebuild the bytes independently. If the two hashes
 *    disagree, the import is refused.
 *
 * WHY STEP 3 IS WORTH HAVING
 * ---------------------------------------------------------------------------
 * Not because Rust is fashionable. Canonicalization is byte-exact work, and
 * PHP is a language where '049' == 49 is true, where a JSON round-trip can
 * turn a numeric string into a number, and where an accidental (int) is one
 * character. A second implementation that shares none of those hazards will
 * notice if the PHP one ever starts producing different bytes for the same
 * result - including after a refactor that looks harmless.
 *
 * It is defence in depth. It is NOT a claim that anything is unhackable, and
 * this component holds no authority: it cannot authorise, publish or reject on
 * its own. It returns a report; WeeklyLotteryImportService decides.
 *
 * WHY IT DEGRADES INSTEAD OF EXPLODING
 * ---------------------------------------------------------------------------
 * A fresh clone has no compiled binary, and CI should not be blocked on a
 * cargo build. So when the binary is absent the report says
 * VERIFIER_UNAVAILABLE and the PHP fingerprint stands alone - which is exactly
 * what the previous wave did with no verifier at all, so nothing regresses.
 * Set weekly_lottery.integrity.required = true in an environment where the
 * binary IS deployed and its absence should be treated as a fault.
 *
 * A HASH IS NOT A SIGNATURE
 * ---------------------------------------------------------------------------
 * With no configured public key the best available answer is
 * INTEGRITY_HASH_ONLY. The status is stored and shown, so nobody reading a
 * provenance panel can mistake "these bytes are self-consistent" for "an
 * authorised party signed these numbers".
 */
class WeeklyResultIntegrityService
{
    /** Canonicalized and hashed; no signing key configured. */
    public const STATUS_HASH_ONLY = 'INTEGRITY_HASH_ONLY';

    /** A configured Ed25519 key verified a signature over the canonical bytes. */
    public const STATUS_SIGNED_VERIFIED = 'SIGNED_VERIFIED';

    /**
     * The only statuses the native protocol may report. An unknown status is
     * not "some new thing the verifier learned" - it is a payload we do not
     * understand, and we refuse payloads we do not understand.
     *
     * @var list<string>
     */
    public const CANONICAL_STATUSES = [
        self::STATUS_HASH_ONLY,
        self::STATUS_SIGNED_VERIFIED,
        self::STATUS_SIGNATURE_INVALID,
        self::STATUS_FINGERPRINT_MISMATCH,
        self::STATUS_VERIFIER_UNAVAILABLE,
        self::STATUS_REJECTED,
    ];

    /** A signature was supplied and did not verify. */
    public const STATUS_SIGNATURE_INVALID = 'SIGNATURE_INVALID';

    /** PHP and the native verifier disagree about the canonical hash. */
    public const STATUS_FINGERPRINT_MISMATCH = 'FINGERPRINT_MISMATCH';

    /** The native verifier is not installed; the PHP fingerprint stands alone. */
    public const STATUS_VERIFIER_UNAVAILABLE = 'VERIFIER_UNAVAILABLE';

    /** The payload cannot be canonicalized at all. */
    public const STATUS_REJECTED = 'REJECTED';

    /**
     * The canonical field order. This list IS the specification, and it is
     * duplicated - deliberately and identically - in
     * security/weekly-result-integrity/src/canonical.rs. The duplication is
     * the point: two independent implementations that must agree.
     *
     * @var list<string>
     */
    private const FIELD_ORDER = [
        'draw_reference',
        'draw_date',
        'first_6',
        'three_ball',
        'two_ball',
        'source_identifier',
        'parser_version',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Build the WKLY1 canonical byte string.
     *
     * FORMAT (must match canonical.rs byte for byte):
     *
     *   WKLY1\n
     *   <field count>\n
     *   <name length>:<name>=<tag><value length>:<value>\n   (in FIELD_ORDER)
     *
     * Length prefixes make the encoding injective: without them a value
     * containing a separator could impersonate the start of the next field and
     * two different results could hash the same. Tag 'S' is a present string,
     * 'N' is an explicit absence - so "no numbers published" never hashes the
     * same as "the empty string", which is the difference between an honest
     * gap and corrupt data.
     *
     * Values are concatenated as strings. Nothing here casts, pads or formats
     * a number, so '049' contributes exactly three bytes.
     *
     * @param  array<string, string|null>  $fields
     */
    public function canonicalBytes(array $fields): string
    {
        $out = "WKLY1\n".count(self::FIELD_ORDER)."\n";

        foreach (self::FIELD_ORDER as $name) {
            $value = $fields[$name] ?? null;

            $out .= strlen($name).':'.$name.'=';

            if ($value === null) {
                $out .= "N0:\n";

                continue;
            }

            $value = (string) $value;
            $out .= 'S'.strlen($value).':'.$value."\n";
        }

        return $out;
    }

    /**
     * Lowercase hex SHA-256 of the canonical bytes.
     *
     * @param  array<string, string|null>  $fields
     */
    public function canonicalFingerprint(array $fields): string
    {
        return hash('sha256', $this->canonicalBytes($fields));
    }

    public function canonicalVersion(): string
    {
        $version = $this->config->get('weekly_lottery.integrity.canonical_version');

        return is_string($version) && $version !== '' ? $version : 'WKLY1';
    }

    /**
     * Ask the native verifier for a second opinion.
     *
     * Returns a report the import service can act on. Never throws: a verifier
     * that crashes the import it was meant to protect is a worse outcome than
     * one that reports it could not run.
     *
     * @param  array<string, string|null>  $fields
     * @return array{
     *     status: string,
     *     acceptable: bool,
     *     fingerprint: string,
     *     canonical_version: string,
     *     native: bool,
     *     error_code: string|null
     * }
     */
    public function verify(array $fields, ?string $signatureHex = null): array
    {
        $fingerprint = $this->canonicalFingerprint($fields);

        $report = [
            'status' => self::STATUS_HASH_ONLY,
            'acceptable' => true,
            'fingerprint' => $fingerprint,
            'canonical_version' => $this->canonicalVersion(),
            'native' => false,
            'error_code' => null,
        ];

        $publicKey = $this->config->get('weekly_lottery.integrity.public_key_hex');
        $signatureRequired = is_string($publicKey) && trim($publicKey) !== '';
        $signatureSupplied = is_string($signatureHex) && trim($signatureHex) !== '';
        $hardStop = (bool) $this->config->get('weekly_lottery.integrity.required', false);

        // Turning the verifier off only ever turns off the *second opinion* on
        // an unsigned payload. A deployment that demands a signature, or that
        // marks the verifier required, cannot reach "acceptable" through this
        // flag - otherwise the flag would be a bypass switch for the exact
        // money path it exists to protect.
        if (! (bool) $this->config->get('weekly_lottery.integrity.enabled', true)) {
            if ($hardStop || $signatureRequired || $signatureSupplied) {
                $report['acceptable'] = false;
                $report['status'] = $signatureRequired || $signatureSupplied
                    ? self::STATUS_SIGNATURE_INVALID
                    : self::STATUS_VERIFIER_UNAVAILABLE;
                $report['error_code'] = 'VERIFIER_DISABLED';
            }

            return $report;
        }

        $binary = $this->binaryPath();

        if ($binary === null) {
            // Honest about what did not happen. 'required' decides whether
            // that is merely noted or is a hard stop.
            $report['status'] = self::STATUS_VERIFIER_UNAVAILABLE;
            $report['acceptable'] = ! $hardStop && ! $signatureRequired && ! $signatureSupplied;
            $report['error_code'] = 'VERIFIER_BINARY_NOT_FOUND';

            return $report;
        }

        $request = [
            'draw_reference' => (string) ($fields['draw_reference'] ?? ''),
            'draw_date' => (string) ($fields['draw_date'] ?? ''),
            'first_6' => $fields['first_6'] ?? null,
            'three_ball' => $fields['three_ball'] ?? null,
            'two_ball' => $fields['two_ball'] ?? null,
            'source_identifier' => $fields['source_identifier'] ?? null,
            'parser_version' => (string) ($fields['parser_version'] ?? ''),
            // The PHP hash is sent as the EXPECTED value, so the native side
            // is comparing against us rather than being told the answer.
            'expected_fingerprint' => $fingerprint,
        ];

        $publicKey = $this->config->get('weekly_lottery.integrity.public_key_hex');

        if (is_string($publicKey) && $publicKey !== '' && is_string($signatureHex) && $signatureHex !== '') {
            $request['public_key_hex'] = $publicKey;
            $request['signature_hex'] = $signatureHex;
        }

        $encoded = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            $report['status'] = self::STATUS_REJECTED;
            $report['acceptable'] = false;
            $report['error_code'] = 'REQUEST_ENCODING_FAILED';

            return $report;
        }

        try {
            // No shell. An argument array, a fixed binary path from config,
            // and the payload on stdin - so nothing the provider sent can ever
            // be interpreted as a command.
            $process = new Process([$binary]);
            $process->setInput($encoded);
            $process->setTimeout((float) $this->timeoutSeconds());
            $process->run();

            $output = trim($process->getOutput());
            $decoded = $output === '' ? null : json_decode($output, true);

            if (! is_array($decoded) || ! isset($decoded['status']) || ! is_string($decoded['status'])) {
                // An installed verifier that answers nothing we can read has
                // not vouched for anything. That is a refusal, not a reason to
                // fall back to the PHP hash quietly.
                $report['status'] = self::STATUS_VERIFIER_UNAVAILABLE;
                $report['acceptable'] = false;
                $report['error_code'] = 'VERIFIER_OUTPUT_UNREADABLE';

                return $report;
            }

            $report['native'] = true;

            return $this->applyNativeReply(
                report: $report,
                decoded: $decoded,
                fields: $fields,
                phpFingerprint: $fingerprint,
                exitCode: $process->getExitCode(),
                signatureRequired: $signatureRequired,
                signatureSupplied: $signatureSupplied,
            );
            return $report;
        } catch (ProcessException|Throwable $exception) {
            // Report the failure, never the exception text: a process error
            // can contain a filesystem path.
            report($exception);

            $report['status'] = self::STATUS_VERIFIER_UNAVAILABLE;
            $report['acceptable'] = ! (bool) $this->config->get('weekly_lottery.integrity.required', false);
            $report['error_code'] = 'VERIFIER_EXECUTION_FAILED';

            return $report;
        }
    }

    /**
     * Turn a native verifier reply into a decision, and only ever into a
     * *narrower* decision than the PHP hash alone would have produced.
     *
     * The verifier is an external process whose stdout is untrusted input. Every
     * field it sends is therefore validated positively: the status must be one
     * we know, `acceptable` must be a real boolean, the fingerprint and version
     * must match what this process computed itself, and the payload length must
     * be the length of the bytes we canonicalized. Anything unrecognised,
     * missing, or self-contradictory is a refusal - never a default.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $decoded
     * @param  array<string, string|null>  $fields
     * @return array<string, mixed>
     */
    private function applyNativeReply(
        array $report,
        array $decoded,
        array $fields,
        string $phpFingerprint,
        ?int $exitCode,
        bool $signatureRequired,
        bool $signatureSupplied,
    ): array {
        // A nonzero exit means the verifier is telling us it did not complete.
        // Whatever it printed on the way out is not a verdict.
        if ($exitCode !== 0) {
            return $this->refuse($report, self::STATUS_REJECTED, 'VERIFIER_NONZERO_EXIT');
        }

        $status = $decoded['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::CANONICAL_STATUSES, true)) {
            return $this->refuse($report, self::STATUS_REJECTED, 'UNKNOWN_STATUS');
        }

        // "false" (string) and 1 (int) are the two shapes a sloppy verifier
        // emits; both would be truthy or falsy in the wrong direction.
        $acceptable = $decoded['acceptable'] ?? null;

        if (! is_bool($acceptable)) {
            return $this->refuse($report, self::STATUS_REJECTED, 'NON_BOOLEAN_ACCEPTABLE');
        }

        $acceptingStatus = in_array($status, [self::STATUS_HASH_ONLY, self::STATUS_SIGNED_VERIFIED], true);

        if ($acceptable !== $acceptingStatus) {
            return $this->refuse($report, self::STATUS_REJECTED, 'STATUS_ACCEPTABLE_DISAGREE');
        }

        // The fingerprint is a hash of bytes we produced. A verifier that
        // cannot reproduce it has not verified our payload.
        $claimed = $decoded['fingerprint'] ?? null;

        if (! is_string($claimed) || ! preg_match('/^[0-9a-f]{64}$/', $claimed) || $claimed !== $phpFingerprint) {
            return $this->refuse($report, self::STATUS_FINGERPRINT_MISMATCH, 'FINGERPRINT_MISMATCH');
        }

        // A verifier answering under a different canonical version is
        // answering about different bytes; relabelling them is exactly the
        // downgrade this check exists to stop.
        $version = $decoded['canonical_version'] ?? null;

        if (! is_string($version) || $version === '' || $version !== $this->canonicalVersion()) {
            return $this->refuse($report, self::STATUS_REJECTED, 'CANONICAL_VERSION_MISMATCH');
        }

        $length = $decoded['canonical_length'] ?? null;

        if (! is_int($length) || $length !== strlen($this->canonicalBytes($fields))) {
            return $this->refuse($report, self::STATUS_REJECTED, 'CANONICAL_LENGTH_MISMATCH');
        }

        $errorCode = $decoded['error_code'] ?? null;
        $errorField = $decoded['error_field'] ?? null;

        if ($errorCode !== null && ! is_string($errorCode)) {
            return $this->refuse($report, self::STATUS_REJECTED, 'MALFORMED_ERROR_CODE');
        }

        if ($errorField !== null && ! is_string($errorField)) {
            return $this->refuse($report, self::STATUS_REJECTED, 'MALFORMED_ERROR_FIELD');
        }

        // An "acceptable" reply carrying an error is a contradiction: one of
        // the two statements is wrong and we cannot tell which.
        if ($acceptable && ($errorCode !== null || $errorField !== null)) {
            return $this->refuse($report, self::STATUS_REJECTED, 'ERROR_ON_ACCEPTED_REPLY');
        }

        // SIGNED_VERIFIED is an assertion that a signature verified against a
        // trusted key. With no key configured, or no signature requested, that
        // assertion is unearned and must not be accepted.
        if ($status === self::STATUS_SIGNED_VERIFIED && ! ($signatureRequired && $signatureSupplied)) {
            return $this->refuse($report, self::STATUS_SIGNATURE_INVALID, 'UNREQUESTED_SIGNATURE');
        }

        // A trusted key means this deployment requires a signature. A reply
        // that verified only a hash cannot satisfy that requirement, and
        // neither can a request that arrived with no signature to check.
        if ($signatureRequired || $signatureSupplied) {
            if (! ($signatureRequired && $signatureSupplied) || $status !== self::STATUS_SIGNED_VERIFIED) {
                return $this->refuse($report, self::STATUS_SIGNATURE_INVALID, 'SIGNATURE_NOT_VERIFIED');
            }
        }

        $report['status'] = $status;
        $report['acceptable'] = $acceptable;
        $report['error_code'] = $errorCode;
        $report['canonical_version'] = $version;

        return $report;
    }

    /**
     * A refusal keeps the report shape stable for callers and always clears
     * `acceptable`: nothing in this class may widen what an unverified payload
     * is allowed to do.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function refuse(array $report, string $status, string $errorCode): array
    {
        $report['status'] = $status;
        $report['acceptable'] = false;
        $report['error_code'] = $errorCode;

        return $report;
    }

    /**
     * Resolve the configured binary, or null when it is not usable.
     *
     * A relative path is resolved against the application base path so the
     * default in config works from any working directory.
     */
    public function binaryPath(): ?string
    {
        $configured = $this->config->get('weekly_lottery.integrity.binary');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $path = str_starts_with($configured, '/') ? $configured : base_path($configured);

        return is_file($path) && is_executable($path) ? $path : null;
    }

    public function isNativeVerifierAvailable(): bool
    {
        return $this->binaryPath() !== null;
    }

    private function timeoutSeconds(): int
    {
        $timeout = $this->config->get('weekly_lottery.integrity.timeout_seconds');

        return is_int($timeout) && $timeout > 0 ? min($timeout, 30) : 5;
    }
}
