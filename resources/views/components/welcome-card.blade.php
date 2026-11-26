@props(['name', 'avatar' => null, 'username', 'balance', 'currency' => 'THB'])
{{-- Post-login card. Pass REAL values from the dashboard controller; nothing here is invented. --}}
<div class="flex items-center gap-4 rounded-2xl border border-amber-400/30 bg-slate-900/80 p-5 shadow-xl">
    @if ($avatar)
        <img src="{{ $avatar }}" alt="" width="64" height="64" referrerpolicy="no-referrer" class="h-16 w-16 rounded-full ring-2 ring-amber-400/60 object-cover">
    @endif
    <div class="min-w-0">
        <p class="truncate text-lg font-extrabold text-white">{{ __('public_pages.google_confirm_welcome', ['name' => $name]) }}</p>
        <p class="text-xs text-slate-400">ID: <span class="font-mono text-slate-200">{{ $username }}</span></p>
        <p class="mt-1 text-sm text-emerald-300">{{ __('public_pages.google_wallet_label') }}: <span class="font-bold">{{ $currency === 'THB' ? '฿' : $currency.' ' }}{{ $balance }}</span></p>
    </div>
</div>
