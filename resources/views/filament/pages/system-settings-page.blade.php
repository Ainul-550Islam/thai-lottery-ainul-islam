<x-filament-panels::page>
    <div class="space-y-6">

        <x-filament::section>
            <x-slot name="heading">How this store works</x-slot>
            <x-slot name="description">
                These are <strong>overrides</strong>, not the configuration itself. A value saved here is
                stored in the <code>system_settings</code> table and merged into the application config on
                every boot, so the lanes that read configuration pick it up without a deployment. A key
                that is not whitelisted in code cannot be written, no secret can ever be stored here, and
                every change is audited with its previous value at High risk.
            </x-slot>

            @php($overrides = $this->overrides())

            <div class="flex flex-wrap gap-2 text-sm">
                <x-filament::badge color="gray">{{ count($overrides) }} override(s) in force</x-filament::badge>
                @unless ($this->canManageSettings())
                    <x-filament::badge color="warning">read only — 'settings.manage' is required to change a value</x-filament::badge>
                @endunless
            </div>

            @if ($overrides !== [])
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="p-2">Key</th>
                                <th class="p-2">Stored value</th>
                                <th class="p-2">Deployed default</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overrides as $row)
                                <tr class="border-b">
                                    <td class="p-2 font-mono text-xs">{{ $row['key'] }}</td>
                                    <td class="p-2 font-semibold">{{ $row['value'] }}</td>
                                    <td class="p-2 opacity-70">{{ $row['default'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <form wire:submit.prevent="save">
            {{ $this->form }}

            @if ($this->canManageSettings())
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <x-filament::button type="submit" icon="heroicon-o-check">
                        Save changed settings
                    </x-filament::button>

                    <span class="text-xs opacity-70">
                        Only values that differ from what is in force are written, so the audit trail
                        stays readable.
                    </span>
                </div>
            @endif
        </form>

    </div>
</x-filament-panels::page>
