<?php

declare(strict_types=1);

namespace App\Services\LotteryGames\Providers;

use App\Contracts\LotteryGames\GameResultProvider;
use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;
use App\Exceptions\LotteryGameException;
use App\Services\LotteryGames\DigitNumberValidator;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The official result feed, driven ENTIRELY by configuration.
 *
 * IT IS NOT CONFIGURED OUT OF THE BOX
 * Every endpoint, path and field name for both games is REQUIRED_INPUT, so this
 * provider refuses with game_result_source_unconfigured until an operator signs
 * a contract with a licensed result provider and fills the values in. There is
 * no scraping here and no guessed URL: a wrong guess would publish a wrong
 * number, and a wrong number pays real money.
 *
 * A SCHEMA CHANGE IS A FAILURE, NEVER AN EMPTY SUCCESS
 * The active failure mode of a naive feed reader is: the provider renames a
 * field, the reader finds nothing, returns null, and the draw silently never
 * settles. Here, a response that arrives but does not contain the configured
 * fields throws payloadInvalid with the reason — so the retry, the breaker and
 * the operator alert all see it.
 *
 * DIGIT WIDTH IS VALIDATED, NOT TRUSTED
 * A provider returning a 3-digit result for a 4-digit game (a schema change, or
 * a different game's feed) is refused by DigitNumberValidator instead of being
 * settled as a 3-digit draw.
 */
final class OfficialGameResultProvider implements GameResultProvider
{
    public function __construct(
        private readonly Repository $config,
        private readonly DigitNumberValidator $validator,
    ) {}

    public function name(): string
    {
        return 'official';
    }

    public function fetchLatest(GameDefinition $game): ?DigitDrawResult
    {
        return $this->fetch($game, null);
    }

    public function fetchByDrawNo(GameDefinition $game, string $drawNo): ?DigitDrawResult
    {
        return $this->fetch($game, $drawNo);
    }

    /**
     * Non-throwing readiness probe used by the availability gate and the
     * console doctor: is there a complete endpoint contract at all?
     */
    public function isConfigured(GameDefinition $game): bool
    {
        foreach (['base_url', 'latest_path', 'draw_no_field', 'digits_field'] as $key) {
            $value = $game->resultSource[$key] ?? null;

            if (! is_string($value) || trim($value) === '' || trim($value) === GameDefinition::REQUIRED_INPUT) {
                return false;
            }
        }

        return true;
    }

    private function fetch(GameDefinition $game, ?string $drawNo): ?DigitDrawResult
    {
        if (! $this->isConfigured($game)) {
            throw LotteryGameException::resultSourceUnconfigured($game->code);
        }

        $source = $game->resultSource;
        $path = $drawNo === null
            ? (string) $source['latest_path']
            : str_replace('{draw_no}', rawurlencode($drawNo), (string) ($source['by_draw_no_path'] ?? $source['latest_path']));

        $url = rtrim((string) $source['base_url'], '/').'/'.ltrim($path, '/');

        try {
            $response = Http::timeout((int) $this->config->get('lottery_games.ingestion.http_timeout_seconds', 10))
                ->acceptJson()
                ->get($url);
        } catch (Throwable $e) {
            // A transport failure is loud: the chain records it and the next
            // provider (if any) is tried.
            throw LotteryGameException::payloadInvalid($game->code, 'transport failure: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw LotteryGameException::payloadInvalid(
                $game->code,
                'the result source answered HTTP '.$response->status(),
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw LotteryGameException::payloadInvalid($game->code, 'the response is not a JSON object');
        }

        $drawNoField = (string) $source['draw_no_field'];
        $digitsField = (string) $source['digits_field'];
        $drawnAtField = isset($source['drawn_at_field']) && is_string($source['drawn_at_field'])
            ? trim($source['drawn_at_field'])
            : '';

        $resolvedDrawNo = $this->readPath($body, $drawNoField);
        $resolvedDigits = $this->readPath($body, $digitsField);

        if ($resolvedDrawNo === null || $resolvedDigits === null) {
            throw LotteryGameException::payloadInvalid(
                $game->code,
                'the response does not contain the configured draw-no/digits fields',
            );
        }

        if ($drawNo !== null && $resolvedDrawNo !== $drawNo) {
            throw LotteryGameException::payloadInvalid(
                $game->code,
                'the response is for draw '.$resolvedDrawNo.', not the requested '.$drawNo,
            );
        }

        // Digit width is a property of the GAME, so a wrongly-shaped payload is
        // refused here rather than downstream at settlement.
        $digits = $this->validator->validateDrawnDigits($game, $resolvedDigits);

        $drawnAt = $drawnAtField === '' ? now()->toIso8601String() : (string) ($this->readPath($body, $drawnAtField) ?? now()->toIso8601String());

        return new DigitDrawResult(
            gameCode: $game->code,
            drawNo: $resolvedDrawNo,
            digits: $digits,
            drawnAt: $drawnAt,
            source: $this->name(),
        );
    }

    /**
     * Read a dotted path out of the decoded payload, so a feed that nests its
     * result under data.result.draw can be configured rather than coded.
     *
     * @param  array<string, mixed>  $body
     */
    private function readPath(array $body, string $path): ?string
    {
        $cursor = $body;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        if (is_string($cursor) || is_int($cursor)) {
            return (string) $cursor;
        }

        return null;
    }
}
