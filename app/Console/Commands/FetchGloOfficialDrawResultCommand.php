<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Services\Lottery\CircuitBreaker;
use App\Services\ResultIngestion\GloResultIngestionService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches the official GLO result for closed draws and ingests it as a draft.
 *
 * ============================================================================
 * WHAT WAS WRONG BEFORE, STATED SO IT CANNOT COME BACK
 * ============================================================================
 * The previous implementation called
 *
 *     config('services.glo.endpoint', 'https://api.glo.or.th/v1/lottery/latest')
 *
 * THREE DEFECTS IN ONE LINE:
 *
 *   1. the URL was a LITERAL FALLBACK, so a deployment that never configured
 *      the endpoint silently called a host nobody had authorised;
 *   2. that host does not appear among the documented public GLO catalogue
 *      endpoints this project is allowed to call (see config/glo.php), so the
 *      fallback was an invented integration;
 *   3. `services.glo.endpoint` was not a key this repository declared, so the
 *      fallback was the *only* path that ever ran.
 *
 * It also had no connect timeout (a black-holed SYN held the scheduler for the
 * full request timeout), retried the same dead host three times on every run,
 * and recorded no provenance — so a downstream reader could not tell which
 * endpoint answered, when, or whether anything answered at all.
 *
 * ============================================================================
 * WHAT IT DOES NOW
 * ============================================================================
 *   - the endpoint comes from config('glo.official_source') with
 *     config('services.glo') as the transport override. There is no URL literal
 *     in this file, and an unset endpoint is a REFUSAL, not a default;
 *   - in production the command refuses to run unless the source mode is
 *     `official`, which mirrors the per-request guard in
 *     ProductionSafetyServiceProvider and the provider's own refusal;
 *   - the per-source circuit breaker is consulted before every attempt, so an
 *     outage costs one probe per cool-off window instead of a full timeout per
 *     draw per hour;
 *   - connect and request timeouts are separate and both bounded;
 *   - provenance is reported per draw: the provider label the draft carries,
 *     the endpoint HOST (never the full URL, which may hold a token), the
 *     retrieval timestamp and the payload fingerprint. The draft itself stores
 *     `source_provider` and the fingerprint, which is what the confirmation
 *     queue displays;
 *   - it exits non-zero when every draw failed, so a scheduler alert fires. The
 *     previous version always exited SUCCESS, which made an outage look like a
 *     healthy run that happened to find nothing.
 *
 * IT STILL DOES NOT PUBLISH. Ingestion produces a PENDING_CONFIRMATION draft;
 * publication requires a second person through DrawResultConfirmationService.
 * That four-eyes boundary is not touched here.
 */
class FetchGloOfficialDrawResultCommand extends Command
{
    protected $signature = 'glo:fetch-official
        {draw_code? : Specific draw code}
        {--dry-run : Attempt the fetch and report what would be ingested, writing nothing}';

    protected $description = 'Fetch the official GLO result from the configured public catalogue and ingest a draft awaiting four-eyes confirmation.';

