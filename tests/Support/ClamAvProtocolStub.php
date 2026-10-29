<?php

declare(strict_types=1);

// Disposable clamd-compatible INSTREAM stub for local tests. It never invokes
// the real daemon and records the exact bytes and framing it received.
[$script, $socketPath, $transcriptPath, $encodedReply, $clientCount] = $argv;
$reply = base64_decode($encodedReply, true);
if ($reply === false || ! ctype_digit($clientCount)) {
    exit(2);
}

$server = stream_socket_server('unix://'.$socketPath, $errno, $error);
if (! is_resource($server)) {
    fwrite(STDERR, "Cannot start test socket: $error ($errno)\n");
    exit(3);
}

$readExactly = static function ($stream, int $length): ?string {
    $value = '';
    while (strlen($value) < $length) {
        $part = fread($stream, $length - strlen($value));
        if ($part === false || $part === '') {
            return null;
        }
        $value .= $part;
    }

    return $value;
};

$transcript = [];
try {
    for ($i = 0; $i < (int) $clientCount; $i++) {
        $client = stream_socket_accept($server, 15);
        if (! is_resource($client)) {
            fwrite(STDERR, "No scanner connected.\n");
            exit(4);
        }
        stream_set_timeout($client, 5);
        $command = $readExactly($client, strlen("zINSTREAM\0"));
        $payload = '';
        $complete = false;
        while (true) {
            $prefix = $readExactly($client, 4);
            if ($prefix === null) {
                break;
            }
            $length = unpack('Nlength', $prefix)['length'];
            if ($length === 0) {
                $complete = true;
                break;
            }
            if ($length > 2_000_000) {
                fwrite(STDERR, "Unexpected chunk length.\n");
                exit(5);
            }
            $chunk = $readExactly($client, $length);
            if ($chunk === null) {
                break;
            }
            $payload .= $chunk;
        }
        $transcript[] = [
            'command' => base64_encode((string) $command),
            'complete' => $complete,
            'bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
        ];
        if ($complete) {
            // Optional test-only fault: swap the temporary upload after clamd
            // received it but before the clean reply reaches the application.
            // This simulates a post-scan/pre-storage change, deterministically.
            if (isset($argv[5], $argv[6])) {
                $replacement = base64_decode($argv[6], true);
                if ($replacement === false || file_put_contents($argv[5], $replacement) !== strlen($replacement)) {
                    fwrite(STDERR, "Cannot mutate the test upload.\n");
                    exit(6);
                }
            }
            fwrite($client, $reply);
        }
        fclose($client);
    }
    file_put_contents($transcriptPath, json_encode($transcript, JSON_THROW_ON_ERROR));
} finally {
    fclose($server);
    @unlink($socketPath);
}
