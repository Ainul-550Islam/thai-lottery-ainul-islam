<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit="form">
            {{ $this->form }}
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="p-2">Claim</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Ticket</th>
                        <th class="p-2">Category</th>
                        <th class="p-2">Gross</th>
                        <th class="p-2">Duty</th>
                        <th class="p-2">Net</th>
                        <th class="p-2">Hold</th>
                        <th class="p-2">Age</th>
                        <th class="p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getClaims() as $claim)
                        <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="clm-{{ $claim['id'] }}">
                            <td class="p-2 font-mono text-xs">{{ $claim['claim_reference'] }}</td>
                            <td class="p-2">
                                <span class="badge-{{ $claim['color'] }}">{{ $claim['status_label'] }}</span>
                            </td>
                            <td class="p-2 font-mono text-xs">{{ $claim['ticket_number'] }}</td>
                            <td class="p-2">{{ $claim['prize_category'] }}</td>
                            <td class="p-2 font-mono">{{ $claim['gross_prize'] }}</td>
                            <td class="p-2 font-mono">{{ $claim['stamp_duty'] }}</td>
                            <td class="p-2 font-mono">{{ $claim['net_prize'] }}</td>
                            <td class="p-2 text-xs">
                                @if ($claim['hold_status'] !== 'none')
                                    <span class="text-danger-600">{{ $claim['hold_reason'] ?? $claim['hold_status'] }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-2 text-xs">
                                {{ $claim['age_verification_result'] }}
                                @if ($claim['verified_age_years'] !== null)
                                    ({{ $claim['verified_age_years'] }})
                                @endif
                            </td>
                            <td class="p-2 space-x-1">
                                @if ($claim['can_review'])
                                    <x-filament::button size="xs" wire:click="act('{{ $claim['claim_reference'] }}', 'review')">
                                        Eligible
                                    </x-filament::button>
                                @endif
                                @if ($claim['can_approve'])
                                    <x-filament::button size="xs" color="success" wire:click="act('{{ $claim['claim_reference'] }}', 'approve')">
                                        Approve
                                    </x-filament::button>
                                @endif
                                @if ($claim['can_pay'])
                                    <x-filament::button size="xs" color="danger" wire:click="act('{{ $claim['claim_reference'] }}', 'pay')">
                                        Pay
                                    </x-filament::button>
                                @endif
                                @if ($claim['can_reject'])
                                    <x-filament::button size="xs" color="gray" wire:click="act('{{ $claim['claim_reference'] }}', 'reject')">
                                        Reject
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-4 text-center text-gray-500">No claims.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500">
            Payment requires APPROVED status with no active freeze or payment hold, verified DOB age ≥ 20,
            verified identity, unexpired claim window and a published official result with final settlement.
            Expired freezes never auto-pay.
        </p>
    </div>
</x-filament-panels::page>
