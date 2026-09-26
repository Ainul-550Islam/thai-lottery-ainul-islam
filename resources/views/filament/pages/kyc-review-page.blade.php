<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit="form">
            {{ $this->form }}
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr>
                        <th class="p-2">ID</th>
                        <th class="p-2">User</th>
                        <th class="p-2">Type</th>
                        <th class="p-2">Number (masked)</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Submitted</th>
                        <th class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getDocuments() as $row)
                        <tr class="border-b">
                            <td class="p-2 font-mono">{{ $row['id'] }}</td>
                            <td class="p-2">#{{ $row['user_id'] }}</td>
                            <td class="p-2">{{ $row['document_type'] }}</td>
                            <td class="p-2 font-mono">{{ $row['document_number_masked'] }}</td>
                            <td class="p-2">{{ $row['status'] }}</td>
                            <td class="p-2">{{ $row['submitted_at'] }}</td>
                            <td class="p-2 space-x-1">
                                @if ($row['status'] === 'pending' || $row['status'] === 'under_review' || $row['status'] === 'unverified')
                                    @if (! $row['is_self'])
                                        <x-filament::button size="xs" color="success"
                                            wire:click="approve({{ $row['id'] }})">Approve</x-filament::button>
                                        <x-filament::button size="xs" color="danger"
                                            wire:click="reject({{ $row['id'] }})">Reject</x-filament::button>
                                        <x-filament::button size="xs" color="warning"
                                            wire:click="requestResubmission({{ $row['id'] }})">Resubmit</x-filament::button>
                                    @else
                                        <span class="text-xs opacity-60">Self-review blocked</span>
                                    @endif
                                @else
                                    <span class="text-xs opacity-60">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-2" colspan="7">No KYC documents match this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs opacity-70">
            Raw document files, full national IDs, and reviewer notes are never rendered here.
            Every decision is written by KycVerificationService with an audit trail.
        </p>
    </div>
</x-filament-panels::page>
