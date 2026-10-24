@extends('layouts.app')

{{--
    Player balance-transfer history (/wallet/transfers).

    WHY THIS FILE EXISTS
    BalanceTransferController::index() renders `player.transfers` for a browser
    request and returns the JSON envelope only for an XHR/API client. The view
    was never written, so the "View all" link inside the transfer panel led to a
    "View [player.transfers] not found" error page - the one screen a player
    reaches immediately after moving money.

    WHAT THIS VIEW IS ALLOWED TO KNOW
    It renders exactly what the controller already computed and passed:
      $transfers       list of BalanceTransfer::toPlayerArray() projections
      $page            LengthAwarePaginator (already filtered, already paginated)
      $meta            page / per_page / total / last_page / filters
      $activeDirection ?TransferDirection   $activeStatus ?TransferStatus
      $activeFilters   filters to carry forward into pagination links
      $currency        display currency code

    NO TRANSACTION LOGIC LIVES HERE. The page has no submit button that could
    move money, no optimistic "transfer complete" banner, and no client-side
    fetch: it is a read-only list. A player who wants to transfer uses the panel
    on the wallet page, which posts to the endpoint that owns that decision.
--}}

@section('title', __('transfer.history_page.title'))
@section('meta_description', __('transfer.history_page.lead'))
{{-- A private account ledger: never indexed, never cached by a shared proxy. --}}
@section('meta_robots', 'noindex,nofollow')

@section('content')
@php
    $transfers = is_array($transfers ?? null) ? $transfers : [];
    $rows = array_values(array_filter($transfers, static fn ($row): bool => is_array($row)));
    $meta = is_array($meta ?? null) ? $meta : [];
    $currency = (string) ($currency ?? 'THB');

    $total = (int) ($meta['total'] ?? count($rows));
    $currentPage = (int) ($meta['page'] ?? 1);
    $lastPage = (int) ($meta['last_page'] ?? 1);
    $perPage = (int) ($meta['per_page'] ?? count($rows));

    $activeFilters = is_array($activeFilters ?? null) ? $activeFilters : [];

    // The controller always supplies these two; defaulting here means a future
    // caller that passes only the list cannot turn the page into an
    // "undefined variable" error.
    $activeDirection = $activeDirection ?? null;
    $activeStatus = $activeStatus ?? null;

    $walletUrl = \Illuminate\Support\Facades\Route::has('player.wallet') ? route('player.wallet') : url('/wallet');
    $historyUrl = \Illuminate\Support\Facades\Route::has('player.transfer.index') ? route('player.transfer.index') : url('/wallet/transfers');

    // Direction is shown as "Cash -> Win". The arrow is a translated, aria-hidden
    // separator: a screen reader hears the pocket names, not "hyphen greater than".
    $directionLabels = [
        'cash_to_win' => __('transfer.direction_cash_to_win'),
        'win_to_cash' => __('transfer.direction_win_to_cash'),
    ];

    // Server-side translation of the status word. The model already carries an
    // English label; this map keeps a Thai or Bangla player from reading the
    // English one.
    $statusLabels = [
        'pending' => __('transfer.history_status_pending'),
        'completed' => __('transfer.history_status_completed'),
        'failed' => __('transfer.history_status_failed'),
        'reversed' => __('transfer.history_status_reversed'),
    ];

    // A filter that excludes everything is a different situation from an account
    // with no transfers at all, and saying so is the whole point of the two
    // empty states below.
    $hasFilters = $activeFilters !== [];
