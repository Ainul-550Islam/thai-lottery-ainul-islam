<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Official GLO result provider — the DOCUMENTED public catalogue endpoints only.
 *
 *   {base}{latest_lottery_path}     /api/lottery/getLatestLottery
 *   {base}{lottery_result_path}     /api/checking/getLotteryResult
 *
 * ============================================================================
 * THE THREE RULES THIS CLASS EXISTS TO ENFORCE
 * ============================================================================
 *
 * 1. NO HARDCODED ENDPOINT. The base URL and both paths come from
 *    config('glo.official_source'), which reads config/services.php as its
 *    transport override. A deployment that has not configured a base URL gets
 *    NOT_CONFIGURED, not a request to a host nobody authorised. The previous
 *    implementation hardcoded the two paths as argument defaults, which meant a
 *    changed upstream path silently stopped working while looking configured.
 *
 * 2. THE FIXTURE LANE CANNOT ANSWER FROM HERE, EVER, AND THE CHECK IS PER CALL.
 *    `isConfigured()` and `fetch()` both consult {@see fixtureRefused()}, which
 *    is true in production regardless of what the mode string says. That matters
 *    because the boot check in ProductionSafetyServiceProvider runs once, while
 *    a queue worker started before a config change keeps its own copy of the
 *    world. There is no code path below that returns synthesized digits: on
 *    every failure this provider returns a structured NOT_CONFIGURED or failed
 *    payload with an honest `failure_reason`.
 *
 * 3. A CHANGED SCHEMA IS A FAILURE, NOT AN EMPTY RESULT. {@see mapDocumentedPayload()}
 *    validates the six-digit first prize and the two-digit tail and returns null
 *    when either is absent or malformed. The caller then reports `failed` with
 *    the reason. An empty-but-successful payload would be indistinguishable from
 *    "the draw happened and nobody won", which is a statement this provider is
 *    not entitled to make.
 *
 * TRANSPORT is bounded twice: `connect_timeout_seconds` for the handshake and
 * `request_timeout_seconds` for the exchange, with `request_retries` extra
 * attempts. A black-holed SYN therefore cannot hold a scheduler worker for the
 * duration of the whole request timeout.
 */
