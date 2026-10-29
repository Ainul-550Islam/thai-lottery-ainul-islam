<?php

declare(strict_types=1);

namespace App\Services\Uploads\Scanners;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\MalwareScanStatus;
use App\Exceptions\AntimalwareException;
use App\Services\Antimalware\ScannerInterface;
use RuntimeException;
use Throwable;

/**
 * ClamAV daemon client for the quarantine upload lane.
 *
 * Talks INSTREAM over TCP or a unix socket and returns a {@see MalwareScanResult}
 * for every outcome. It never throws: the caller ({@see \App\Services\Uploads\UploadScanService})
 * persists the verdict, and a thrown exception there would leave the scan record
 * on its previous state with the file still sitting in quarantine.
 *
 * ============================================================================
 * FAIL-CLOSED IS THE WHOLE CONTRACT
 * ============================================================================
 * There are exactly three verdicts and no fourth:
 *
 *   CLEAN     the daemon answered `stream: OK` for the bytes we sent;
 *   INFECTED  the daemon answered `stream: <signature> FOUND`;
 *   ERROR     anything else — no address, refused connection, read timeout,
 *             empty reply, an unrecognised reply line, a read or write
 *             failure, or a file larger than the configured ceiling.
 *
 * An ERROR is never upgraded to CLEAN anywhere in this file. That is the
 * property the whole upload gate rests on.
 *
 * ============================================================================
 * WHAT WAS WRONG BEFORE, STATED SO IT CANNOT COME BACK
 * ============================================================================
 *  1. THE REPLY WAS READ WITH fgets(). The protocol this file speaks is
 *     `zINSTREAM`, and the `z` prefix means clamd NUL-terminates its reply.
 *     fgets() waits for a NEWLINE. So every single scan sat on the socket
 *     until the stream timeout expired before it parsed anything, and on a
 *     daemon that never sends a newline the reply arrived only because the
 *     read timed out. The reply is now framed on the NUL byte that the
 *     protocol actually sends.
 *
 *  2. A SHORT WRITE WAS SILENTLY A SHORT SCAN. `fwrite()` on a socket may
 *     accept fewer bytes than it was given, and the old loop ignored the
 *     return value. The daemon then scanned a TRUNCATED file and answered
 *     `stream: OK` for it — a clean verdict on bytes it never saw. Writes are
 *     now completed in a loop and a zero-byte write is a transport failure.
 *
 *  3. AN EMPTY READ WAS TREATED AS END-OF-FILE. `fread()` returning '' does
 *     not mean EOF; it also means "nothing available right now". Breaking out
 *     of the loop there truncated the stream the same way (2) did. The loop
 *     now distinguishes a timed-out read from a genuine EOF.
 *
 *  4. `FOUND` WAS CHECKED AFTER `OK`. Correct today only because of an
 *     accident of suffix matching. Detection now wins: a signature is parsed
 *     first, and `clean` requires the remainder to equal `OK` exactly.
 *
 *  5. THE DOCBLOCK CLAIMED UNIX SOCKET SUPPORT THAT DID NOT EXIST. It called
 *     fsockopen($host, $port) and never looked at `antimalware.clamav.socket`,
 *     which is the key the rest of the project configures. A deployment on a
 *     unix socket therefore connected to 127.0.0.1:3310 and reported the
 *     daemon unreachable. The address is now resolved once, socket first.
 *
 *  6. getVersionInfo() INVENTED A VERSION. When the daemon said nothing it
 *     still reported `version => 'ClamAV 1.3'` next to `engine_active => true`
 *     — a signature database version nobody observed, shown on the admin
 *     security page. Silence is now reported as silence.
 *
 *  7. THE EXCEPTION MESSAGE WAS PUT IN THE VERDICT. A connect failure carried
 *     `[host:port]` into `threat_name`, which is written to the
 *     upload_scan_records row and to the log. The refusal now names the
 *     failure, never the internal address.
 *
 *  8. scanStream() DID NOT EXIST AT ALL, while UploadScanService called it on
 *     the quarantine-disk path. That path is the one every scanned upload
 *     takes, so the scan died with `Call to undefined method
 *     ClamAvScanner::scanStream()` and the malware gate never ran. It is
 *     implemented here and declared on the contract.
 */
class ClamAvScanner implements MalwareScanner, ScannerInterface
{
    /** The engine label persisted on every verdict. */
    public const ENGINE = 'ClamAV';

