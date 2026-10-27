{{--
    Balance transfer panel (Cash <-> Win).

    RENDERED SERVER-SIDE ON PURPOSE.
    Balances, limits and lane availability are all in the HTML, so the panel is
    correct and readable with JavaScript disabled. The script only adds live
    quoting and submit-without-reload; it never supplies a fact the page does not
    already contain.

    Expected variable:
      $transferPanel — array from App\Services\Transfer\TransferPanelService

    NO SECRET AND NO OTHER PLAYER'S DATA is present here. The CSRF token is the
    only credential, and it is the one this form is entitled to.
--}}
@php
    $panel = $transferPanel ?? null;
    $enabled = is_array($panel) && (bool) ($panel['enabled'] ?? false);
    $pockets = is_array($panel) ? (array) ($panel['pockets'] ?? []) : [];
    $directions = is_array($panel) ? (array) ($panel['directions'] ?? []) : [];
    $currency = is_array($panel) ? (string) ($panel['currency'] ?? 'THB') : 'THB';

    // Rendered server-side so the history is correct with JavaScript disabled.
    // The script refreshes this same tbody after a submit; it never supplies a
    // fact the page did not already contain.
    $recent = is_array($panel) ? array_values(array_filter(
        (array) ($panel['recent'] ?? []),
        static fn ($row): bool => is_array($row),
    )) : [];

    $cash = $pockets['cash'] ?? null;
    $win = $pockets['win'] ?? null;

    $cashAvailable = is_array($cash) ? (string) ($cash['available'] ?? '0.00') : '0.00';
    $winAvailable = is_array($win) ? (string) ($win['available'] ?? '0.00') : '0.00';

    // The lane labels are re-translated HERE, in the view, before they are handed
    // to the script as JSON. TransferPanelService returns the enum's own English
    // label; the script must never be the place a sentence is chosen, because a
    // script that picks copy is a second source of truth that will not follow a
    // locale change.
    foreach ($directions as $laneKey => $lane) {
        if (! is_array($lane)) {
            continue;
        }

        if (Lang::has('transfer.direction_'.$laneKey)) {
            $directions[$laneKey]['label'] = __('transfer.direction_'.$laneKey);
        }

        // A ready-to-render sentence, or null when the lane cannot be priced -
        // which is also why its `enabled` flag is false.
        $directions[$laneKey]['fee_rate_label'] = isset($lane['fee_rate_percent']) && $lane['fee_rate_percent'] !== null
            ? __('transfer.fee_rate_percent', ['percent' => (string) $lane['fee_rate_percent']])
            : null;

        $directions[$laneKey]['unavailable_label'] = __('transfer.lane_unavailable');

        // One server-translated sentence summarising what is stopping this lane
        // right now, or an empty string when nothing is. Built here rather than in
        // the script so the panel says the same thing with JavaScript off, and so
        // the script can swap lanes by assigning textContent from this map instead
        // of composing a sentence out of numbers.
        $allowance = isset($lane['allowance']) && is_array($lane['allowance']) ? $lane['allowance'] : null;
        $statusLine = '';

        if (! (bool) ($lane['enabled'] ?? false)) {
            $statusLine = __('transfer.lane_unavailable');
        } elseif ($allowance !== null) {
            $cooldownLeft = (int) ($allowance['cooldown_seconds_remaining'] ?? 0);
            $countLeft = $allowance['count_remaining'] ?? null;
            $amountLeft = $allowance['amount_remaining'] ?? null;

            if ($cooldownLeft > 0) {
                $statusLine = __('transfer.cooldown_hint', ['seconds' => (string) $cooldownLeft]);
            } elseif (is_int($countLeft) && $countLeft <= 5) {
                // Only surfaced when it is close. A player with 47 of 50 transfers
                // left does not need to be told about a limit they will not reach.
                $statusLine = __('transfer.allowance_count_remaining', ['count' => (string) $countLeft]);
            } elseif (is_string($amountLeft) && bccomp($amountLeft, (string) ($lane['minimum'] ?? '0.00'), 2) <= 0) {
                $statusLine = __('transfer.allowance_amount_remaining', ['amount' => $amountLeft]);
            }
        }

        $directions[$laneKey]['status_line'] = $statusLine;
    }

    // The panel defaults to the first ENABLED direction, so a lane the operator
    // switched off is never the one pre-selected.
    $defaultDirection = null;
    foreach ($directions as $key => $lane) {
        if (is_array($lane) && (bool) ($lane['enabled'] ?? false)) {
            $defaultDirection = (string) $key;
            break;
        }
    }

    // Translated status labels for the server-rendered history rows.
    $statusLabels = [
        'pending' => __('transfer.history_status_pending'),
        'completed' => __('transfer.history_status_completed'),
        'failed' => __('transfer.history_status_failed'),
    ];
