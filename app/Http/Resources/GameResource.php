<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\LotteryGames\GameCapability;
use App\DTOs\LotteryGames\GameDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of one digit game: its rules as far as a player may see them, its
 * sellability, and its next draw.
 *
 * WHY THE RULES ARE INCLUDED AT ALL
 * A client must know a market's digit width and whether it is exact/any_order/prefix/
 * suffix BEFORE it can build a valid selection, and the payout multiplier is what the
 * quote screen has to show. All three are operator-configured values, read from the
 * definition the registry built — this resource never computes or defaults any of them.
 *
 * WHY UNCONFIGURED VALUES ARE NULL RATHER THAN THE PLACEHOLDER STRING
 * `REQUIRED_INPUT` is an operator instruction, not a value. A player-facing payload says
 * `null`, and `capability.status` says `NOT_CONFIGURED` with the reasons in
 * `capability.missing`. Echoing the placeholder into a client would invite a UI to
 * render "REQUIRED_INPUT" as if it were a game setting.
 *
 * WHAT IS WITHHELD
 * The result-source endpoint, the publication bridge, the settlement market key and the
 * liability caps. Those are operator plumbing and the caps in particular are exactly the
 * kind of ceiling a client could probe one purchase at a time.
 */
final class GameResource extends JsonResource
{
    /**
     * @param  array{definition: GameDefinition, capability: GameCapability, upcoming: array<string, mixed>}  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GameDefinition $definition */
        $definition = $this->resource['definition'];

        /** @var GameCapability $capability */
        $capability = $this->resource['capability'];

        /** @var array<string, mixed> $upcoming */
        $upcoming = $this->resource['upcoming'];

        $betTypes = [];

        foreach ($definition->betTypeKeys() as $betType) {
            $betTypes[] = [
                'key' => $betType,
                'selection_digits' => $definition->selectionDigitsFor($betType),
                'match_mode' => $definition->matchModeFor($betType)?->value,
                'match_mode_label' => $definition->matchModeFor($betType)?->label(),
                'payout_multiplier' => $definition->payoutMultiplierFor($betType),
            ];
        }

        return [
            'code' => $definition->code,
            'name' => $definition->name,
            'digits' => $definition->digits,
            'timezone' => $definition->timezone,
            'min_stake' => $definition->minStake,
            'max_stake' => $definition->maxStake,
            'bet_types' => $betTypes,
            'sellable' => $capability->isSellable(),
            'capability' => [
                'status' => $capability->status,
                'reason' => $capability->reason,
                'missing' => $capability->missing,
            ],
            'next_draw' => (new GameDrawResource($upcoming))->toArray($request),
        ];
    }
}
