<x-filament-panels::page>
    <div class="space-y-6">

        <form wire:submit.prevent="generate">
            {{ $this->form }}

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray">
                    Run and download
                </x-filament::button>

                <span class="text-xs opacity-70">
                    A report is frozen and executed through OperationalReportService; the file is
                    sealed with a checksum before it is handed to you.
                </span>
            </div>
        </form>

        <x-filament::section>
            <x-slot name="heading">Your recent report jobs</x-slot>
            <x-slot name="description">
                Only your own asks are listed: a job carries the filters it was run with, and those
                filters can name individual players.
            </x-slot>

            @php($jobs = $this->recentJobs())

            @if ($jobs === [])
                <p class="text-sm opacity-70">No reports run yet from this panel.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="p-2">Family</th>
                                <th class="p-2">Horizon</th>
                                <th class="p-2">Status</th>
                                <th class="p-2">Rows</th>
                                <th class="p-2">Asked</th>
                                <th class="p-2">Fingerprint</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($jobs as $job)
                                <tr class="border-b">
                                    <td class="p-2">{{ $job['type'] }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $job['horizon'] }}</td>
                                    <td class="p-2">{{ $job['status'] }}</td>
                                    <td class="p-2">{{ $job['rows'] }}</td>
                                    <td class="p-2">{{ $job['asked'] }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $job['fingerprint'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