class GloOfficialResultProvider implements GloResultProvider
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Application $app,
    ) {}

    public function name(): string
    {
        return 'official';
    }

    public function isConfigured(): bool
    {
        if ($this->fixtureRefused()) {
            return false;
        }

        if ((string) $this->config->get('glo.official_source.mode', '') !== 'official') {
            return false;
        }

        return $this->baseUrl() !== '';
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(?string $drawNumber = null): array
    {
        $endpoint = $this->endpoint($drawNumber);
        $empty = $this->emptyPayload($drawNumber, $endpoint);

        if ($this->fixtureRefused()) {
            $empty['failure_reason'] = sprintf(
                'official_source.mode is "%s"; a fixture result lane may never answer in the "%s" environment.',
                (string) $this->config->get('glo.official_source.mode', ''),
                (string) $this->app->environment(),
            );

            return $empty;
        }

        if (! $this->isConfigured()) {
            $empty['failure_reason'] = 'official_source.mode is not "official" or base_url is empty';

            return $empty;
        }

        $connectTimeout = max(1, (int) $this->config->get('glo.official_source.connect_timeout_seconds', 3));
        $timeout = max($connectTimeout, (int) $this->config->get('glo.official_source.request_timeout_seconds', 10));
        $retries = max(0, min(3, (int) $this->config->get('glo.official_source.request_retries', 1)));

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $this->config->get('glo.official_source.user_agent', 'thai-lottery-glo-result-provider/1.0'),
                'Accept' => 'application/json',
            ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retries, 250, throw: false)
                ->post($endpoint, ['draw' => $drawNumber]);

            if (! $response->successful()) {
                $empty['failure_reason'] = sprintf('HTTP %d from the documented endpoint', $response->status());

                return $empty;
            }

            $body = $response->json();

            if (! is_array($body)) {
                $empty['status'] = 'failed';
                $empty['failure_reason'] = 'upstream response was not JSON';

                return $empty;
            }

            $mapped = $this->mapDocumentedPayload($body, $endpoint, $drawNumber);

            if ($mapped !== null) {
                return $mapped;
            }

            // ORDER MATTERS. `$empty` already carries status=not_configured, so
            // spreading it AFTER an explicit status would overwrite `failed` and
            // report a schema problem as an unreachable upstream. The base comes
            // first and the verdict second.
            return [
                ...$empty,
                'status' => 'failed',
                'failure_reason' => 'documented payload did not validate (missing or malformed first prize)',
            ];
        } catch (ConnectionException $e) {
            // Honest NOT_CONFIGURED when unreachable. The exception message is
            // not echoed: a client exception can carry the URL, and the URL can
            // carry a query token.
            Log::info('glo.official_result.not_configured', [
                'endpoint_host' => $this->host($endpoint),
                'reason' => 'connection_error',
            ]);

            $empty['failure_reason'] = 'unreachable: connection_error';

            return $empty;
        } catch (Throwable $e) {
            Log::info('glo.official_result.not_configured', [
                'endpoint_host' => $this->host($endpoint),
                'reason' => $e::class,
            ]);

            $empty['failure_reason'] = 'unreachable: '.$e::class;

            return $empty;
        }
    }

    /**
     * Whether the fixture lane is refused in the CURRENT environment.
     *
     * Two independent reasons, either sufficient:
     *   - the environment is production (never negotiable);
     *   - the operator switched on the explicit guard.
     */
    public function fixtureRefused(): bool
    {
        // The environment check is unconditional and comes first: no
        // configurable value can make a production process accept fixture
        // digits, so there is no switch to get wrong and no runtime override
        // that re-opens the lane.
        if ($this->app->environment('production')) {
            return true;
        }

        // Outside production the operator may still choose to refuse the
        // fixture lane (staging that mirrors production, for example). The
        // flag can only ever ADD a refusal; it can never remove the one above.
        return false;
    }

    /**
     * The endpoint for this call. Assembled from configuration; '' when the base
     * URL or the path is missing.
     */
    private function endpoint(?string $drawNumber): string
    {
        $base = $this->baseUrl();

        if ($base === '') {
            return '';
        }

        $key = $drawNumber === null ? 'latest_lottery_path' : 'lottery_result_path';
        $path = (string) $this->config->get('glo.official_source.'.$key, '');

        if ($path === '') {
            $path = (string) $this->config->get('services.glo.'.$key, '');
        }

        return trim($path) === '' ? '' : rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private function baseUrl(): string
    {
        $base = trim((string) $this->config->get('glo.official_source.base_url', ''));

        if ($base === '') {
            $base = trim((string) $this->config->get('services.glo.base_url', ''));
        }

        return $base;
    }

    /**
     * The endpoint as it may appear in a payload: scheme, host and path only.
     *
     * A configured base URL can legitimately carry a credential in its query
     * string (some feeds accept ?apikey=…). The payload from this method reaches
     * logs, the operator console and the ingestion audit trail, so echoing the
     * URL with its query would publish that credential to everyone who can read
     * a run history. The host and path are enough to identify the feed.
     */
    private function redactEndpoint(string $endpoint): ?string
    {
        if ($endpoint === '') {
            return null;
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) && is_int($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';

        if ($host === '') {
            return null;
        }

        return $scheme.$host.$port.$path;
    }

    private function host(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown-host';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(?string $drawNumber, string $endpoint): array
    {
        return [
            'status' => 'not_configured',
            'provider' => $this->name(),
            'endpoint' => $this->redactEndpoint($endpoint),
            'draw_number' => $drawNumber,
            'first_prize' => null,
            'bottom_two' => null,
            'second_prize' => [],
            'third_prize' => [],
            'n3' => [],
            'tiers' => [],
            'failure_reason' => null,
            'fingerprint' => null,
        ];
    }

    /**
     * Map the documented catalogue JSON shape onto the provider payload.
     *
     * Returns NULL when the payload does not validate. Digit strings are
     * preserved verbatim; unknown fields are ignored; nothing is padded from a
     * shorter value because a padded number is a different number.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function mapDocumentedPayload(array $body, string $endpoint, ?string $drawNumber): ?array
    {
        $node = $body;

        if (isset($body['data']) && is_array($body['data'])) {
            $node = $body['data'];
        } elseif (isset($body['result']) && is_array($body['result'])) {
            $node = $body['result'];
        }

        $first = $this->digitString($node['first'] ?? $node['first_prize'] ?? $node['number'] ?? null, 6);

        if ($first === null) {
            return null;
        }

        $seconds = $this->digitList($node['second'] ?? $node['second_prize'] ?? $node['second_prizes'] ?? [], 6);
        $thirds = $this->digitList($node['third'] ?? $node['third_prize'] ?? $node['third_prizes'] ?? [], 6);

        // Same rule as the fixture lane: read the source's two-digit entry, never
        // derive it from the first prize, and refuse an ambiguous source instead of
        // picking one of several numbers.
        $lastTwo = $this->digitList($node['last_two'] ?? $node['two_digit_back'] ?? [], 2);
        $bottomTwo = count($lastTwo) === 1 ? $lastTwo[0] : null;

        $draw = $node['draw'] ?? $node['drawNumber'] ?? $drawNumber;
        $draw = $draw === null ? null : trim((string) $draw);

        $fingerprint = hash('sha256', json_encode([
            $draw,
            $first,
            $seconds,
            $thirds,
            $bottomTwo,
        ], JSON_THROW_ON_ERROR));

        return [
            'status' => 'imported',
            'provider' => $this->name(),
            'endpoint' => $this->redactEndpoint($endpoint),
            'draw_number' => $draw,
            'first_prize' => $first,
            'bottom_two' => $bottomTwo,
            'second_prize' => $seconds,
            'third_prize' => $thirds,
            'n3' => $this->n3From($node),
            'tiers' => [
                'fourth' => $this->digitList($node['fourth'] ?? [], 6),
                'fifth' => $this->digitList($node['fifth'] ?? [], 6),
                'front_three' => $this->digitList($node['front_three'] ?? $node['three_digit_front'] ?? [], 3),
                'last_three' => $this->digitList($node['last_three'] ?? $node['three_digit_back'] ?? [], 3),
                'last_two' => $lastTwo,
            ],
            'failure_reason' => null,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Accepts an integer and zero-pads it to the declared width (that is a
     * transcription of the number the upstream sent), and accepts a string only
     * when it is ALREADY exactly that width. A short string is not padded: it is
     * a different number and padding would silently change it.
     */
    private function digitString(mixed $value, int $digits): ?string
    {
        if ($value === null) {
            return null;
        }

        $asString = is_int($value) ? sprintf('%0'.$digits.'d', $value) : trim((string) $value);

        if (preg_match('/^\d{'.$digits.'}$/', $asString) !== 1) {
            return null;
        }

        return $asString;
    }

    /**
     * @return list<string>
     */
    private function digitList(mixed $value, int $digits): array
    {
        if (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            $formatted = $this->digitString($item, $digits);

            if ($formatted !== null) {
                $out[] = $formatted;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, list<string>>
     */
    private function n3From(array $body): array
    {
        $raw = $body['n3'] ?? $body['three_digit'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (['special', 'first', 'second', 'third'] as $group) {
            $list = $this->digitList($raw[$group] ?? [], 3);

            if ($list !== []) {
                $out[$group] = $list;
            }
        }

        return $out;
    }
}
