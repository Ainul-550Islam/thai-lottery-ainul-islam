<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Official GLO result provider — DOCUMENTED public endpoints only.
 *
 * Endpoints (config glo.official_source):
 *   {base}/api/lottery/getLatestLottery
 *   {base}/api/checking/getLotteryResult
 *
 * When mode is not 'official', or the HTTP attempt fails / times out, the
 * provider returns status=not_configured with an honest failure_reason.
 * It NEVER invents draw numbers or prizes.
 *
 * Note: the sandbox typically cannot reach glo.or.th; tests use the fixture
 * provider (or Http::fake). Live failure is reported, not papered over.
 */
class GloOfficialResultProvider implements GloResultProvider
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function name(): string
    {
        return 'official';
    }

    public function isConfigured(): bool
    {
        $mode = (string) $this->config->get('glo.official_source.mode', 'fixture');

        if ($mode !== 'official') {
            return false;
        }

        $base = rtrim((string) $this->config->get('glo.official_source.base_url', ''), '/');

        return $base !== '';
    }

    public function fetch(?string $drawNumber = null): array
    {
        $base = rtrim((string) $this->config->get('glo.official_source.base_url', ''), '/');
        $latestPath = (string) $this->config->get('glo.official_source.latest_lottery_path', '/api/lottery/getLatestLottery');
        $resultPath = (string) $this->config->get('glo.official_source.lottery_result_path', '/api/checking/getLotteryResult');
        $timeout = (int) $this->config->get('glo.official_source.request_timeout_seconds', 10);
        $endpoint = $drawNumber === null
            ? $base.$latestPath
            : $base.$resultPath;

        $empty = [
            'status' => 'not_configured',
            'provider' => $this->name(),
            'endpoint' => $endpoint,
            'draw_number' => $drawNumber,
            'first_prize' => null,
            'second_prize' => [],
            'third_prize' => [],
            'n3' => [],
            'tiers' => [],
            'failure_reason' => null,
            'fingerprint' => null,
        ];

        if (! $this->isConfigured()) {
            $empty['failure_reason'] = 'official_source.mode is not "official" or base_url empty';

            return $empty;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $this->config->get('glo.official_source.user_agent', 'thai-lottery-glo-result-provider/1.0'),
                'Accept' => 'application/json',
            ])->timeout($timeout)->post($endpoint, [
                'draw' => $drawNumber,
            ]);

            if (! $response->successful()) {
                $empty['status'] = 'not_configured';
                $empty['failure_reason'] = sprintf('HTTP %d from documented endpoint', $response->status());

                return $empty;
            }

            $body = $response->json();

            if (! is_array($body)) {
                $empty['status'] = 'failed';
                $empty['failure_reason'] = 'upstream response was not JSON';

                return $empty;
            }

            return $this->mapDocumentedPayload($body, $endpoint, $drawNumber);
        } catch (Throwable $e) {
            // Honest NOT_CONFIGURED when unreachable (sandbox / network policy).
            Log::info('glo.official_result.not_configured', [
                'endpoint' => $endpoint,
                'reason' => $e->getMessage(),
            ]);
            $empty['status'] = 'not_configured';
            $empty['failure_reason'] = 'unreachable: '.$e->getMessage();

            return $empty;
        }
    }

    /**
     * Map the documented catalog JSON shape onto the provider payload.
     * Digit strings preserved; unknown fields ignored.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mapDocumentedPayload(array $body, string $endpoint, ?string $drawNumber): array
    {
        // Documented fields vary slightly by endpoint; accept common aliases
        // without inventing data.
        $first = $this->digitString($body['first'] ?? $body['first_prize'] ?? $body['number'] ?? null, 6);
        $seconds = $this->digitList($body['second'] ?? $body['second_prize'] ?? [], 6);
        $thirds = $this->digitList($body['third'] ?? $body['third_prize'] ?? [], 6);
        $draw = isset($body['draw']) ? (string) $body['draw'] : (isset($body['drawNumber']) ? (string) $body['drawNumber'] : $drawNumber);

        if ($first === null) {
            return [
                'status' => 'failed',
                'provider' => $this->name(),
                'endpoint' => $endpoint,
                'draw_number' => $draw,
                'first_prize' => null,
                'second_prize' => [],
                'third_prize' => [],
                'n3' => [],
                'tiers' => [],
                'failure_reason' => 'documented payload missing first prize digit string',
                'fingerprint' => null,
            ];
        }

        $fingerprint = hash('sha256', json_encode([
            $draw,
            $first,
            $seconds,
            $thirds,
        ], JSON_THROW_ON_ERROR));

        return [
            'status' => 'imported',
            'provider' => $this->name(),
            'endpoint' => $endpoint,
            'draw_number' => $draw,
            'first_prize' => $first,
            'second_prize' => $seconds,
            'third_prize' => $thirds,
            'n3' => $this->n3From($body),
            'tiers' => [
                'fourth' => $this->digitList($body['fourth'] ?? [], 6),
                'fifth' => $this->digitList($body['fifth'] ?? [], 6),
                'front_three' => $this->digitList($body['front_three'] ?? [], 3),
                'last_three' => $this->digitList($body['last_three'] ?? [], 3),
                'last_two' => $this->digitList($body['last_two'] ?? [], 2),
            ],
            'failure_reason' => null,
            'fingerprint' => $fingerprint,
        ];
    }

    private function digitString(mixed $value, int $digits): ?string
    {
        if ($value === null) {
            return null;
        }

        $asString = is_int($value) ? sprintf('%0'. $digits .'d', $value) : trim((string) $value);

        if (! preg_match('/^\d{'.$digits.'}$/', $asString)) {
            return null;
        }

        return $asString;
    }

    /**
     * @return list<string>
     */
    private function digitList(mixed $value, int $digits): array
    {
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
