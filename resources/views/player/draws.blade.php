@extends('layouts.app')

@section('title', __('player.draws_title'))
@section('meta_description', __('player.draws_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="tl-v2-eyebrow">{{ __('player.nav_draws') }}</span>
            <h1 class="mt-1 text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">{{ __('player.draws_title') }}</h1>
            <p class="text-[#D4CAB3] text-sm mt-1">{{ __('player.draws_lead') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('player.bet') }}" class="tl-v2-btn-gold text-xs">
                {{ __('player.enter_bet_slip') }}
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($draws as $draw)
            <div class="tl-v2-card flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="font-mono font-bold text-[#FFF6D6] text-base">{{ $draw->draw_number }}</span>
                        @php
                            $status = $draw->status->value ?? (string) $draw->status;
                        @endphp
                        @if($status === 'open')
                            <span class="tl-v2-pill tl-v2-pill--verified">{{ __('player.status_open') }}</span>
                        @elseif($status === 'completed' || $status === 'result_published')
                            <span class="tl-v2-pill tl-v2-pill--neutral">{{ __('player.status_settled') }}</span>
                        @else
                            <span class="tl-v2-pill tl-v2-pill--configured">{{ $status }}</span>
                        @endif
                    </div>

                    <div class="text-xs text-[#D4CAB3] space-y-2 mb-6">
                        <div class="flex justify-between gap-2">
                            <span class="text-[#9D937D]">{{ __('player.scheduled_label') }}</span>
                            <span class="text-[#FAF6EC] font-mono">{{ $draw->scheduled_at?->format('M d, Y H:i') ?? __('player.not_recorded') }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="text-[#9D937D]">{{ __('player.closes_label') }}</span>
                            <span class="text-[#FAF6EC] font-mono">{{ $draw->betting_close_at?->format('M d, Y H:i') ?? __('player.not_recorded') }}</span>
                        </div>
                    </div>

                    @if($draw->result)
                        <div class="tl-v2-subcard mb-4 text-center">
                            <span class="text-[11px] text-[#9D937D] uppercase font-bold tracking-wider block mb-1">{{ __('player.first_prize_6') }}</span>
                            <div class="text-2xl font-black font-mono text-[#FFF6D6] tracking-wider" data-tabular-nums>
                                {{ $draw->result->first_prize }}
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3 pt-3 border-t border-[#D4AF37]/15 text-xs">
                                <div>
                                    <span class="text-[11px] text-[#9D937D] block">{{ __('player.digit_3d_top_short') }}</span>
                                    <span class="font-bold font-mono text-[#F5E6B8]" data-tabular-nums>{{ $draw->result->three_digits_top }}</span>
                                </div>
                                <div>
                                    <span class="text-[11px] text-[#9D937D] block">{{ __('player.digit_2d_bottom_short') }}</span>
                                    <span class="font-bold font-mono text-[#F5E6B8]" data-tabular-nums>{{ $draw->result->two_digits_bottom }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <a href="{{ route('player.draws.detail', $draw->id) }}" class="tl-v2-btn-outline w-full text-xs">
                    {{ __('player.view_draw_breakdown') }} &rarr;
                </a>
            </div>
        @empty
            <div class="col-span-full py-12 text-center text-[#D4CAB3]">
                {{ __('player.no_draws_available') }}
            </div>
        @endforelse
    </div>

    @if(is_object($draws) && method_exists($draws, 'links'))
        <div class="pt-4">
            {{ $draws->links() }}
        </div>
    @endif
</div>
@endsection