@endphp

<section
    id="balance-transfer-panel"
    class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl"
    data-enabled="{{ $enabled ? '1' : '0' }}"
    data-currency="{{ $currency }}"
    data-cash-available="{{ $cashAvailable }}"
    data-win-available="{{ $winAvailable }}"
    data-quote-url="{{ is_array($panel) ? (string) ($panel['quote_url'] ?? '') : '' }}"
    data-submit-url="{{ is_array($panel) ? (string) ($panel['submit_url'] ?? '') : '' }}"
    data-history-url="{{ is_array($panel) ? (string) ($panel['history_url'] ?? '') : '' }}"
    data-panel-url="{{ route('player.transfer.panel') }}"
    data-directions='@json($directions)'
    {{-- The script shows the server's own `message` for a refusal. These two are
         the only strings it may fall back to, and both are server-translated, so
         the script never builds a sentence and never owns any copy. --}}
    data-error-fallback="{{ __('transfer.errors.fallback') }}"
    data-error-network="{{ __('transfer.errors.network') }}"
    data-quote-failed="{{ __('transfer.errors.quote_failed') }}"
    data-submit-failed="{{ __('transfer.errors.submit_failed') }}"
    data-lane-unavailable="{{ __('transfer.lane_unavailable') }}"
    data-fee-unavailable="{{ __('transfer.fee_unavailable') }}"
    data-arrives-template="{{ __('transfer.you_will_receive', ['pocket' => '__POCKET__']) }}"
    data-pocket-cash="{{ __('transfer.pocket_cash') }}"
    data-pocket-win="{{ __('transfer.pocket_win') }}"
    {{-- Translated status words, so the script can add a row to the history table
         after a submit without owning any copy of its own. Same pattern as the
         min/max hint templates: the server chooses the sentence, the script
         substitutes values into it. --}}
    data-status-labels='@json($statusLabels)'
    aria-labelledby="balance-transfer-title"
