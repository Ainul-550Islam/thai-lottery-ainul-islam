<x-filament-panels::page>
    <div class="space-y-6">

        {{-- WHERE THE TRUTH LIVES --}}
        <x-filament::section>
            <x-slot name="heading">What this page is</x-slot>
            <x-slot name="description">
                A read-only projection of the catalogue the platform actually sells. There is no
                games table: the games are <code>config/lottery.php</code> (<code>types</code> and
                <code>markets</code>), and the draws and bets tables only refer to those words.
                A create/edit form here would write a row no engine reads, so it is deliberately
                absent — the values below are deployed with the code, not clicked into place.
            </x-slot>

            @php($games = $this->games())
            @php($drift = $this->drift())

            <div class="flex flex-wrap gap-3 text-sm">
                <x-filament::badge color="gray">{{ count($games) }} configured game types</x-filament::badge>
                <x-filament::badge color="gray">{{ count($this->markets()) }} sellable markets</x-filament::badge>
                <x-filament::badge :color="$drift === [] ? 'success' : 'warning'">
                    {{ $drift === [] ? 'no configuration drift' : count($drift).' configuration disagreements' }}
                </x-filament::badge>
            </div>
        </x-filament::section>

        {{-- GAMES --}}
        <x-filament::section>
            <x-slot name="heading">Game types</x-slot>
            <x-slot name="description">
                Digit counts, number ranges, ordering rule and the type-level payout multiplier, with
                the live volume behind each one. The multiplier is what a winning unit is paid at
                before any per-market override.
            </x-slot>

            @if ($games === [])
                <p class="text-sm text-danger-600">
                    The catalogue could not be read. An empty table here would not mean the platform
                    sells nothing — check the application log, the configuration read failed.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="p-2">Game</th>
                                <th class="p-2">State</th>
                                <th class="p-2">Digits</th>
                                <th class="p-2">Range</th>
                                <th class="p-2">Order</th>
                                <th class="p-2">Payout</th>
                                <th class="p-2">Draws</th>
                                <th class="p-2">Next draw</th>
                                <th class="p-2">Bets</th>
                                <th class="p-2">Stake</th>
                                <th class="p-2">Number limits</th>
                                <th class="p-2">Enums</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($games as $game)
                                <tr class="border-b align-top">
                                    <td class="p-2 font-semibold">{{ $game['label'] }} <span class="opacity-60 font-mono text-xs">{{ $game['key'] }}</span></td>
                                    <td class="p-2">
                                        <x-filament::badge :color="$game['enabled'] ? 'success' : 'gray'">
                                            {{ $game['enabled'] ? 'offered' : 'off' }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="p-2">{{ $game['digits'] }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $game['min_number'] }}–{{ $game['max_number'] }}</td>
                                    <td class="p-2 text-xs">
                                        {{ $game['requires_exact_order'] ? 'exact order' : 'any order' }}
                                        @if ($game['allows_permutation'])
                                            <span class="opacity-70">(permutations allowed)</span>
                                        @endif
                                    </td>
                                    <td class="p-2">×{{ $game['payout_multiplier'] }}</td>
                                    <td class="p-2">{{ $game['live']['draws_total'] }}{{ $game['live']['draws_open'] > 0 ? ' ('.$game['live']['draws_open'].' open)' : '' }}</td>
                                    <td class="p-2 text-xs">{{ $this->time($game['live']['next_scheduled_at']) }}</td>
                                    <td class="p-2">{{ $game['live']['bets_total'] }}</td>
                                    <td class="p-2">{{ $this->money($game['live']['stake_total']) }}</td>
                                    <td class="p-2">{{ $game['live']['number_limits'] }}</td>
                                    <td class="p-2 text-xs">
                                        <span class="{{ $game['bet_type_present'] ? 'text-success-600' : 'text-danger-600' }}">BetType</span>
                                        <span class="{{ $game['draw_type_present'] ? 'text-success-600' : 'text-danger-600' }}">DrawType</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- MARKETS --}}
        <x-filament::section>
            <x-slot name="heading">Sellable markets</x-slot>
            <x-slot name="description">
                Each market names the result field it settles against and its own multiplier. Where
                that multiplier differs from the game's, the difference is shown rather than
                reconciled — Run pays 3/4 per position while the Run game row declares 12.
            </x-slot>

            @php($markets = $this->markets())

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 dark:bg-gray-800">
                        <tr>
                            <th class="p-2">Market</th>
                            <th class="p-2">Game</th>
                            <th class="p-2">State</th>
                            <th class="p-2">Digits</th>
                            <th class="p-2">Position</th>
                            <th class="p-2">Settles against</th>
                            <th class="p-2">Match</th>
                            <th class="p-2">Payout</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($markets as $market)
                            <tr class="border-b">
                                <td class="p-2">{{ $market['label'] }} <span class="opacity-60 font-mono text-xs">{{ $market['key'] }}</span></td>
                                <td class="p-2 font-mono text-xs">{{ $market['bet_type'] }}</td>
                                <td class="p-2">
                                    <x-filament::badge :color="$market['enabled'] ? 'success' : 'gray'">
                                        {{ $market['enabled'] ? 'sellable' : 'off' }}
                                    </x-filament::badge>
                                </td>
                                <td class="p-2">{{ $market['digits'] }}</td>
                                <td class="p-2">{{ $market['position'] }}</td>
                                <td class="p-2 font-mono text-xs">{{ $market['source'] }}</td>
                                <td class="p-2 font-mono text-xs">{{ $market['match_mode'] }}</td>
                                <td class="p-2">
                                    ×{{ $market['payout_multiplier'] }}
                                    @if ($market['differs_from_type'])
                                        <span class="text-warning-600 text-xs">(game row says ×{{ $market['type_payout_multiplier'] }})</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- DRIFT --}}
        <x-filament::section>
            <x-slot name="heading">Configuration drift</x-slot>
            <x-slot name="description">
                The same games are described three times — configuration, <code>BetType</code> and
                <code>DrawType</code> — and the descriptions do not always agree. Configuration is
                authoritative and the engine refuses to normalise a number while
                <code>BetType</code> disagrees with it, so these rows are the reason a bet can be
                rejected "for no visible reason". Nothing is fixed here: changing a digit count or a
                multiplier changes what a player is paid, which is not a panel click.
            </x-slot>

            @if ($drift === [])
                <p class="text-sm text-success-600">Configuration and both enums agree on every game.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="p-2">Game</th>
                                <th class="p-2">Field</th>
                                <th class="p-2">Configuration</th>
                                <th class="p-2">Enum</th>
                                <th class="p-2">Why it matters</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($drift as $row)
                                <tr class="border-b align-top">
                                    <td class="p-2 font-mono text-xs">{{ $row['game'] }}</td>
                                    <td class="p-2">{{ $row['field'] }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $row['config'] }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $row['enum'] }}</td>
                                    <td class="p-2 text-xs opacity-80">{{ $row['source'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- PRODUCTS --}}
        <x-filament::section>
            <x-slot name="heading">Draw products</x-slot>
            <x-slot name="description">
                The four public products and whether each is switched on. The public hub deliberately
                omits a switched-off product, so this is the only screen that can tell an operator
                that a product is off rather than broken.
            </x-slot>

            @php($products = $this->products())

            <div class="flex flex-wrap gap-3">
                @foreach ($products as $product)
                    <x-filament::badge :color="$product['enabled'] ? 'success' : 'danger'">
                        {{ $product['key'] }} — {{ $product['enabled'] ? 'enabled' : 'disabled' }}
                    </x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

    </div>
</x-filament-panels::page>
