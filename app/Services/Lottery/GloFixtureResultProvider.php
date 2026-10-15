<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Fixture result provider — replays official-shape JSON from
 * resources/glo/fixtures/ without any network call.
 *
 * Fixture files use the same documented catalog field aliases as the official
 * provider so import logic is provider-agnostic. Missing fixture → failed with
 * an honest reason (never a fabricated draw).
 */
class GloFixtureResultProvider implements GloResultProvider
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function name(): string
    {
        return 'fixture';
    }

    public function isConfigured(): bool
    {
        $dir = $this->fixtureDir();

        return $dir !== '' && is_dir($dir);
    }

    public function fetch(?string $drawNumber = null): array
    {
        $dir = $this->fixtureDir();
        $endpoint = $dir !== '' ? $dir.'/lottery_result.json' : null;

        $empty = [
            'status' => 'failed',
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
            $empty['status'] = 'not_configured';
            $empty['failure_reason'] = 'fixture directory missing: '.$dir;

            return $empty;
        }

        $path = $dir.'/lottery_result.json';

        if (! is_file($path)) {
            $empty['failure_reason'] = 'fixture missing: '.$path;

            return $empty;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            $empty['failure_reason'] = 'fixture unreadable: '.$path;

            return $empty;
        }

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            $empty['failure_reason'] = 'fixture is not valid JSON: '.$path;

            return $empty;
        }

        // Optional draw filter: fixture may carry multiple draws under "draws".
        if ($drawNumber !== null && isset($body['draws']) && is_array($body['draws'])) {
            $matched = null;

            foreach ($body['draws'] as $entry) {
                if (is_array($entry) && (string) ($entry['draw'] ?? $entry['draw_number'] ?? '') === $drawNumber) {
                    $matched = $entry;
                    break;
                }
            }

            if ($matched === null) {
                $empty['failure_reason'] = 'fixture has no draw '.$drawNumber;

                return $empty;
            }

            $body = $matched;
        } elseif ($drawNumber !== null) {
            $fixtureDraw = (string) ($body['draw'] ?? $body['draw_number'] ?? '');

            if ($fixtureDraw !== '' && $fixtureDraw !== $drawNumber) {
                $empty['failure_reason'] = sprintf(
                    'fixture draw %s does not match requested %s',
                    $fixtureDraw,
                    $drawNumber,
                );

                return $empty;
            }
        }

        return $this->mapFixture($body, $endpoint, $drawNumber);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mapFixture(array $body, ?string $endpoint, ?string $drawNumber): array
    {
        $first = $this->digitString($body['first'] ?? $body['first_prize'] ?? null, 6);

        $failed = [
            'status' => 'failed',
            'provider' => $this->name(),
            'endpoint' => $endpoint,
            'draw_number' => $drawNumber ?? (string) ($body['draw'] ?? $body['draw_number'] ?? ''),
            'first_prize' => null,
            'second_prize' => [],
            'third_prize' => [],
            'n3' => [],
            'tiers' => [],
            'failure_reason' => 'fixture missing first prize digit string',
            'fingerprint' => null,
        ];

        if ($first === null) {
            return $failed;
        }

        $seconds = $this->digitList($body['second'] ?? [], 6);
        $thirds = $this->digitList($body['third'] ?? [], 6);
        $draw = (string) ($body['draw'] ?? $body['draw_number'] ?? $drawNumber ?? '');

        $n3 = [];
        $rawN3 = $body['n3'] ?? [];

        if (is_array($rawN3)) {
            foreach (['special', 'first', 'second', 'third'] as $group) {
                $list = $this->digitList($rawN3[$group] ?? [], 3);

                if ($list !== []) {
                    $n3[$group] = $list;
                }
            }
        }

        $fingerprint = hash('sha256', json_encode([
            $draw,
            $first,
            $seconds,
            $thirds,
            $n3,
        ], JSON_THROW_ON_ERROR));

        return [
            'status' => 'imported',
            'provider' => $this->name(),
            'endpoint' => $endpoint,
            'draw_number' => $draw,
            'first_prize' => $first,
            'second_prize' => $seconds,
            'third_prize' => $thirds,
            'n3' => $n3,
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

    private function fixtureDir(): string
    {
        return rtrim((string) $this->config->get('glo.official_source.fixture_path', ''), '/');
    }

    private function digitString(mixed $value, int $digits): ?string
    {
        if ($value === null) {
            return null;
        }

        $asString = is_int($value) ? sprintf('%0'.$digits.'d', $value) : trim((string) $value);

        return preg_match('/^\d{'.$digits.'}$/', $asString) === 1 ? $asString : null;
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
}
