@extends('layouts.app')

@section('title', __('transfer.desk.title'))
@section('meta_robots', 'noindex,nofollow')

{{--
    The operator balance-transfer desk.

    WHY THIS PAGE EXISTS
    config/permission.php declared `transfer.view_any` and `transfer.reverse`, and
    config/transfer.php named both under `permissions`, but no route read either. The
    grants were seeded onto roles and gated nothing. This page is the surface
    `transfer.view_any` was written for; the reversal form on the detail page is the
    surface `transfer.reverse` was written for.

    WHAT IS NEVER DONE HERE
    No arithmetic and no state reasoning. Every figure arrives already at its column
    scale from BalanceTransfer::toAuditArray(), and `reversible` / `reverseBlockers`
    are computed in the controller. A view that decided for itself whether a transfer
    could be reversed would be a second copy of a money rule, and the copy nobody
    tests.

    PRIVACY
    This list shows balances and request ips for players who never asked this operator
    to look. It is gated by transfer.view_any for exactly that reason, and it is marked
    noindex,nofollow so no crawler or browser-sync service ever indexes a URL that may
    carry a search for a named player.
--}}

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Admin Console</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">{{ __('transfer.desk.breadcrumb') }}</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">{{ __('transfer.desk.title') }}</h1>
            <p class="text-sm text-slate-400 mt-2 max-w-3xl">{{ __('transfer.desk.lead') }}</p>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-amber-500/10 text-amber-400 border border-amber-500/30">
                {{ __('transfer.desk.dual_control') }}
            </span>
        </div>
    </div>

    {{-- Filters. A GET form so every filtered view is a shareable, bookmarkable URL and
         nothing here can be mistaken for a mutation. --}}
    <form method="GET" action="{{ route('admin.transfers.index') }}" class="tl-glass-panel p-5 shadow-xl">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <label for="desk-q" class="block text-xs font-semibold text-slate-400 uppercase mb-1">{{ __('transfer.desk.search_label') }}</label>
                <input
                    id="desk-q"
                    name="q"
                    type="search"
                    value="{{ $activeQuery }}"
                    maxlength="190"
                    placeholder="{{ __('transfer.desk.search_placeholder') }}"
                    class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-emerald-500"
                >
            </div>

            <div>
                <label for="desk-user-id" class="block text-xs font-semibold text-slate-400 uppercase mb-1">{{ __('transfer.desk.user_id_label') }}</label>
                <input
                    id="desk-user-id"
                    name="user_id"
                    type="text"
                    inputmode="numeric"
                    value="{{ $activeUserId }}"
                    maxlength="19"
                    class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono placeholder-slate-600 focus:outline-none focus:border-emerald-500"
                >
            </div>

            <div>
                <label for="desk-direction" class="block text-xs font-semibold text-slate-400 uppercase mb-1">{{ __('transfer.desk.filter_direction') }}</label>
                <select
                    id="desk-direction"
                    name="direction"
                    class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500"
                >
                    <option value="">{{ __('transfer.desk.filter_all_directions') }}</option>
                    @foreach ($directions as $lane)
                        <option value="{{ $lane->value }}" @selected($activeDirection === $lane)>
                            {{ __('transfer.direction_'.$lane->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="desk-status" class="block text-xs font-semibold text-slate-400 uppercase mb-1">{{ __('transfer.desk.filter_status') }}</label>
                <select
                    id="desk-status"
                    name="status"
                    class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500"
                >
                    <option value="">{{ __('transfer.desk.filter_all_statuses') }}</option>
                    @foreach ($statuses as $state)
                        <option value="{{ $state->value }}" @selected($activeStatus === $state)>
                            {{ __('transfer.history_status_'.$state->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-3 text-xs text-slate-500 self-end">
                {{ __('transfer.desk.total', ['count' => $meta['total']]) }}
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold px-4 py-2 rounded-lg transition-colors">
                    {{ __('transfer.desk.search_submit') }}
                </button>
                <a href="{{ route('admin.transfers.index') }}" class="flex-1 text-center bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-bold px-4 py-2 rounded-lg transition-colors">
                    {{ __('transfer.desk.search_reset') }}
                </a>
            </div>
        </div>
    </form>

    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        @if (count($transfers) === 0)
            <p class="text-sm text-slate-400 py-8 text-center">
                {{ $meta['total'] === 0 && $activeQuery === null && $activeUserId === null && $activeDirection === null && $activeStatus === null
                    ? __('transfer.desk.empty')
                    : __('transfer.desk.none_matching') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                            <th class="py-3 px-4">{{ __('transfer.desk.col_date') }}</th>
                            <th class="py-3 px-4">{{ __('transfer.desk.col_reference') }}</th>
                            <th class="py-3 px-4">{{ __('transfer.desk.col_player') }}</th>
                            <th class="py-3 px-4">{{ __('transfer.desk.col_direction') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('transfer.desk.col_amount') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('transfer.desk.col_fee') }}</th>
                            <th class="py-3 px-4">{{ __('transfer.desk.col_status') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('transfer.desk.col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70">
                        @foreach ($transfers as $row)
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-4 text-slate-400 whitespace-nowrap font-mono text-xs">
                                    {{ $row['created_at'] }}
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <a href="{{ route('admin.transfers.show', ['reference' => $row['reference']]) }}"
                                       class="text-emerald-400 hover:underline font-mono text-xs">
                                        {{ $row['reference'] }}
                                    </a>
                                    <div class="flex gap-1 mt-1">
                                        @if ($row['is_reversal'])
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold font-mono bg-sky-500/10 text-sky-400 border border-sky-500/30">
                                                {{ __('transfer.desk.badge_reversal') }}
                                            </span>
                                        @endif
                                        @if ($row['is_reversed'])
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold font-mono bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                                {{ __('transfer.desk.badge_reversed') }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="text-slate-200 text-xs">{{ $row['player_name'] ?? __('transfer.desk.none') }}</div>
                                    <div class="text-slate-500 text-[11px] font-mono">#{{ $row['user_id'] }}</div>
                                </td>
                                <td class="py-3 px-4 text-slate-300 text-xs whitespace-nowrap">
                                    {{ $row['direction_label'] ?? $row['direction'] }}
                                </td>
                                <td class="py-3 px-4 text-right text-white font-mono text-xs whitespace-nowrap">
                                    {{ $row['amount'] }} {{ $row['currency'] }}
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-xs whitespace-nowrap {{ $row['fee_charged'] ? 'text-amber-300' : 'text-slate-600' }}">
                                    {{ $row['fee'] }}
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    @if ($row['status'] === 'completed')
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">{{ $row['status_label'] }}</span>
                                    @elseif ($row['status'] === 'failed')
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-rose-500/10 text-rose-400 border border-rose-500/30">{{ $row['status_label'] }}</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-500/10 text-slate-400 border border-slate-500/30">{{ $row['status_label'] }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.transfers.show', ['reference' => $row['reference']]) }}"
                                       class="text-xs font-bold text-emerald-400 hover:text-emerald-300 hover:underline">
                                        {{ __('transfer.desk.view') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($page->lastPage() > 1)
                <div class="pt-2">
                    {{ $page->links('vendor.pagination.tailwind') }}
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