@endphp

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h1 id="transfers-title" class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                {{ __('transfer.history_page.title') }}
            </h1>
            <p class="text-slate-400 text-sm mt-1">{{ __('transfer.history_page.lead') }}</p>
        </div>

        <a
            href="{{ $walletUrl }}"
            class="inline-flex items-center gap-2 self-start rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm font-semibold text-slate-200 transition hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400 motion-reduce:transition-none"
        >{{ __('transfer.history_page.back_to_wallet') }}</a>
    </div>

    {{--
        Server-side validation / refusal feedback.

        This page only reads, so nothing here validates today. The block stays
        because a GET form still round-trips: if a future filter rule starts
        rejecting a value and redirects back with errors, the message must be
        visible instead of silently dropped. It is rendered only when errors
        exist - there is no permanently empty red box.
    --}}
    @if (isset($errors) && $errors->any())
        <div
            class="rounded-2xl border border-rose-500/40 bg-rose-950/30 px-4 py-3 text-sm text-rose-100"
            role="alert"
        >
            <p class="font-bold">{{ __('transfer.history_page.errors_heading') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Filters ------------------------------------------------------------}}
    <form
        method="GET"
        action="{{ $historyUrl }}"
        class="rounded-3xl border border-slate-800 bg-slate-900 p-5 shadow-xl sm:p-6"
        aria-labelledby="transfer-filters-title"
    >
        <h2 id="transfer-filters-title" class="sr-only">{{ __('transfer.history_page.filters_title') }}</h2>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="filter-direction" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                    {{ __('transfer.history_page.filter_direction') }}
                </label>
                <select
                    id="filter-direction"
                    name="direction"
                    class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                >
                    <option value="">{{ __('transfer.history_page.filter_all_directions') }}</option>
                    @foreach ($directionLabels as $value => $label)
                        <option value="{{ $value }}" @selected($activeDirection !== null && $activeDirection->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1">
                <label for="filter-status" class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                    {{ __('transfer.history_page.filter_status') }}
                </label>
                <select
                    id="filter-status"
                    name="status"
                    class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                >
                    <option value="">{{ __('transfer.history_page.filter_all_statuses') }}</option>
                    @foreach (['pending', 'completed', 'failed'] as $value)
                        <option value="{{ $value }}" @selected($activeStatus !== null && $activeStatus->value === $value)>{{ $statusLabels[$value] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="submit"
                    class="rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-bold text-slate-950 shadow transition hover:bg-amber-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300 motion-reduce:transition-none"
                >{{ __('transfer.history_page.filter_submit') }}</button>

                @if ($hasFilters)
                    <a
                        href="{{ $historyUrl }}"
                        class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400 motion-reduce:transition-none"
                    >{{ __('transfer.history_page.filter_reset') }}</a>
                @endif
            </div>
        </div>

        {{-- Keeps an explicit page size when the player filtered a paginated
             list, so applying a filter does not silently drop back to the
             default page length. --}}
        @if (isset($activeFilters['per_page']))
            <input type="hidden" name="per_page" value="{{ (string) $activeFilters['per_page'] }}">
        @endif
    </form>

    {{-- History ------------------------------------------------------------}}
    <div class="rounded-3xl border border-slate-800 bg-slate-900 p-5 shadow-xl sm:p-6">
        {{-- Summary line. aria-live so a filtered result is announced rather than
             only being visible. It states counts the server computed, never an
             estimate. --}}
        <p class="mb-4 text-xs text-slate-500" role="status" aria-live="polite">
            @if ($total > 0)
                {{ trans_choice('transfer.history_page.summary', $total, ['count' => $total]) }}
                @if ($lastPage > 1)
                    <span class="ml-2">{{ __('transfer.history_page.page_of', ['page' => $currentPage, 'pages' => $lastPage]) }}</span>
                @endif
            @endif
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ __('transfer.history_page.table_caption') }}</caption>
                <thead>
                    <tr class="border-b border-slate-800 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        <th scope="col" class="pb-3 pr-4">{{ __('transfer.history_reference') }}</th>
                        <th scope="col" class="pb-3 pr-4">{{ __('transfer.history_page.col_date') }}</th>
                        <th scope="col" class="pb-3 pr-4">{{ __('transfer.history_direction') }}</th>
                        <th scope="col" class="pb-3 pr-4 text-right">{{ __('transfer.history_amount') }}</th>
                        <th scope="col" class="pb-3 pr-4 text-right">{{ __('transfer.history_fee') }}</th>
                        <th scope="col" class="pb-3 text-right">{{ __('transfer.history_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/70">
                    @forelse ($rows as $row)
                        @php
                            $rowDirection = (string) ($row['direction'] ?? '');
                            $rowDirectionLabel = $directionLabels[$rowDirection] ?? (string) ($row['direction_label'] ?? $rowDirection);
                            $rowStatus = (string) ($row['status'] ?? '');
                            $rowStatusLabel = $statusLabels[$rowStatus] ?? (string) ($row['status_label'] ?? $rowStatus);
                            $rowCurrency = (string) ($row['currency'] ?? $currency);

                            $rowStatusColor = match ($rowStatus) {
                                'completed' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                'failed' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                'reversed' => 'bg-slate-500/10 text-slate-300 border-slate-500/30',
                                default => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                            };

                            // Timestamps travel as ISO-8601 strings. Formatted for
                            // display only; the raw value is never re-parsed as a
                            // number or used in arithmetic.
                            $rowCreatedAt = isset($row['created_at']) && is_string($row['created_at'])
                                ? str_replace('T', ' ', substr($row['created_at'], 0, 16))
                                : null;
                        @endphp
                        <tr class="transition hover:bg-slate-800/30 motion-reduce:transition-none">
                            <th scope="row" class="py-3.5 pr-4 font-mono text-xs font-bold text-slate-200">
                                {{ (string) ($row['reference'] ?? __('player.not_recorded')) }}
                            </th>
                            <td class="py-3.5 pr-4 font-mono text-xs text-slate-500">
                                {{ $rowCreatedAt ?? __('player.not_recorded') }}
                            </td>
                            <td class="py-3.5 pr-4 text-xs text-slate-300">{{ $rowDirectionLabel }}</td>
                            <td class="py-3.5 pr-4 text-right font-mono text-xs font-bold text-slate-100">
                                {{ (string) ($row['amount'] ?? '0.00') }} {{ $rowCurrency }}
                            </td>
                            <td class="py-3.5 pr-4 text-right font-mono text-xs text-slate-400">
                                {{ (string) ($row['fee'] ?? '0.00') }}
                            </td>
                            <td class="py-3.5 text-right">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold {{ $rowStatusColor }}">
                                    {{ $rowStatusLabel }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-xs text-slate-500">
                                {{ $hasFilters ? __('transfer.history_page.empty_filtered') : __('transfer.history_empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination. appends() carries the resolved filters forward; Laravel's
             paginator builds links from appends() alone and would otherwise drop
             them, handing the player page 2 of a list they filtered. --}}
        @if (isset($page) && method_exists($page, 'links') && $lastPage > 1)
            <nav class="pt-6" aria-label="{{ __('transfer.history_page.pagination') }}">
                {{ $page->appends($activeFilters)->links() }}
            </nav>
        @endif

        <p class="mt-4 text-[11px] text-slate-600">
            {{ trans_choice('transfer.history_page.rows_per_page', max($perPage, 1), ['count' => $perPage]) }}
        </p>
    </div>
</div>
@endsection