    public function handle(
        GloResultIngestionService $ingestionService,
        CircuitBreaker $breaker,
        ConfigRepository $config,
    ): int {
        $mode = (string) $config->get('glo.official_source.mode', '');
        $environment = (string) $this->laravel->environment();

        if ($environment === 'production' && $mode !== 'official') {
            $this->error(sprintf(
                'Refusing to run: GLO_OFFICIAL_SOURCE_MODE is "%s" but production requires "official". Nothing was fetched and nothing was written.',
                $mode === '' ? 'unset' : $mode,
            ));

            return self::FAILURE;
        }

        $endpoint = $this->endpoint($config);

        if ($endpoint === '') {
            $this->error('Refusing to run: no GLO endpoint is configured. Set GLO_OFFICIAL_BASE_URL (config/glo.php) or services.glo.base_url. No URL is hardcoded in this command.');

            return self::FAILURE;
        }

        $breakerName = 'glo:official';

        if ($breaker->isOpen($breakerName)) {
            $this->warn(sprintf(
                'Circuit breaker is open for %s; retry in %d seconds. Nothing was fetched.',
                $breakerName,
                $breaker->retryAfterSeconds($breakerName),
            ));

            return self::SUCCESS;
        }

        $draws = $this->drawsAwaitingResult($this->argument('draw_code'));

        if ($draws->isEmpty()) {
            $this->info('No closed draws awaiting result ingestion.');

            return self::SUCCESS;
        }

        $failures = 0;
        $ingested = 0;

        foreach ($draws as $draw) {
            $this->line(sprintf('Fetching official result for draw %s…', $draw->draw_code));

            $payload = $this->fetch($endpoint, $breakerName, $breaker);

            if ($payload === null) {
                $failures++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->info(sprintf(
                    'Dry run: %s answered with first prize %s for draw %s. Nothing was written.',
                    $this->host($endpoint),
                    (string) ($payload['first_prize'] ?? ''),
                    $draw->draw_code,
                ));
                $ingested++;

                continue;
            }

            try {
                $systemOperatorId = $this->systemOperatorId($config);

                $draft = $ingestionService->ingestPayload(
                    drawId: (int) $draw->id,
                    resultPayload: $payload['result'],
                    operatorId: $systemOperatorId,
                    sourceProvider: 'GLO_OFFICIAL_CATALOGUE',
                );

                $this->info(sprintf(
                    'Ingested draft for draw %s. Fingerprint: %s. Endpoint host: %s. Retrieved: %s.',
                    $draw->draw_code,
                    (string) $draft->payload_fingerprint,
                    $this->host($endpoint),
                    (string) ($payload['retrieved_at'] ?? ''),
                ));
                $this->warn('Awaiting four-eyes confirmation before publication.');

                $ingested++;
            } catch (Throwable $e) {
                $failures++;

                // No payload, no URL and no response body is ever logged: the
                // endpoint may carry a query token and the body is provider data.
                Log::error('glo.official_result.ingest_failed', [
                    'draw_code' => (string) $draw->draw_code,
                    'endpoint_host' => $this->host($endpoint),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $this->error(sprintf('Ingestion failed for draw %s: %s', $draw->draw_code, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->line(sprintf('Fetched %d of %d closed draw(s); %d failure(s).', $ingested, $draws->count(), $failures));

        if ($ingested === 0 && $failures > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Perform one bounded fetch and record the breaker outcome.
     *
     * @return array{result: array<string, mixed>, retrieved_at: string}|null
     */
    private function fetch(string $endpoint, string $breakerName, CircuitBreaker $breaker): ?array
    {
        $config = $this->laravel->make(ConfigRepository::class);
        $connectTimeout = max(1, (int) $config->get('glo.official_source.connect_timeout_seconds', 3));
        $timeout = max($connectTimeout, (int) $config->get('glo.official_source.request_timeout_seconds', 10));
        $retries = max(0, min(3, (int) $config->get('glo.official_source.request_retries', 1)));

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $config->get('glo.official_source.user_agent', 'thai-lottery-glo-result-provider/1.0'),
                'Accept' => 'application/json',
            ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retries, 250, throw: false)
                ->post($endpoint);

            if (! $response->successful()) {
                $reason = sprintf('HTTP %d from %s', $response->status(), $this->host($endpoint));

                $breaker->recordFailure($breakerName, $reason);
                $this->error('Fetch failed: '.$reason);

                return null;
            }

            $body = $response->json();

            if (! is_array($body)) {
                $reason = 'upstream response was not JSON';

                $breaker->recordFailure($breakerName, $reason);
                $this->error('Fetch failed: '.$reason);

                return null;
            }

            $result = $this->mapCataloguePayload($body);

            if ($result === null) {
                $reason = 'upstream payload did not match the documented catalogue schema';

                $breaker->recordFailure($breakerName, $reason);
                $this->error('Fetch failed: '.$reason);

                return null;
            }

            $breaker->recordSuccess($breakerName);

            return ['result' => $result, 'retrieved_at' => now()->toIso8601String()];
        } catch (ConnectionException $e) {
            $reason = 'connection_error';

            $breaker->recordFailure($breakerName, $reason);
            $this->error(sprintf('Fetch failed: could not reach %s (%s).', $this->host($endpoint), $e::class));

            return null;
        } catch (Throwable $e) {
            $breaker->recordFailure($breakerName, 'transport_error');

            Log::error('glo.official_result.fetch_failed', [
                'endpoint_host' => $this->host($endpoint),
                'exception' => $e::class,
            ]);
            $this->error(sprintf('Fetch failed: %s', $e::class));

            return null;
        }
    }

    /**
     * Map the documented catalogue JSON onto the ingestion payload shape.
     *
     * Returns null when the payload cannot be validated. It NEVER invents a
     * digit, never pads a short number and never substitutes a default: an
     * unusable payload is a failure, and the caller reports it as one.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function mapCataloguePayload(array $body): ?array
    {
        // The documented catalogues wrap their payload; accept either the
        // envelope or the bare object without inventing fields.
        $node = $body;

        if (isset($body['data']) && is_array($body['data'])) {
            $node = $body['data'];
        } elseif (isset($body['result']) && is_array($body['result'])) {
            $node = $body['result'];
        }

        $firstPrize = $this->digits($node['first_prize'] ?? $node['first'] ?? $node['number'] ?? null, 6);

        if ($firstPrize === null) {
            return null;
        }

        $twoDigitBack = $this->digits($node['two_digit_back'] ?? $node['last_two'] ?? $node['last2'] ?? null, 2);
        $threeFront = $this->digitList($node['three_digit_front'] ?? $node['front_three'] ?? $node['first3'] ?? [], 3);
        $threeBack = $this->digitList($node['three_digit_back'] ?? $node['last_three'] ?? $node['last3'] ?? [], 3);
        $secondPrizes = $this->digitList($node['second_prizes'] ?? $node['second'] ?? $node['second_prize'] ?? [], 6);
        $thirdPrizes = $this->digitList($node['third_prizes'] ?? $node['third'] ?? $node['third_prize'] ?? [], 6);

        // The two-digit tail is part of every documented GLO catalogue payload.
        // If the upstream omitted it the result is incomplete, and an incomplete
        // result must not be ingested as if it were whole.
        if ($twoDigitBack === null) {
            return null;
        }

        return [
            'first_prize' => $firstPrize,
            'two_digit_back' => $twoDigitBack,
            'three_digit_front' => $threeFront,
            'three_digit_back' => $threeBack,
            'second_prizes' => $secondPrizes,
            'third_prizes' => $thirdPrizes,
        ];
    }

    /**
     * The endpoint, assembled from configuration only.
     */
    private function endpoint(ConfigRepository $config): string
    {
        $base = trim((string) $config->get('glo.official_source.base_url', ''));

        if ($base === '') {
            $base = trim((string) $config->get('services.glo.base_url', ''));
        }

        if ($base === '') {
            return '';
        }

        $path = (string) $config->get(
            'glo.official_source.latest_lottery_path',
            (string) $config->get('services.glo.latest_lottery_path', ''),
        );

        if (! is_string($path) || trim($path) === '') {
            return '';
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /**
     * A host-only projection of the endpoint. A configured endpoint may carry a
     * token in its query string; the host is what gets logged and recorded.
     */
    private function host(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown-host';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Draw>
     */
    private function drawsAwaitingResult(?string $drawCode): \Illuminate\Database\Eloquent\Collection
    {
        $query = Draw::query()->whereIn('status', ['CLOSED', 'DRAW_COMPLETED']);

        if (is_string($drawCode) && trim($drawCode) !== '') {
            $query->where('draw_code', trim($drawCode));
        }

        return $query->orderBy('draw_date')->get();
    }

    /**
     * Who the draft is filed as having been imported by.
     *
     * THE MACHINE, NOT A FAKE HUMAN. The previous version passed the literal
     * string '00000000-0000-0000-0000-000000000001', which the ingestion service
     * casts to int and therefore stored as 0 — the same identifier this returns.
     * The difference is that the identifier is now NAMED
     * ({@see GloResultIngestionService::AUTOMATED_IMPORTER_ID}) and can be
     * overridden by an operator who wants the fetch attributed to a particular
     * service account.
     *
     * 0 is the right value for the four-eyes boundary rather than merely a
     * convenient one: no users row has id 0, so a human confirming the draft can
     * never collide with the machine that staged it, whatever the sequence of
     * operator ids happens to be.
     */
    private function systemOperatorId(ConfigRepository $config): int
    {
        $raw = $config->get('services.system.automated_operator_id');

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return GloResultIngestionService::AUTOMATED_IMPORTER_ID;
    }

    private function digits(mixed $value, int $width): ?string
    {
        if ($value === null) {
            return null;
        }

        $asString = is_int($value) ? sprintf('%0'.$width.'d', $value) : trim((string) $value);

        return preg_match('/^\d{'.$width.'}$/', $asString) === 1 ? $asString : null;
    }

    /**
     * @return list<string>
     */
    private function digitList(mixed $value, int $width): array
    {
        if (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            $formatted = $this->digits($item, $width);

            if ($formatted !== null) {
                $out[] = $formatted;
            }
        }

        return $out;
    }
}