    /** Driver identifier used by the existing antimalware manager. */
    public const DRIVER = 'clamav';

    /** Longest signature name we will carry into a database column. */
    private const MAX_SIGNATURE_LENGTH = 120;

    /** Longest reply we will buffer from the daemon before calling it broken. */
    private const MAX_REPLY_BYTES = 4096;

    /** How much of an unrecognised reply is quoted back. */
    private const MAX_REPLY_HINT = 120;

    private string $socketAddress;

    private int $timeoutSeconds;

    private int $chunkBytes;

    private int $maxFileSizeBytes;

    public function __construct()
    {
        $this->socketAddress = $this->resolveSocketAddress();
        $this->timeoutSeconds = max(1, min(120, (int) config('antimalware.timeout_seconds', 15)));
        $this->chunkBytes = max(1024, min(1_048_576, (int) config('antimalware.clamav.chunk_bytes', 8192)));
        $this->maxFileSizeBytes = max(1, (int) config('antimalware.max_file_size_bytes', 10_485_760));
    }

    public function name(): string
    {
        return self::DRIVER;
    }

    /**
     * Adapt the existing quarantine verdict to the manager's fail-closed API.
     * Only an explicit CLEAN result is returned; infections and all scanner
     * errors are exceptions. The actual INSTREAM client remains this class.
     *
     * @return array{driver: string, verdict: string, signature: null, bytes: int, duration_ms: int, sha256: string}
     */
    public function scan(string $path): array
    {
        $result = $this->scanFile($path);
        $context = ['basename' => basename($path)];

        if ($result->status === MalwareScanStatus::INFECTED) {
            throw AntimalwareException::infected($result->threatName ?: 'unnamed_detection', $context + [
                'bytes' => $result->fileSizeBytes,
                'duration_ms' => (int) round($result->scanDurationMs),
            ]);
        }

        if ($result->status !== MalwareScanStatus::CLEAN) {
            $reason = (string) $result->threatName;
            if (str_starts_with($reason, 'FILE_TOO_LARGE')) {
                throw AntimalwareException::tooLarge($result->fileSizeBytes, $this->maxFileSizeBytes, $context);
            }
            if ($reason === 'FILE_NOT_FOUND' || $reason === 'FILE_NOT_READABLE') {
                throw AntimalwareException::unavailable('file_not_readable', $context);
            }

            // Keep addresses and arbitrary daemon replies out of exceptions.
            throw AntimalwareException::unavailable('scanner_error', $context);
        }

        return [
            'driver' => self::DRIVER,
            'verdict' => 'clean',
            'signature' => null,
            'bytes' => $result->fileSizeBytes,
            'duration_ms' => (int) round($result->scanDurationMs),
            // Hash of the very same chunks sent over INSTREAM, not a second
            // read of a path that could have changed after the daemon reply.
            'sha256' => $result->fileSha256,
        ];
    }

    /**
     * Whether an address is configured. Deliberately does NOT open a socket:
     * an availability probe that dials an internal host is a way to make the
     * application reach hosts it was never pointed at, and reachability is the
     * scan's job to report.
     */
    public function isAvailable(): bool
    {
        return $this->socketAddress !== '';
    }

    /**
     * {@inheritDoc}
     */
    public function scanFile(string $filePath, ?string $originalFilename = null): MalwareScanResult
    {
        $started = microtime(true);

        if (! is_file($filePath) || ! is_readable($filePath)) {
            return $this->refusal('FILE_NOT_FOUND', $started);
        }

        $size = (int) filesize($filePath);

        if ($size > $this->maxFileSizeBytes) {
            return $this->refusal(
                sprintf('FILE_TOO_LARGE: %d > %d', $size, $this->maxFileSizeBytes),
                $started,
                '',
                $size,
            );
        }

        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return $this->refusal('FILE_NOT_READABLE', $started, '', $size);
        }

