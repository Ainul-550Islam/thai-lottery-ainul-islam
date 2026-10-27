<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit="form">
            {{ $this->form }}
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr>
                        <th class="p-2">Code</th>
                        <th class="p-2">Dealer</th>
                        <th class="p-2">Name</th>
                        <th class="p-2">Province</th>
                        <th class="p-2">District</th>
                        <th class="p-2">Lat/Lng</th>
                        <th class="p-2">Verification</th>
                        <th class="p-2">Source</th>
                        <th class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getPoints() as $row)
                        <tr class="border-b">
                            <td class="p-2 font-mono">{{ $row['sales_point_code'] }}</td>
                            <td class="p-2">#{{ $row['dealer_id'] }}</td>
                            <td class="p-2">{{ $row['display_name'] }}</td>
                            <td class="p-2">{{ $row['province'] }}</td>
                            <td class="p-2">{{ $row['district'] }}</td>
                            <td class="p-2 text-xs">{{ $row['latitude'] }}, {{ $row['longitude'] }}</td>
                            <td class="p-2">{{ $row['verification_state'] }}</td>
                            <td class="p-2 text-xs">{{ $row['source'] }} / {{ $row['source_state'] }}</td>
                            <td class="p-2">
                                @if ($row['verification_state'] !== 'verified')
                                    <x-filament::button
                                        size="xs"
                                        color="success"
                                        wire:click="verify('{{ $row['sales_point_code'] }}')"
                                    >Verify</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-2" colspan="9">No sales points.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
