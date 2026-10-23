@extends('layouts.app')

@section('title', __('player.profile_title').' — '.config('app.name', 'Thai Lottery'))
@section('meta_robots', 'noindex, nofollow')

@section('content')
<div class="max-w-4xl mx-auto space-y-8">
    <div>
        <span class="tl-v2-eyebrow">{{ __('player.nav_profile') }}</span>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">{{ __('player.profile_title') }}</h1>
        <p class="text-[#D4CAB3] text-sm mt-1">{{ __('player.profile_lead') }}</p>
    </div>

    @if (session('success'))
        <div class="p-4 bg-emerald-950/65 border border-emerald-500/45 text-emerald-200 rounded-2xl text-sm" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 bg-rose-950/65 border border-rose-500/45 text-rose-200 rounded-2xl text-sm" role="alert">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="p-4 bg-rose-950/65 border border-rose-500/45 text-rose-200 rounded-2xl text-sm space-y-1" role="alert">
            @foreach ($errors->all() as $error)
                <p>• {{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="space-y-6">
            <div class="tl-v2-card text-center">
                <div class="w-20 h-20 bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 rounded-full flex items-center justify-center font-black text-2xl mx-auto mb-3">
                    {{ $user->name !== null && $user->name !== '' ? strtoupper(substr($user->name, 0, 1)) : ($user->username !== null && $user->username !== '' ? strtoupper(substr($user->username, 0, 1)) : __('player.not_recorded')) }}
                </div>
                <h2 class="text-lg font-extrabold text-[#FFF6D6]">{{ $user->name ?? $user->username ?? __('player.not_recorded') }}</h2>
                <p class="text-xs text-[#D4CAB3] font-mono mt-0.5 break-all">{{ $user->email ?? __('player.not_recorded') }}</p>
                <div class="mt-5 pt-4 border-t border-[#D4AF37]/15 flex flex-col gap-2.5 text-sm">
                    <a href="{{ route('account.verification') }}" class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">{{ __('player.link_account_verification') }}</a>
                    <a href="{{ route('account.grade') }}" class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">{{ __('player.link_account_grade') }}</a>
                    <a href="{{ route('fees') }}" class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">{{ __('player.link_fees') }}</a>
                </div>
            </div>
        </div>

        <div class="lg:col-span-2 space-y-6">
            <div class="tl-v2-card">
                <h2 class="text-base font-extrabold text-[#FFF6D6] mb-4">{{ __('player.personal_details') }}</h2>
                <form method="POST" action="{{ route('player.profile.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="profile-name" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.full_name') }}</label>
                            <input id="profile-name" type="text" name="name" value="{{ old('name', $user->name ?? '') }}" required
                                   class="tl-v2-input">
                        </div>
                        <div>
                            <label for="profile-phone" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.phone_number') }}</label>
                            <input id="profile-phone" type="text" name="phone" value="{{ old('phone', $user->phone ?? '') }}"
                                   class="tl-v2-input font-mono">
                        </div>
                    </div>
                    <div class="pt-2 text-right">
                        <button type="submit" class="tl-v2-btn-gold text-xs">
                            {{ __('player.save_changes') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="tl-v2-card">
                <h2 class="text-base font-extrabold text-[#FFF6D6] mb-4">{{ __('player.change_password') }}</h2>
                <form method="POST" action="{{ route('player.password.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="profile-current-password" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.current_password') }}</label>
                            <input id="profile-current-password" type="password" name="current_password" autocomplete="current-password" required
                                   class="tl-v2-input">
                        </div>
                        <div>
                            <label for="profile-new-password" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.new_password') }}</label>
                            <input id="profile-new-password" type="password" name="new_password" autocomplete="new-password" required
                                   class="tl-v2-input">
                        </div>
                    </div>
                    <div class="pt-2 text-right">
                        <button type="submit" class="tl-v2-btn-gold text-xs">
                            {{ __('player.save_password') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="tl-v2-card">
                <div class="mb-4">
                    <h2 class="text-base font-extrabold text-[#FFF6D6]">{{ __('player.responsible_gaming_title') }}</h2>
                    <p class="text-xs text-[#D4CAB3] mt-0.5">{{ __('player.responsible_gaming_lead') }}</p>
                </div>
                <form method="POST" action="{{ route('player.limits.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="limit-daily-deposit" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.daily_deposit_limit') }}</label>
                            <input id="limit-daily-deposit" type="number" step="0.01" min="0" name="daily_deposit_limit"
                                   value="{{ old('daily_deposit_limit', $limits?->daily_deposit_limit ?? '') }}"
                                   class="tl-v2-input font-mono">
                        </div>
                        <div>
                            <label for="limit-single-bet" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.single_bet_limit') }}</label>
                            <input id="limit-single-bet" type="number" step="0.01" min="0" name="single_bet_limit"
                                   value="{{ old('single_bet_limit', $limits?->single_bet_limit ?? '') }}"
                                   class="tl-v2-input font-mono">
                        </div>
                        <div>
                            <label for="limit-daily-wagering" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-1.5">{{ __('player.daily_wagering_limit') }}</label>
                            <input id="limit-daily-wagering" type="number" step="0.01" min="0" name="daily_wagering_limit"
                                   value="{{ old('daily_wagering_limit', $limits?->daily_wagering_limit ?? '') }}"
                                   class="tl-v2-input font-mono">
                        </div>
                    </div>
                    <p class="text-xs text-[#9D937D]">{{ __('player.limits_optional_hint') }}</p>
                    <div class="pt-2 text-right">
                        <button type="submit" class="tl-v2-btn-gold text-xs">
                            {{ __('player.save_limits') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