        try {
            return $this->stream($handle, $originalFilename, $started);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Scan an already-open stream. The CALLER keeps ownership: this method
     * never closes the handle, because UploadScanService closes it in its own
     * finally block and a double fclose() on a Laravel readStream is a warning
     * that turns into a failed request.
     *
     * @param  resource  $stream
     */
    public function scanStream($stream, ?string $originalFilename = null): MalwareScanResult
    {
        $started = microtime(true);

        if (! is_resource($stream)) {
            return $this->refusal('STREAM_NOT_READABLE', $started);
        }

        return $this->stream($stream, $originalFilename, $started);
    }

    /**
     * Check the daemon answers at all.
     */
    public function ping(): bool
    {
        try {
            $socket = $this->connect();

            try {
                $this->write($socket, "zPING\0");
                $reply = $this->normalise($this->readReply($socket));

                return strcasecmp($reply, 'PONG') === 0;
            } finally {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Signature database version, as the daemon reports it.
     *
     * `engine_active` is true only when the daemon actually answered. There is
     * no fallback version string: a page that shows a database version has to
     * be showing one that was read, not one that was assumed.
     *
     * @return array{scanner: string, version: string, engine_active: bool}
     */
    public function getVersionInfo(): array
    {
        try {
            $socket = $this->connect();

            try {
                $this->write($socket, "zVERSION\0");
                $reply = $this->normalise($this->readReply($socket));
            } finally {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }

            if ($reply === '') {
                return ['scanner' => self::ENGINE, 'version' => 'UNKNOWN', 'engine_active' => false];
            }

            return [
                'scanner' => self::ENGINE,
                'version' => mb_substr($reply, 0, self::MAX_SIGNATURE_LENGTH),
                'engine_active' => true,
            ];
        } catch (Throwable) {
            return ['scanner' => self::ENGINE, 'version' => 'UNREACHABLE', 'engine_active' => false];
        }
    }

    /**
     * Stream one open handle to clamd and turn the reply into a verdict.
     *
     * @param  resource  $handle
     */
    private function stream($handle, ?string $originalFilename, float $started): MalwareScanResult
    {
        $hash = hash_init('sha256');
        $sent = 0;
        $socket = null;

        try {
            $socket = $this->connect();

            $this->write($socket, "zINSTREAM\0");

            while (! feof($handle)) {
                $chunk = @fread($handle, $this->chunkBytes);

                if ($chunk === false) {
                    return $this->refusal('FILE_READ_FAILED', $started, hash_final($hash), $sent);
                }

                if ($chunk === '') {
                    $meta = stream_get_meta_data($handle);

                    // A timed-out read is not an end of file. Scanning the
                    // prefix and calling it clean is the fail-open this whole
                    // class exists to prevent.
                    if (($meta['timed_out'] ?? false) === true) {
                        return $this->refusal('FILE_READ_TIMEOUT', $started, hash_final($hash), $sent);
                    }
                    if (! feof($handle)) {
                        return $this->refusal('FILE_READ_INCOMPLETE', $started, hash_final($hash), $sent);
                    }

                    break;
                }

                $sent += strlen($chunk);

                if ($sent > $this->maxFileSizeBytes) {
                    // The file grew while we were reading it, or the caller
                    // handed us a stream with no declared size. Refuse rather
                    // than let the daemon's own StreamMaxLength reject it
                    // mid-stream, where the failure is indistinguishable from a
                    // transport error.
                    return $this->refusal(
                        sprintf('FILE_TOO_LARGE: %d > %d', $sent, $this->maxFileSizeBytes),
                        $started,
                        hash_final($hash),
                        $sent,
                    );
                }

                hash_update($hash, $chunk);

                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }

            // Zero-length chunk terminates the stream.
            $this->write($socket, pack('N', 0));

            $reply = $this->readReply($socket);
            $sha256 = hash_final($hash);
        } catch (Throwable $e) {
            // The message is NOT interpolated: a connect failure carries the
            // host and port, and this verdict is written to a database column
            // and to the log.
            return $this->refusal(
                'SCANNER_OUTAGE: '.$this->safeToken($e::class),
                $started,
                '',
                $sent,
            );
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        return $this->interpret($reply, $sha256, $sent, $started, $originalFilename);
    }

    /**
     * Map the daemon's reply line to a verdict.
     *
     * Detection is parsed FIRST. `clean` is an equality check against `OK`, not
     * a suffix match, so no signature name can ever be read as a pass.
     */
    private function interpret(
        string $reply,
        string $sha256,
        int $bytes,
        float $started,
        ?string $originalFilename,
    ): MalwareScanResult {
        $body = $this->normalise($reply);

        if ($body === '') {
            return $this->refusal('SCANNER_OUTAGE: empty_reply', $started, $sha256, $bytes);
        }

        // "stream: OK" / "stream: Eicar-Test-Signature FOUND"
        if (stripos($body, 'stream:') === 0) {
            $body = trim(substr($body, 7));
        }

        if (preg_match('/^(.*?)\s+FOUND$/i', $body, $matches) === 1) {
            $signature = trim((string) $matches[1]);

            if ($signature === '') {
                $signature = 'UNNAMED_DETECTION';
            }

            return new MalwareScanResult(
                status: MalwareScanStatus::INFECTED,
                threatName: $this->safeToken($signature),
                scannerEngine: self::ENGINE,
                fileSha256: $sha256,
                fileSizeBytes: $bytes,
                scanDurationMs: round((microtime(true) - $started) * 1000, 2),
            );
        }

        if (strcasecmp($body, 'OK') === 0) {
            return new MalwareScanResult(
                status: MalwareScanStatus::CLEAN,
                threatName: null,
                scannerEngine: self::ENGINE,
                fileSha256: $sha256,
                fileSizeBytes: $bytes,
                scanDurationMs: round((microtime(true) - $started) * 1000, 2),
            );
        }

        return $this->refusal(
            'UNKNOWN_SCANNER_RESPONSE: '.$this->safeToken($body),
            $started,
            $sha256,
            $bytes,
        );
    }

    /**
     * The one constructor for an ERROR verdict, so no call site can forget the
     * duration or invent a status.
     */
    private function refusal(
        string $code,
        float $started,
        string $sha256 = '',
        int $bytes = 0,
    ): MalwareScanResult {
        return new MalwareScanResult(
            status: MalwareScanStatus::ERROR,
            threatName: $this->safeToken($code),
            scannerEngine: self::ENGINE,
            fileSha256: $sha256,
            fileSizeBytes: $bytes,
            scanDurationMs: round((microtime(true) - $started) * 1000, 2),
        );
    }

    /**
     * Open the configured connection.
     *
     * @return resource
     */
    private function connect()
    {
        if ($this->socketAddress === '') {
            throw new RuntimeException('clamav_address_not_configured');
        }

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $this->socketAddress,
            $errno,
            $errstr,
            (float) $this->timeoutSeconds,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('connect_failed errno=%d', $errno));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        stream_set_blocking($socket, true);

        return $socket;
    }

    /**
     * Write every byte or fail. A socket write is allowed to be short.
     *
     * @param  resource  $socket
     */
    private function write($socket, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = @fwrite($socket, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('write_failed');
            }

            $offset += $written;
        }
    }

    /**
     * Read one reply, framed on the terminator the `z` protocol actually sends.
     *
     * @param  resource  $socket
     */
    private function readReply($socket): string
    {
        $reply = '';

        while (! feof($socket)) {
            $piece = @fread($socket, 512);

            if ($piece === false) {
                throw new RuntimeException('read_failed');
            }

            if ($piece === '') {
                $meta = stream_get_meta_data($socket);

                if (($meta['timed_out'] ?? false) === true) {
                    throw new RuntimeException('read_timeout');
                }

                break;
            }

            $reply .= $piece;

            if (strlen($reply) > self::MAX_REPLY_BYTES) {
                throw new RuntimeException('reply_too_long');
            }

            if (str_contains($reply, "\0") || str_contains($reply, "\n")) {
                break;
            }
        }

        return $reply;
    }

    /**
     * `antimalware.clamav.socket` wins when set (a unix socket path or a full
     * URI); otherwise host:port is composed from the two scalar keys. An empty
     * string means "no address", which makes every scan refuse rather than dial
     * a default host nobody configured.
     */
    private function resolveSocketAddress(): string
    {
        $socket = trim((string) config('antimalware.clamav.socket', ''));

        if ($socket !== '') {
            if (str_contains($socket, '://')) {
                return $socket;
            }

            return str_starts_with($socket, '/') ? 'unix://'.$socket : 'tcp://'.$socket;
        }

        $host = trim((string) config('antimalware.clamav.host', ''));
        $port = (int) config('antimalware.clamav.port', 0);

        if ($host === '' || $port <= 0 || $port > 65535) {
            return '';
        }

        return 'tcp://'.$host.':'.$port;
    }

    /**
     * Strip the protocol terminator and surrounding whitespace.
     */
    private function normalise(string $reply): string
    {
        return trim(str_replace("\0", '', $reply));
    }

    /**
     * Make a daemon-supplied string safe for a database column and a log line.
     */
    private function safeToken(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';

        return mb_substr(trim($clean), 0, self::MAX_SIGNATURE_LENGTH);
    }
}
