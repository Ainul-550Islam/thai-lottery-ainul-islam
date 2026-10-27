{{--
    Operator result entry.

    TWO FORMS, TWO PERMISSIONS, TWO PEOPLE. The left form records numbers and
    withholds them. The right form releases a stored version and refuses to do so
    for the person who recorded it. They are rendered as separate panels with
    separate submit buttons because a single "save and publish" control would undo
    the entire control.

    NO NUMBERS ARE RENDERED FROM THE REQUEST. The queue below is built from the
    service's own projection of stored rows. Nothing in this template reads
    request input into the page.
--}}
<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-semibold">Publication requires two people.</p>
            <p class="mt-1">
                A result entered here is stored and withheld. It becomes public only after a
                different operator approves it with a written reason. The importer can never
                approve their own entry, and a published result is never edited — a correction
                is a new version that supersedes the old one.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- ── Operator A: record ─────────────────────────────────── --}}
            <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h2 class="text-base font-semibold">1 — Record a result</h2>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    Stored as an internal reconciliation. Never labelled official: a person
                    typing numbers is not the government source.
                </p>

                <div class="mt-4 space-y-3">
                    <label class="block">
                        <span class="text-sm font-medium">Lane</span>
                        <select
                            wire:model.live="lane"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                        >
                            @foreach ($lanes as $key => $laneState)
                                <option value="{{ $key }}" @disabled(! $laneState['available'])>
                                    {{ ucfirst($key) }}{{ $laneState['available'] ? '' : ' — unavailable' }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    @if (! ($lanes[$lane]['available'] ?? false))
                        <p class="rounded border border-red-300 bg-red-50 p-2 text-xs text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-100">
                            Manual entry is not publishable in this lane: the operator provider
                            label is not in the lane's source priority list, so any entry would be
                            stored but could never be released.
                        </p>
                    @endif

                    {{-- Field names are the lane's own declared result columns. --}}
                    @foreach (['draw_date', 'first_prize', 'second_prizes', 'third_prizes'] as $field)
                        <label class="block" wire:key="field-{{ $lane }}-{{ $field }}">
                            <span class="text-sm font-medium">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                            <input
                                type="text"
                                wire:model="fields.{{ $field }}"
                                autocomplete="off"
                                spellcheck="false"
                                inputmode="{{ str_contains($field, 'prize') ? 'numeric' : 'text' }}"
                                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-800"
                            >
                        </label>
                    @endforeach

                    <button
                        type="button"
                        wire:click="submit"
                        wire:loading.attr="disabled"
                        @disabled(! $canSubmit)
                        class="rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Store and withhold
                    </button>

                    @unless ($canSubmit)
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            You need the “manage draws” permission and a non read-only session to record a result.
                        </p>
                    @endunless
                </div>
            </section>

            {{-- ── Operator B: approve ────────────────────────────────── --}}
            <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h2 class="text-base font-semibold">2 — Approve and publish</h2>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    A written reason is required and is stored with the version. Your identity is
                    recorded; if you are the importer the approval will be refused.
                </p>

                <div class="mt-4 space-y-3">
                    <label class="block">
                        <span class="text-sm font-medium">Pending version</span>
                        <select
                            wire:model="selectedVersionId"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                        >
                            <option value="">— select —</option>
                            @foreach ($pending as $row)
                                <option value="{{ $row['version_id'] }}">
                                    {{ $row['draw_reference'] }} · v{{ $row['version_number'] }} ·
                                    {{ $row['provider'] }} · {{ $row['source_state'] }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">Reason</span>
                        <textarea
                            wire:model="approvalReason"
                            rows="3"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                        ></textarea>
                        <span class="mt-1 block text-xs text-gray-600 dark:text-gray-400">
                            Say what you checked and where the numbers came from. This is the record
                            that answers a later dispute.
                        </span>
                    </label>

                    <button
                        type="button"
                        wire:click="approve"
                        wire:loading.attr="disabled"
                        @disabled(! $canApprove)
                        class="rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Approve and publish
                    </button>

                    @unless ($canApprove)
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            You need the “process draws” permission and a non read-only session to approve.
                        </p>
                    @endunless
                </div>
            </section>
        </div>

        {{-- ── The queue ──────────────────────────────────────────────── --}}
        <section class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h2 class="text-base font-semibold">
                Awaiting a second signature — {{ ucfirst($lane) }}
            </h2>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            <th class="p-2">Draw</th>
                            <th class="p-2">Version</th>
                            <th class="p-2">Provider</th>
                            <th class="p-2">Source state</th>
                            <th class="p-2">Result state</th>
                            <th class="p-2">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pending as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="pending-{{ $row['version_id'] }}">
                                <td class="p-2 font-mono text-xs">{{ $row['draw_reference'] }}</td>
                                <td class="p-2">v{{ $row['version_number'] }}</td>
                                <td class="p-2">{{ $row['provider'] }}</td>
                                <td class="p-2 text-xs">{{ $row['source_state'] }}</td>
                                <td class="p-2 text-xs">{{ $row['result_status'] }}</td>
                                <td class="p-2 text-xs">{{ $row['imported_at'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-3 text-sm text-gray-600 dark:text-gray-400">
                                    Nothing is waiting for a second signature in this lane.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
