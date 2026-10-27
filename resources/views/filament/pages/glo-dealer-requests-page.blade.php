<x-filament-panels::page>
    <div class="space-y-4" x-data="{ note: '' }">
        <form wire:submit="form">
            {{ $this->form }}
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr>
                        <th class="p-2">Reference</th>
                        <th class="p-2">Type</th>
                        <th class="p-2">Dealer</th>
                        <th class="p-2">Old</th>
                        <th class="p-2">Requested</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Timeline</th>
                        <th class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getRequests() as $row)
                        <tr class="border-b">
                            <td class="p-2 font-mono">{{ $row['request_reference'] }}</td>
                            <td class="p-2">{{ $row['request_type'] }}</td>
                            <td class="p-2">#{{ $row['dealer_id'] }}</td>
                            <td class="p-2">{{ $row['old_value'] }}</td>
                            <td class="p-2">{{ $row['requested_value'] }}</td>
                            <td class="p-2">{{ $row['status'] }}</td>
                            <td class="p-2 text-xs">{{ $row['timeline'] }}</td>
                            <td class="p-2 space-x-1">
                                @if (in_array($row['status_value'], ['submitted', 'under_review'], true))
                                    <x-filament::button
                                        size="xs"
                                        color="success"
                                        wire:click="review('{{ $row['request_reference'] }}', 'approve')"
                                    >Approve</x-filament::button>
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        wire:click="review('{{ $row['request_reference'] }}', 'reject')"
                                    >Reject</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-2" colspan="8">No dealer change requests.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
