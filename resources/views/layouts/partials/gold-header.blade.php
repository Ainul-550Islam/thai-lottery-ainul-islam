{{-- Gold header. Expects $gdGuest, $gdDashboard and the $gdUser* values from layouts/app.blade.php. --}}
<header class="gd-top">
    <button type="button" class="gd-ib" id="gd-menu" aria-label="Menu"><svg class="ic"><use href="#i-menu"/></svg></button>
    <label class="gd-search"><svg class="ic"><use href="#i-search"/></svg><input type="search" placeholder="Search lotteries, tickets, or transactions..." aria-label="Search"></label>
    <span class="gd-live"><i></i>Live</span>
    @if (! $gdDashboard && ! $gdGuest)
        <div id="live-wallet-pill" class="gd-wallet"><i></i><small>Balance:</small><b id="player-balance-display">{{ \App\Services\Finance\Money::of((string) (Auth::user()->wallet?->balance ?? '0'), \App\Enums\Currency::site())->format() }}</b></div>
    @endif
    <div class="gd-clock"><div id="gd-date"></div><div id="gd-time"></div></div>
    <i class="gd-vr"></i>
    @unless ($gdGuest)
        <button type="button" class="gd-ib" aria-label="Notifications"><svg class="ic"><use href="#i-bell"/></svg>@if ($gdDashboard)<span class="gd-bdg" id="gd-bdg">0</span>@elseif ($gdUnread > 0)<span class="gd-bdg" id="gd-bdg">{{ $gdUnread }}</span>@endif</button>
    @endunless
    <button type="button" class="gd-ib" id="gd-fs" aria-label="Fullscreen"><svg class="ic"><use href="#i-expand"/></svg></button>
    @if ($gdGuest)
        <div class="gd-auth"><a class="gd-btn o" href="{{ $gdRoute('login') }}">{{ $gdLabel('player.sign_in', 'Sign In') }}</a><a class="gd-btn" href="{{ $gdRoute('register') }}">{{ $gdLabel('player.register', 'Register') }}</a></div>
    @else
        <div class="gd-user">
            @if ($gdDashboard)
                <img class="gd-av" data-src="user.avatar" src="{{ asset('images/gd/avatar.png') }}" alt=""><div><b data-bind="user.name"></b><small><span data-bind="user.tier"></span> <svg class="ic"><use href="#i-crown"/></svg></small></div>
            @else
                @if ($gdAvatar)<img class="gd-av" src="{{ $gdAvatar }}" alt="">@else<span class="gd-av">{{ $gdInitials }}</span>@endif<div><b>{{ $gdName }}</b>@if ($gdTier)<small>{{ $gdTier }} <svg class="ic"><use href="#i-crown"/></svg></small>@endif</div>
            @endif
            <i class="gd-chev"></i>
        </div>
    @endif
</header>