>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h2 id="balance-transfer-title" class="text-lg font-bold text-white">{{ __('transfer.panel_title') }}</h2>
            <p class="text-slate-400 text-sm mt-1">{{ __('transfer.panel_lead') }}</p>
        </div>

        {{-- Both pocket balances, visible without JavaScript. --}}
        <div class="flex items-center gap-4 text-right">
            <div>
                <span class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold">{{ __('transfer.pocket_cash') }}</span>
                <span class="block font-mono text-sm font-bold text-amber-400" data-balance-display="cash">{{ $cashAvailable }} {{ $currency }}</span>
            </div>
            <div>
                <span class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold">{{ __('transfer.pocket_win') }}</span>
                <span class="block font-mono text-sm font-bold text-emerald-400" data-balance-display="win">{{ $winAvailable }} {{ $currency }}</span>
            </div>
        </div>
    </div>

    @unless($enabled)
        {{-- An unavailable lane says so plainly instead of rendering a form that
             would refuse every submit. --}}
        <p class="text-sm text-slate-400 bg-slate-800/60 border border-slate-700 rounded-xl px-4 py-3" role="status">
            {{ __('transfer.panel_disabled') }}
        </p>
    @else
        <form
            id="balance-transfer-form"
            method="POST"
            action="{{ is_array($panel) ? (string) ($panel['submit_url'] ?? '') : '' }}"
            class="space-y-5"
            novalidate
        >
            @csrf

            <input
                type="hidden"
                name="idempotency_key"
                id="transfer-idempotency-key"
                value=""
                autocomplete="off"
            >

            {{-- Direction --------------------------------------------------}}
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">
                    {{ __('transfer.from') }} / {{ __('transfer.to') }}
                </span>

                <div class="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="{{ __('transfer.panel_title') }}">
                    @foreach($directions as $key => $lane)
                        @php
                            $laneEnabled = is_array($lane) && (bool) ($lane['enabled'] ?? false);
                            $isDefault = $defaultDirection === (string) $key;
                        @endphp
                        <label
                            class="flex-1 min-w-[12rem] cursor-pointer {{ $laneEnabled ? '' : 'opacity-50 cursor-not-allowed' }}"
                            @unless($laneEnabled) aria-disabled="true" @endunless
                        >
                            <input
                                type="radio"
                                name="direction"
                                value="{{ $key }}"
                                class="peer sr-only"
                                data-from="{{ is_array($lane) ? (string) ($lane['from'] ?? '') : '' }}"
                                data-to="{{ is_array($lane) ? (string) ($lane['to'] ?? '') : '' }}"
                                data-minimum="{{ is_array($lane) ? (string) ($lane['minimum'] ?? '0.00') : '0.00' }}"
                                data-maximum="{{ is_array($lane) ? (string) ($lane['maximum'] ?? '0.00') : '0.00' }}"
                                @unless($laneEnabled) disabled @endunless
                                @if($isDefault) checked @endif
                            >
                            <span class="block rounded-xl border border-slate-700 bg-slate-800/60 px-4 py-3 text-sm font-semibold text-slate-200 transition peer-checked:border-amber-500 peer-checked:bg-amber-500/10 peer-checked:text-amber-300 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-amber-400">
                                {{ is_array($lane) ? (string) ($lane['label'] ?? $key) : $key }}
                            </span>
                        </label>
                    @endforeach
                </div>

                {{-- Shown when the selected lane is switched off or cannot be
                     priced. Empty and hidden otherwise. The text comes from the
                     lane's own server-translated `unavailable_label`. --}}
                <p
                    class="text-[11px] text-rose-400 mt-2 hidden"
                    data-role="lane-notice"
                    role="status"
                ></p>

                {{-- Cooldown and remaining allowance for the selected lane.
                     Rendered server-side for the default lane so it is correct
                     without JavaScript; the script swaps in the selected lane's
                     own pre-translated sentence. --}}
                @php
                    $defaultStatusLine = $defaultDirection !== null && isset($directions[$defaultDirection]['status_line'])
                        ? (string) $directions[$defaultDirection]['status_line']
                        : '';
                @endphp
                <p
                    class="text-[11px] text-slate-500 mt-2 {{ $defaultStatusLine === '' ? 'hidden' : '' }}"
                    data-role="lane-status"
                    role="status"
                >{{ $defaultStatusLine }}</p>
            </div>

            {{-- Amount ------------------------------------------------------}}
            <div>
                <label for="transfer-amount" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">
                    {{ __('transfer.amount') }} ({{ $currency }})
                </label>

                <input
                    type="text"
                    inputmode="decimal"
                    id="transfer-amount"
                    name="amount"
                    value=""
                    placeholder="0.00"
                    autocomplete="off"
                    aria-describedby="transfer-amount-hint"
                    class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-lg text-white placeholder-slate-600 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                >

                {{-- The bound text is filled from the selected lane's data
                     attributes; the fallback below is the honest minimum for the
                     default lane, so it is correct even before JS runs. --}}
                {{-- The translated sentence templates live in data attributes so
                     the script can swap the lane without owning any copy: JS
                     substitutes a number into a server-translated sentence and
                     never builds one itself. --}}
                <p
                    id="transfer-amount-hint"
                    class="text-[11px] text-slate-500 mt-2"
                    data-role="bounds"
                    data-min-template="{{ __('transfer.minimum_hint', ['amount' => '__AMOUNT__']) }}"
                    data-max-template="{{ __('transfer.maximum_hint', ['amount' => '__AMOUNT__']) }}"
                    data-separator=" · "
                >
                    @if($defaultDirection !== null && isset($directions[$defaultDirection]))
                        {{ __('transfer.minimum_hint', ['amount' => (string) ($directions[$defaultDirection]['minimum'] ?? '0.00') ]) }}
                        ·
                        {{ __('transfer.maximum_hint', ['amount' => (string) ($directions[$defaultDirection]['maximum'] ?? '0.00') ]) }}
                    @endif
                </p>
            </div>

            {{-- Live quote ---------------------------------------------------}}
            <dl
                id="transfer-quote"
                class="rounded-xl border border-slate-800 bg-slate-950/60 divide-y divide-slate-800 text-sm"
                data-role="quote"
                hidden
            >
                <div class="flex items-center justify-between px-4 py-2.5">
                    <dt class="text-slate-400">{{ __('transfer.fee') }}</dt>
                    <dd class="font-mono text-slate-200" data-quote="fee">—</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5">
                    <dt class="text-slate-400">{{ __('transfer.total_debited') }}</dt>
                    <dd class="font-mono font-bold text-white" data-quote="total">—</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5">
                    <dt class="text-slate-400" data-quote="arrives-label">{{ __('transfer.you_will_receive', ['pocket' => __('transfer.pocket_win') ]) }}</dt>
                    <dd class="font-mono font-bold text-emerald-400" data-quote="arrives">—</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5">
                    <dt class="text-slate-400">{{ __('transfer.fee') }}</dt>
                    {{-- Filled from the selected lane's server-translated rate
                         sentence. Empty when the lane cannot be priced, in which
                         case the lane is disabled and this whole block is never
                         shown. --}}
                    <dd class="text-xs text-slate-500" data-quote="rate"></dd>
                </div>
            </dl>

            {{-- Result / refusal banner. Empty and hidden until something happens,
                 and always aria-live so a screen reader announces the refusal
                 rather than leaving the player guessing why the button did nothing. --}}
            <p
                id="transfer-result"
                class="rounded-xl px-4 py-3 text-sm border hidden"
                role="status"
                aria-live="polite"
                data-role="result"
            ></p>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    id="transfer-submit"
                    class="px-6 py-3 bg-amber-500 hover:bg-amber-400 disabled:opacity-50 disabled:cursor-not-allowed text-slate-950 font-bold rounded-xl shadow transition text-sm"
                    data-label-submit="{{ __('transfer.submit') }}"
                    data-label-submitting="{{ __('transfer.submitting') }}"
                >
                    {{ __('transfer.submit') }}
                </button>

                <span class="text-[11px] text-slate-500">{{ __('transfer.fee_hint') }}</span>
            </div>
        </form>

        {{-- History ------------------------------------------------------------}}
        <div class="mt-8 pt-6 border-t border-slate-800">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-white">{{ __('transfer.history_title') }}</h3>
                <a
                    href="{{ is_array($panel) ? (string) ($panel['history_url'] ?? '#') : '#' }}"
                    class="text-xs font-semibold text-amber-400 hover:text-amber-300 transition"
                    data-role="history-link"
                >
                    {{ __('transfer.history_all') }}
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-800">
                            <th class="pb-2 pr-4 font-semibold">{{ __('transfer.history_reference') }}</th>
                            <th class="pb-2 pr-4 font-semibold">{{ __('transfer.history_direction') }}</th>
                            <th class="pb-2 pr-4 font-semibold text-right">{{ __('transfer.history_amount') }}</th>
                            <th class="pb-2 pr-4 font-semibold text-right">{{ __('transfer.history_fee') }}</th>
                            <th class="pb-2 font-semibold">{{ __('transfer.history_status') }}</th>
                        </tr>
                    </thead>
                    {{--
                        RENDERED FROM THE SERVER.
                        A panel that showed an empty history until JavaScript filled it
                        would tell a player with transfers that they have none, and would
                        be wrong for anyone with scripting off. The script replaces these
                        rows after a submit; it is a refresh, not the source.
                    --}}
                    <tbody
                        data-role="history-body"
                        class="divide-y divide-slate-800/70"
                        data-empty="{{ __('transfer.history_empty') }}"
                    >
                        @forelse($recent as $row)
                            @php
                                $rowStatus = (string) ($row['status'] ?? '');
                                $rowDirection = (string) ($row['direction'] ?? '');

                                // Translated in the view, from the maps built above,
                                // falling back to the label the model already carries.
                                $rowStatusLabel = $statusLabels[$rowStatus] ?? (string) ($row['status_label'] ?? '');
                                $rowDirectionLabel = isset($directions[$rowDirection]['label'])
                                    ? (string) $directions[$rowDirection]['label']
                                    : (string) ($row['direction_label'] ?? '');

                                $rowStatusColor = match ($rowStatus) {
                                    'completed' => 'text-emerald-400',
                                    'failed' => 'text-rose-400',
                                    default => 'text-amber-400',
                                };
                            @endphp
                            <tr data-reference="{{ (string) ($row['reference'] ?? '') }}">
                                <td class="py-3.5 pr-4 font-mono text-xs text-slate-300">{{ (string) ($row['reference'] ?? '') }}</td>
                                <td class="py-3.5 pr-4 text-xs text-slate-400">{{ $rowDirectionLabel }}</td>
                                <td class="py-3.5 pr-4 text-right font-mono text-xs text-slate-200">
                                    {{ (string) ($row['amount'] ?? '0.00') }} {{ (string) ($row['currency'] ?? $currency) }}
                                </td>
                                <td class="py-3.5 pr-4 text-right font-mono text-xs text-slate-400">
                                    {{ (string) ($row['fee'] ?? '0.00') }}
                                </td>
                                <td class="py-3.5 text-xs font-semibold {{ $rowStatusColor }}">{{ $rowStatusLabel }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-4 text-slate-500 text-xs">{{ __('transfer.history_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endunless
</section>
