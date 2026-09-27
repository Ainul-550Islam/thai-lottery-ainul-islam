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

        if (! (bool) $this->config->get('weekly_lottery.integrity.enabled', true)) {
            return $report;
        }

        $binary = $this->binaryPath();

        if ($binary === null) {
            // Honest about what did not happen. 'required' decides whether
            // that is merely noted or is a hard stop.
            $report['status'] = self::STATUS_VERIFIER_UNAVAILABLE;
            $report['acceptable'] = ! (bool) $this->config->get('weekly_lottery.integrity.required', false);
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
                $report['status'] = self::STATUS_VERIFIER_UNAVAILABLE;
                $report['acceptable'] = ! (bool) $this->config->get('weekly_lottery.integrity.required', false);
                $report['error_code'] = 'VERIFIER_OUTPUT_UNREADABLE';

                return $report;
            }

            $report['native'] = true;
            $report['status'] = $decoded['status'];
            $report['acceptable'] = (bool) ($decoded['acceptable'] ?? false);
            $report['error_code'] = isset($decoded['error_code']) && is_string($decoded['error_code'])
                ? $decoded['error_code']
                : null;

            if (isset($decoded['canonical_version']) && is_string($decoded['canonical_version'])) {
                $report['canonical_version'] = $decoded['canonical_version'];
            }

            // The native fingerprint is authoritative for the comparison but
            // the stored value stays the PHP one - they are asserted equal
            // here, so if they ever differ the import stops rather than
            // silently preferring one implementation.
            if (isset($decoded['fingerprint']) && is_string($decoded['fingerprint'])
                && ! hash_equals($fingerprint, $decoded['fingerprint'])) {
                $report['status'] = self::STATUS_FINGERPRINT_MISMATCH;
                $report['acceptable'] = false;
            }

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
