<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit="form">
            {{ $this->form }}
        </form>

        @if (! AdminAccess::current(\App\Support\Admin\AdminAccess::REVIEW_GLO_FREEZES))
            <div class="rounded-lg bg-warning-50 p-4 text-sm text-warning-700">
                Read-only view: your account cannot transition freeze cases.
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="p-2">Case</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Ticket</th>
                        <th class="p-2">Case ref</th>
                        <th class="p-2">Evidence ref</th>
                        <th class="p-2">Expiry</th>
                        <th class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getFreezes() as $freeze)
                        <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="fz-{{ $freeze['id'] }}">
                            <td class="p-2 font-mono text-xs">{{ $freeze['freeze_case_id'] }}</td>
                            <td class="p-2">
                                <span class="badge-{{ $freeze['color'] }}">{{ $freeze['status_label'] }}</span>
                            </td>
                            <td class="p-2 font-mono text-xs">{{ $freeze['ticket_reference'] }}</td>
                            <td class="p-2">{{ $freeze['case_reference'] }}</td>
                            <td class="p-2 text-xs">{{ $freeze['evidence_reference'] }}</td>
                            <td class="p-2 text-xs">{{ $freeze['expiry_at'] ?? '—' }}</td>
                            <td class="p-2 space-x-1">
                                @if ($freeze['can_review'])
                                    <x-filament::button size="xs" wire:click="transition('{{ $freeze['freeze_case_id'] }}', 'review')">
                                        Review
                                    </x-filament::button>
                                @endif
                                @if ($freeze['can_approve'])
                                    <x-filament::button size="xs" color="danger" wire:click="transition('{{ $freeze['freeze_case_id'] }}', 'approve')">
                                        Freeze
                                    </x-filament::button>
                                @endif
                                @if ($freeze['can_release'])
                                    <x-filament::button size="xs" color="success" wire:click="transition('{{ $freeze['freeze_case_id'] }}', 'release')">
                                        Release
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-4 text-center text-gray-500">No freeze cases.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
