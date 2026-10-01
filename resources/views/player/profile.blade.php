@extends('layouts.app')

@section('title', __('player.profile_title').' — '.config('app.name', 'Thai Lottery'))

@section('content')
<div class="max-w-4xl mx-auto space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ __('player.profile_title') }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('player.profile_lead') }}</p>
    </div>

    @if (session('success'))
        <div class="p-4 bg-emerald-950/60 border border-emerald-800 text-emerald-400 rounded-2xl text-sm" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 bg-rose-950/60 border border-rose-800 text-rose-400 rounded-2xl text-sm" role="alert">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="p-4 bg-rose-950/60 border border-rose-800 text-rose-400 rounded-2xl text-sm space-y-1" role="alert">
            @foreach ($errors->all() as $error)
                <p>• {{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sidebar / Summary -->
        <div class="space-y-6">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow text-center">
                <div class="w-20 h-20 bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 rounded-full flex items-center justify-center font-bold text-2xl mx-auto mb-3">
                    {{ $user->name !== null && $user->name !== '' ? strtoupper(substr($user->name, 0, 1)) : ($user->username !== null && $user->username !== '' ? strtoupper(substr($user->username, 0, 1)) : __('player.not_recorded')) }}
                </div>
                <h3 class="text-lg font-bold text-white">{{ $user->name ?? $user->username ?? __('player.not_recorded') }}</h3>
                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $user->email ?? __('player.not_recorded') }}</p>
                <div class="mt-4 flex flex-col gap-2 text-sm">
                    <a href="{{ route('account.verification') }}" class="text-emerald-400 hover:underline">{{ __('player.link_account_verification') }}</a>
                    <a href="{{ route('account.grade') }}" class="text-emerald-400 hover:underline">{{ __('player.link_account_grade') }}</a>
                    <a href="{{ route('fees') }}" class="text-emerald-400 hover:underline">{{ __('player.link_fees') }}</a>
                </div>
            </div>
        </div>

        <!-- Main Settings / Responsible Gaming -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Personal Details Form -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow">
                <h3 class="text-base font-bold text-white mb-4">{{ __('player.personal_details') }}</h3>
                <form method="POST" action="{{ route('player.profile.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.full_name') }}</label>
                            <input type="text" name="name" value="{{ old('name', $user->name ?? '') }}" required
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.phone_number') }}</label>
                            <input type="text" name="phone" value="{{ old('phone', $user->phone ?? '') }}"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                    </div>
                    <div class="pt-2 text-right">
                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition">
                            {{ __('player.save_changes') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Form -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow">
                <h3 class="text-base font-bold text-white mb-4">{{ __('player.change_password') }}</h3>
                <form method="POST" action="{{ route('player.password.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.current_password') }}</label>
                            <input type="password" name="current_password" required
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.new_password') }}</label>
                            <input type="password" name="new_password" required
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                    </div>
                    <div class="pt-2 text-right">
                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition">
                            {{ __('player.save_password') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Responsible Gaming Limits Form -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow">
                <div class="mb-4">
                    <h3 class="text-base font-bold text-white">{{ __('player.responsible_gaming_title') }}</h3>
                    <p class="text-xs text-slate-400 mt-0.5">{{ __('player.responsible_gaming_lead') }}</p>
                </div>
                <form method="POST" action="{{ route('player.limits.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.daily_deposit_limit') }}</label>
                            <input type="number" step="0.01" min="0" name="daily_deposit_limit"
                                   value="{{ old('daily_deposit_limit', $limits?->daily_deposit_limit ?? '') }}"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.single_bet_limit') }}</label>
                            <input type="number" step="0.01" min="0" name="single_bet_limit"
                                   value="{{ old('single_bet_limit', $limits?->single_bet_limit ?? '') }}"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">{{ __('player.daily_wagering_limit') }}</label>
                            <input type="number" step="0.01" min="0" name="daily_wagering_limit"
                                   value="{{ old('daily_wagering_limit', $limits?->daily_wagering_limit ?? '') }}"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">{{ __('player.limits_optional_hint') }}</p>
                    <div class="pt-2 text-right">
                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition">
                            {{ __('player.save_limits') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
