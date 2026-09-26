<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Current verified result --}}
        <section>
            <h2 class="text-lg font-semibold mb-2">Current result</h2>
            @if ($this->getCurrent()['available'] ?? false)
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 p-3 rounded overflow-x-auto">{{ json_encode($this->getCurrent()['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                <p class="text-sm opacity-70">{{ $this->getCurrent()['message'] ?? 'No verified result available.' }}</p>
            @endif
        </section>

        {{-- Provider health (honest source states) --}}
        <section>
            <h2 class="text-lg font-semibold mb-2">Provider health</h2>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
                @foreach ($this->getProviderHealth() as $key => $value)
                    <div class="border rounded p-2">
                        <div class="text-xs opacity-60">{{ $key }}</div>
                        <div class="font-mono">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- History / publication status --}}
        <section>
            <h2 class="text-lg font-semibold mb-2">Result history (latest 20)</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 dark:bg-gray-800">
                        <tr>
                            <th class="p-2">Draw</th>
                            <th class="p-2">First prize</th>
                            <th class="p-2">Published</th>
                            <th class="p-2">Source version</th>
                            <th class="p-2">Provider</th>
                            <th class="p-2">Source state</th>
                            <th class="p-2">Publication</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->getHistory() as $row)
                            <tr class="border-b">
                                <td class="p-2 font-mono">{{ $row['draw_number'] }}</td>
                                <td class="p-2 font-mono">{{ $row['first_prize'] }}</td>
                                <td class="p-2">{{ $row['published_at'] }}</td>
                                <td class="p-2 text-xs font-mono">{{ $row['source_version'] }}</td>
                                <td class="p-2">{{ $row['import_provider'] }}</td>
                                <td class="p-2 text-xs">{{ $row['source_state'] }}</td>
                                <td class="p-2">{{ $row['publication_status'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="p-2" colspan="7">No published results yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
