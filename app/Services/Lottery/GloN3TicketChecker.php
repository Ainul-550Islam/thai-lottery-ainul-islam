<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * N3 (3-digit) official ticket checker.
 *
 * Reads recorded N3 numbers from the draw result's GLO metadata lane
 * (config glo.tiers.metadata_key → n3_wins / n3_numbers) and matches a
 * digit-string ticket with exact string equality. Leading zeros are
 * preserved; values are never integer-cast.
 *
 * Pure reader — no write, no money movement.
 */
class GloN3TicketChecker
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GloResultService $results,
        private readonly GloN3PrizeCalculator $calculator,
    ) {}

    /**
     * @return array{
     *     draw_id: int,
     *     ticket_number: string,
     *     won: bool,
     *     groups: list<string>,
     *     matches: list<array{group: string, number: string}>,
     *     has_n3_result: bool
     * }
     */
    public function check(int $drawId, string $ticketNumber): array
    {
        if (! preg_match('/^\d{3}$/', $ticketNumber)) {
            throw new InvalidArgumentException('N3 ticket_number must be exactly 3 digits');
        }

        $recorded = $this->recordedNumbers($drawId);
        $matches = [];

        foreach ($recorded as $group => $numbers) {
            if ($this->calculator->matches($ticketNumber, $numbers)) {
                $matches[] = [
                    'group' => $group,
                    'number' => $ticketNumber,
                ];
            }
        }

        return [
            'draw_id' => $drawId,
            'ticket_number' => $ticketNumber,
            'won' => $matches !== [],
            'groups' => array_column($matches, 'group'),
            'matches' => $matches,
            'has_n3_result' => $recorded !== [],
        ];
    }

    /**
     * Recorded N3 numbers keyed by group. Empty array = not recorded.
     *
     * @return array<string, list<string>>
     */
    public function recordedNumbers(int $drawId): array
    {
        // N3 numbers live on either draw.metadata[glo].n3 (settlement lane)
        // or draw_results.metadata[glo].n3 (import lane). GloResultService's
        // flat payload does not surface them — read both metadata lanes.
        $tierKey = (string) $this->config->get('glo.tiers.metadata_key', 'glo');
        $raw = null;

        $draw = \App\Models\Draw::query()->find($drawId);

        if ($draw !== null) {
            $meta = is_array($draw->metadata) ? $draw->metadata : [];
            $lane = is_array($meta[$tierKey] ?? null) ? $meta[$tierKey] : [];

            if (isset($lane['n3_numbers']) && is_array($lane['n3_numbers'])) {
                // Settlement writes numbers under n3_numbers.
                $raw = $lane['n3_numbers'];
            } elseif (isset($lane['n3']) && is_array($lane['n3'])) {
                $raw = $lane['n3'];
            } elseif (isset($meta['n3']) && is_array($meta['n3'])) {
                $raw = $meta['n3'];
            }
        }

        if ($raw === null) {
            $result = \App\Models\DrawResult::query()->where('draw_id', $drawId)->first();

            if ($result !== null) {
                $meta = is_array($result->metadata) ? $result->metadata : [];
                $lane = is_array($meta[$tierKey] ?? null) ? $meta[$tierKey] : [];

                if (isset($lane['n3']) && is_array($lane['n3'])) {
                    $raw = $lane['n3'];
                } elseif (isset($meta['n3']) && is_array($meta['n3'])) {
                    $raw = $meta['n3'];
                }
            }
        }

        if ($raw === null) {
            return [];
        }

        $out = [];

        foreach (['special', 'first', 'second', 'third'] as $group) {
            $list = $raw[$group] ?? [];

            if (! is_array($list)) {
                continue;
            }

            $digits = [];

            foreach ($list as $number) {
                $asString = is_int($number) ? sprintf('%03d', $number) : trim((string) $number);

                // Keep only well-formed 3-digit strings (with leading zeros).
                if (preg_match('/^\d{3}$/', $asString)) {
                    $digits[] = $asString;
                }
            }

            if ($digits !== []) {
                $out[$group] = $digits;
            }
        }

        return $out;
    }
}
