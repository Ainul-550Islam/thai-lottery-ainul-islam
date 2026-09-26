<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Exceptions\GloDealerException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * GLO-18 Data Matrix verification adapter.
 *
 * The proprietary GLO Data Matrix encoding is NOT publicly documented.
 * This adapter therefore:
 *  - validates a size/type envelope only
 *  - reports NOT_CONFIGURED / UNSUPPORTED_FORMAT honestly
 *  - optionally parses a controlled SYNTHETIC_FIXTURE_V1 envelope used in tests
 *  - NEVER fabricates a successful production decode of unknown encodings
 *
 * Interface seam: GloDataMatrixParserInterface allows a future authorized
 * schema implementation without changing callers.
 */
class GloDataMatrixParser implements GloDataMatrixParserInterface
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function mode(): string
    {
        return (string) $this->config->get('glo.result_experience.data_matrix.mode', 'not_configured');
    }

    /**
     * @return array{
     *     status: string,
     *     source_state: string,
     *     payload: array<string, mixed>|null,
     *     reason: string|null,
     *     schema: string|null
     * }
     */
    public function parse(string $raw): array
    {
        $maxBytes = (int) $this->config->get('glo.result_experience.data_matrix.max_payload_bytes', 4096);
        $schema = (string) $this->config->get('glo.result_experience.data_matrix.fixture_schema_version', 'SYNTHETIC_FIXTURE_V1');

        $empty = [
            'status' => 'UNSUPPORTED_FORMAT',
            'source_state' => GloSourceState::NotConfigured->value,
            'payload' => null,
            'reason' => null,
            'schema' => null,
        ];

        $trimmed = trim($raw);

        if ($trimmed === '') {
            $empty['reason'] = 'empty payload';

            return $empty;
        }

        if (strlen($trimmed) > $maxBytes) {
            $empty['reason'] = 'payload exceeds '.$maxBytes.' bytes';

            return $empty;
        }

        // Controlled fixture envelope: JSON with explicit schema marker.
        // This is SYNTHETIC — not the proprietary GLO encoding.
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);

            if (! is_array($decoded)) {
                $empty['reason'] = 'invalid JSON envelope';

                return $empty;
            }

            $claimed = (string) ($decoded['schema'] ?? '');

            if ($claimed === $schema) {
                $number = isset($decoded['ticket_number']) ? (string) $decoded['ticket_number'] : '';

                if (preg_match('/^\d{6}$/', $number) !== 1) {
                    $empty['status'] = 'INVALID';
                    $empty['reason'] = 'fixture ticket_number must be 6 digits';
                    $empty['source_state'] = GloSourceState::FixtureOnly->value;
                    $empty['schema'] = $schema;

                    return $empty;
                }

                return [
                    'status' => 'FIXTURE_PARSED',
                    'source_state' => GloSourceState::FixtureOnly->value,
                    'payload' => [
                        'schema' => $schema,
                        'ticket_number' => $number,
                        'draw_number' => isset($decoded['draw_number']) ? (string) $decoded['draw_number'] : null,
                        'synthetic' => true,
                    ],
                    'reason' => 'Parsed SYNTHETIC_FIXTURE envelope only — proprietary GLO Data Matrix encoding is NOT_CONFIGURED.',
                    'schema' => $schema,
                ];
            }

            $empty['reason'] = 'unknown JSON schema marker';
            $empty['source_state'] = GloSourceState::NotConfigured->value;

            return $empty;
        }

        // Binary/opaque payload without authorized schema → honest refusal.
        $mode = $this->mode();
        $empty['status'] = 'UNSUPPORTED_FORMAT';
        $empty['source_state'] = $mode === 'not_configured'
            ? GloSourceState::NotConfigured->value
            : GloSourceState::Unavailable->value;
        $empty['reason'] = 'Proprietary GLO Data Matrix encoding is not publicly documented; no authorized schema is configured.';

        return $empty;
    }

    /**
     * Convenience: throw when parse did not yield a usable ticket number.
     *
     * @return array<string, mixed>
     */
    public function parseOrFail(string $raw): array
    {
        $result = $this->parse($raw);

        if ($result['payload'] === null) {
            throw GloDealerException::dataMatrixUnsupported(
                (string) ($result['reason'] ?? $result['status'])
            );
        }

        return $result;
    }
}
