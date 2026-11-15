<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;

/**
 * VALIDATES AN OPERATOR-SUPPLIED RULE BLOCK BEFORE IT IS ALLOWED ANYWHERE NEAR CONFIG.
 *
 * WHY A SEPARATE VALIDATOR
 * `lottery-games:rules:import` accepts a JSON document typed by a human from an official
 * rules document. That document is untrusted input: it arrives from a browser, a chat
 * message or a spreadsheet export, and a single wrong multiplier is a pricing error that
 * pays out for as long as nobody notices. So the checks happen HERE, before the file is
 * written, and the import command refuses to write anything that fails.
 *
 * WHAT IT DELEGATES, AND WHY IT DELEGATES IT
 * The rule-level checks (digit width vs market width, cron that parses, cutoff inside the
 * cadence, ordered stakes, per-number cap that can bind, IANA timezone, endpoint contract,
 * decidable publication bridge) already exist in GameRulesValidator, and the RTP ceiling
 * check already exists in RtpAnalyzer. This class does NOT re-implement any of them: it
 * builds the same GameDefinition the registry would build, runs the same two services, and
 * adds only the two things a fresh document needs and a live configuration never does:
 *
 *   1  STRUCTURE — the keys the block must have at all, named individually, because
 *      "invalid" is not actionable to a person holding a PDF.
 *   2  UNKNOWN KEYS — a misspelled key is silently ignored by every reader, so an operator
 *      who typed `payout_multiplliers` would ship a market with no multiplier and see a
 *      green report. Unknown keys are reported here, by name, as warnings.
 */
final class RulesImportValidator
{
    /**
     * Every key a complete game block must carry. An absent key is a structural problem;
     * a key that is present but still REQUIRED_INPUT is reported through `missing` by
     * GameDefinition, which is the same list the validate-rules command prints.
     */
    public const REQUIRED_KEYS = GameDefinition::REQUIRED_CONFIG_KEYS;

    /**
     * Keys that are allowed but not required: identity and the declared status, which
     * the resolver or the operator's own row governs anyway.
     */
    public const OPTIONAL_KEYS = [
        'code',
        'name',
        'rules_status',
    ];

    /**
     * The keys a NESTED map may carry. A misspelling one level down is the dangerous kind:
     * `bet_types.4d_direct.payout_multiplier` misspelled leaves a market with no
     * multiplier, and no reader anywhere reports it — the market simply never prices.
     *
     * `publication_bridge` is deliberately NOT listed: its members are operator-named
     * tiers, so there is no closed set to check against.
     */
    public const NESTED_KEYS = [
        'bet_types' => ['selection_digits', 'match_mode', 'payout_multiplier'],
        'result_source' => [
            'driver',
            'base_url',
            'latest_path',
            'by_draw_no_path',
            'draw_no_field',
            'digits_field',
            'drawn_at_field',
            'schema_version',
        ],
    ];

    public function __construct(
        private readonly GameRulesValidator $rules,
        private readonly RtpAnalyzer $rtp,
    ) {}

    /**
     * Inspect one candidate block.
     *
     * `valid` is the conjunction of: no structural problem, no unknown REQUIRED_INPUT,
     * no rule problem, and no RTP breach. Warnings never make a block invalid — they are
     * things an operator must see and may knowingly accept (a sub-1x market, a market
     * key that only the settlement adapter validates).
     *
     * @param  array<string, mixed>  $block
     * @return array{
     *     valid: bool,
     *     game_code: string,
     *     missing: list<string>,
     *     problems: list<string>,
     *     warnings: list<string>,
     *     rtp: array<string, mixed>|null
     * }
     */
    public function inspect(string $code, array $block, string $ceiling = '0.98'): array
    {
        $problems = [];
        $warnings = [];

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $block)) {
                $problems[] = sprintf('the block has no "%s" key at all.', $key);
            }
        }

        $known = array_merge(self::REQUIRED_KEYS, self::OPTIONAL_KEYS);

        foreach (array_keys($block) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $warnings[] = sprintf(
                    'unknown key "%s" — no reader in this lane looks at it; a misspelling here is a rule that never applies.',
                    (string) $key,
                );
            }
        }

        if (! is_array($block['bet_types'] ?? null) || ($block['bet_types'] ?? []) === []) {
            $problems[] = 'bet_types is empty: a game with no market can never be sold.';
        }

        foreach (self::NESTED_KEYS as $parent => $allowed) {
            $children = $block[$parent] ?? null;

            if (! is_array($children)) {
                continue;
            }

            // bet_types is two levels deep (type -> rule); result_source is one.
            $maps = $parent === 'bet_types'
                ? array_map(static fn ($rule): array => is_array($rule) ? $rule : [], $children)
                : [$children];

            foreach ($maps as $mapKey => $map) {
                foreach (array_keys($map) as $key) {
                    if (in_array((string) $key, $allowed, true)) {
                        continue;
                    }

                    $warnings[] = sprintf(
                        'unknown key "%s.%s" — no reader in this lane looks at it; a misspelling here is a rule that never applies.',
                        $parent === 'bet_types' ? $parent.'.'.$mapKey : $parent,
                        (string) $key,
                    );
                }
            }
        }

        $normalised = $this->normalise($code, $block);

        $definition = GameDefinition::fromConfig($code, $normalised);
        $report = $this->rules->validate($definition);

        $missing = $report['missing'];
        $problems = array_merge($problems, $report['problems']);
        $warnings = array_merge($warnings, $report['warnings']);

        // RTP is only meaningful once the multipliers and widths are readable; a block
        // with REQUIRED_INPUT left in it has already failed above, and reporting a
        // "breach" for an unpriced market would bury the real reason.
        $rtp = null;

        if ($missing === []) {
            $rtp = $this->rtp->analyze($definition, $ceiling);

            if ($rtp['within_ceiling'] !== true) {
                foreach ((array) $rtp['breaches'] as $breach) {
                    $problems[] = 'RTP ceiling: '.$breach;
                }
            }
        }

        return [
            'valid' => $missing === [] && $problems === [],
            'game_code' => $code,
            'missing' => $missing,
            'problems' => $problems,
            'warnings' => $warnings,
            'rtp' => $rtp,
        ];
    }

    /**
     * Shape a candidate block the way the registry would read it.
     *
     * Only two things are imposed, and both are the deployment's own invariants rather
     * than the operator's data: the code comes from the key the block is filed under, and
     * a missing rules_status means 'unverified' (never 'verified' — that is a row, not a
     * file).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function normalise(string $code, array $block): array
    {
        $block['code'] = $code;

        if (! isset($block['rules_status'])
            || ! is_string($block['rules_status'])
            || ! in_array($block['rules_status'], ['unverified', 'verified', 'suspended'], true)
        ) {
            $block['rules_status'] = 'unverified';
        }

        return $block;
    }
}
