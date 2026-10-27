<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit="form">
            {{ $this->form }}
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr>
                        <th class="p-2">Draw</th>
                        <th class="p-2">Notifications</th>
                        <th class="p-2">Delivered</th>
                        <th class="p-2">Failed</th>
                        <th class="p-2">Pending</th>
                        <th class="p-2">Push not configured</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getRows() as $row)
                        <tr class="border-b">
                            <td class="p-2 font-mono">{{ $row['draw_number'] }}</td>
                            <td class="p-2">{{ $row['notification_count'] }}</td>
                            <td class="p-2">{{ $row['delivered'] }}</td>
                            <td class="p-2">{{ $row['failed'] }}</td>
                            <td class="p-2">{{ $row['pending'] }}</td>
                            <td class="p-2">{{ $row['provider_not_configured'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-2" colspan="6">No result-notification deliveries recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs opacity-70">
            Push provider is NOT_CONFIGURED unless a driver is wired; in-app/database delivery still works.
            Failed rows are retried by the glo:notify-saved-tickets worker — never double-fired from this panel.
        </p>
    </div>
</x-filament-panels::page>
