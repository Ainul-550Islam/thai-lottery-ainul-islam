@php $r = fn ($n, $f = '#') => \Illuminate\Support\Facades\Route::has($n) ? route($n) : $f; @endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard - {{ config('app.name') }}</title>
    @vite(['resources/css/gold-dashboard.css', 'resources/js/gold-dashboard.js'])
</head>
<body class="gd-body">
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></symbol>
<symbol id="i-ticket" viewBox="0 0 24 24"><path d="M3 8a2 2 0 0 0 0 8v3h18v-3a2 2 0 0 1 0-8V5H3zM13 5v14"/></symbol>
<symbol id="i-trophy" viewBox="0 0 24 24"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/></symbol>
<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
<symbol id="i-wallet" viewBox="0 0 24 24"><path d="M3 7h16a2 2 0 0 1 2 2v10H5a2 2 0 0 1-2-2zM3 7l2-3h12v3M16 13h2"/></symbol>
<symbol id="i-repeat" viewBox="0 0 24 24"><path d="M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 0 1-3 3H3"/></symbol>
<symbol id="i-down" viewBox="0 0 24 24"><path d="M12 4v14M6 12l6 6 6-6"/></symbol>
<symbol id="i-up" viewBox="0 0 24 24"><path d="M12 20V6M6 12l6-6 6 6"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></symbol>
<symbol id="i-gift" viewBox="0 0 24 24"><path d="M3 9h18v4H3zM5 13v8h14v-8M12 9v12M12 9C9 9 8 7 8 6a2.5 2.5 0 0 1 4-1.5zm0 0c3 0 4-2 4-3a2.5 2.5 0 0 0-4-1.5"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0M17 4.5a3.5 3.5 0 0 1 0 7M22 21a6 6 0 0 0-4-5.6"/></symbol>
<symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></symbol>
<symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></symbol>
<symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></symbol>
<symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 0 0 4 0"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></symbol>
<symbol id="i-expand" viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M3 6h18M3 12h14M3 18h18"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
<symbol id="i-crown" viewBox="0 0 24 24"><path d="M3 8l4 4 5-7 5 7 4-4-2 11H5z"/></symbol>
<symbol id="i-headset" viewBox="0 0 24 24"><path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v5H4zM17 14h3v5h-3zM20 19c0 2-3 2-6 2"/></symbol>
<symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></symbol>
<symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></symbol>
<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></symbol>
<symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></symbol>
<linearGradient id="gg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe9a0"/><stop offset="1" stop-color="#8a5a06"/></linearGradient>
<path id="sp" d="M0 0l4 30h-8zM-14 30h28l5 40h-38zM-28 70h56l7 52h-70z"/>
</defs></svg>

<div class="gd" id="gd-root" data-state="ready" data-img="{{ asset('images/gd') }}" data-endpoint="{{ $r('api.v1.player.dashboard', '') }}">
    <script type="application/json" id="gd-state">@json($state ?? null)</script>
    <aside class="gd-side" aria-label="Main navigation">
        <a class="gd-logo" href="{{ $r('home', '/') }}"><img class="gd-mark" src="{{ asset('images/gd/logo-mark.png') }}" alt=""><span><b>THAI<br>LOTTERY</b><small>Play Smart - Win Big</small></span></a>
        <nav class="gd-nav">
            <a class="on" href="{{ $r('player.dashboard') }}"><svg class="ic"><use href="#i-home"/></svg>Dashboard</a>
            <a href="{{ $r('player.bet') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-bet.png') }}" alt="">Place Bet</a>
            <a href="{{ $r('player.draws') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-draws.png') }}" alt="">Draws &amp; Results</a>
            <a href="{{ $r('player.bets') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-mybets.png') }}" alt="">My Bets</a>
            <a href="{{ $r('player.wallet') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-wallet.png') }}" alt="">Wallet</a>
            <a href="{{ $r('wallet.conversion.index') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-convert.png') }}" alt="">Convert</a>
            <a href="{{ $r('glo-l6.index') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-glo.png') }}" alt="">GLO L6</a>
            <a href="{{ $r('player.deposit') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-deposit.png') }}" alt="">Deposit</a>
            <a href="{{ $r('player.withdraw') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-withdraw.png') }}" alt="">Withdrawal</a>
            <a href="{{ $r('player.profile') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-profile.png') }}" alt="">Profile</a>
            <a href="{{ $r('player.security') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-security.png') }}" alt="">Security</a>
            <a href="{{ $r('member.rewards') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-rewards.png') }}" alt="">Rewards</a>
            <a href="{{ $r('agent.portal') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-referral.png') }}" alt="">Referral</a>
            <a href="{{ $r('support.portal') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-help.png') }}" alt="">Help &amp; Support</a>
            <a href="{{ $r('player.security') }}"><img class="gd-ni" src="{{ asset('images/gd/nav-settings.png') }}" alt="">Settings</a>
            <form method="POST" action="{{ $r('logout') }}">@csrf<button type="submit"><img class="gd-ni" src="{{ asset('images/gd/nav-logout.png') }}" alt="">Logout</button></form>
        </nav>
        <div class="gd-vip"><img src="{{ asset('images/gd/vip-crown.png') }}" alt=""><b>VIP CLUB</b><small>Exclusive Benefits</small><a class="gd-btn" href="{{ $r('member.rewards') }}">Join Now <svg class="ic"><use href="#i-arrow"/></svg></a></div>
    </aside>

    <header class="gd-top">
        <button type="button" class="gd-ib" id="gd-menu" aria-label="Menu"><svg class="ic"><use href="#i-menu"/></svg></button>
        <label class="gd-search"><svg class="ic"><use href="#i-search"/></svg><input type="search" placeholder="Search lotteries, tickets, or transactions..." aria-label="Search"></label>
        <span class="gd-live"><i></i>Live</span>
        <div class="gd-clock"><div id="gd-date"></div><div id="gd-time"></div></div>
        <i class="gd-vr"></i><button type="button" class="gd-ib" aria-label="Notifications"><svg class="ic"><use href="#i-bell"/></svg><span class="gd-bdg" id="gd-bdg">0</span></button>
        <button type="button" class="gd-ib" id="gd-fs" aria-label="Fullscreen"><svg class="ic"><use href="#i-expand"/></svg></button>
        <div class="gd-user"><img class="gd-av" data-src="user.avatar" src="{{ asset('images/gd/avatar.png') }}" alt=""><div><b data-bind="user.name"></b><small><span data-bind="user.tier"></span> <svg class="ic"><use href="#i-crown"/></svg></small></div><i class="gd-chev"></i></div>
    </header>

    <main class="gd-main">
        <p class="gd-err" role="alert">Could not load dashboard data. Refresh the page.</p>
        <section class="gd-c gd-hero" @if (file_exists(public_path('images/gd/hero.jpg'))) style="--gd-hero-img:url('{{ asset('images/gd/hero.jpg') }}')" @endif>
            <img class="gd-hl" src="{{ asset('images/gd/hero-left.png') }}" alt=""><img class="gd-hr" src="{{ asset('images/gd/hero-right.png') }}" alt=""><img class="gd-b3" src="{{ asset('images/gd/ball3.png') }}" alt="">
            <div class="t"><h1>THAI LOTTERY</h1><p class="l">Your Trusted Lottery Platform</p><p class="s">Play • Win • Enjoy</p><a class="gd-btn o" href="{{ $r('player.bet') }}">Start Playing <svg class="ic"><use href="#i-arrow"/></svg></a></div>
        </section>

        <section class="gd-stats" aria-label="Account summary">
            <div class="gd-c gd-stat"><img src="{{ asset('images/gd/stat-balance.png') }}" alt=""><div><small>Total Balance</small><b data-bind="stats.balance" data-fmt="money"></b></div><span class="gd-d" data-bind="stats.balanceDelta" data-fmt="pct"></span></div>
            <div class="gd-c gd-stat"><img src="{{ asset('images/gd/stat-win.png') }}" alt=""><div><small>Total Winnings</small><b data-bind="stats.winnings" data-fmt="money"></b></div><span class="gd-d" data-bind="stats.winningsDelta" data-fmt="pct"></span></div>
            <div class="gd-c gd-stat"><img src="{{ asset('images/gd/stat-bets.png') }}" alt=""><div><small>Total Bets</small><b data-bind="stats.bets" data-fmt="int"></b></div><span class="gd-d" data-bind="stats.betsDelta" data-fmt="pct"></span></div>
            <div class="gd-c gd-stat"><img src="{{ asset('images/gd/stat-gift.png') }}" alt=""><div><small>Reward Points</small><b data-bind="stats.points" data-fmt="int"></b></div><span class="gd-d" data-bind="stats.pointsDelta" data-fmt="pcts"></span></div>
        </section>

        <div class="gd-r3">
            <section class="gd-c" aria-labelledby="gd-h1">
                <h2 class="gd-h" id="gd-h1"><svg class="ic"><use href="#i-chart"/></svg>Live Draws <span class="gd-pill">● Live Now</span></h2>
                <div class="gd-ld">
                    <img class="gd-la" src="{{ asset('images/gd/live-art.png') }}" alt="">
                    <div>
                        <h3><span data-bind="live.game"></span> <span class="gd-g" data-bind="live.name"></span></h3>
                        <p>Draw No. <span data-bind="live.drawNo"></span></p><p data-bind="live.label"></p>
                        <div class="gd-cd" id="gd-cd" role="timer"><div data-p>00<small>Hrs</small></div><b>:</b><div data-p>00<small>Min</small></div><b>:</b><div data-p>00<small>Sec</small></div></div>
                        <a class="gd-btn" href="{{ $r('player.draws') }}">View Details <svg class="ic"><use href="#i-arrow"/></svg></a>
                    </div>
                </div>
            </section>
            <section class="gd-c" aria-labelledby="gd-h2">
                <h2 class="gd-h" id="gd-h2"><svg class="ic"><use href="#i-trophy"/></svg>Latest Results <a class="gd-more" href="{{ $r('results.index') }}">View All →</a></h2>
                <div class="gd-tabs" id="gd-tabs" role="tablist"></div>
                <div class="gd-res"><div class="w"><p class="n"><span id="gd-rn"></span> <span class="gd-g" id="gd-rno"></span></p><p id="gd-rd"></p><div class="gd-balls" id="gd-rb"></div><span class="gd-nx"><svg class="ic gd-g"><use href="#i-clock"/></svg><span id="gd-rx"></span></span></div><img class="gd-cage" src="{{ asset('images/gd/cage.png') }}" alt=""></div>
            </section>
            <section class="gd-c" aria-labelledby="gd-h3">
                <h2 class="gd-h" id="gd-h3"><svg class="ic"><use href="#i-calendar"/></svg>Upcoming Draws <a class="gd-more" href="{{ $r('player.draws') }}">View All →</a></h2>
                <ul class="gd-up" id="gd-up"></ul>
            </section>
        </div>

        <div class="gd-r4">
            <section class="gd-c" aria-labelledby="gd-h4">
                <h2 class="gd-h" id="gd-h4"><svg class="ic"><use href="#i-bolt"/></svg>Quick Actions</h2>
                <div class="gd-qa">
                    <a href="{{ $r('player.bet') }}"><img class="gd-qi" src="{{ asset('images/gd/qa-bet.png') }}" alt="">Place Bet</a>
                    <a href="{{ $r('player.deposit') }}"><img class="gd-qi" src="{{ asset('images/gd/qa-deposit.png') }}" alt="">Deposit</a>
                    <a href="{{ $r('wallet.conversion.index') }}"><img class="gd-qi" src="{{ asset('images/gd/qa-convert.png') }}" alt="">Convert</a>
                    <a href="{{ $r('player.withdraw') }}"><img class="gd-qi" src="{{ asset('images/gd/qa-withdraw.png') }}" alt="">Withdraw</a>
                    <a href="{{ $r('player.bets') }}"><img class="gd-qi" src="{{ asset('images/gd/qa-history.png') }}" alt="">History</a>
                </div>
            </section>
            <section class="gd-c" aria-labelledby="gd-h5">
                <h2 class="gd-h" id="gd-h5"><svg class="ic"><use href="#i-trophy"/></svg>Your Recent Activity <a class="gd-more" href="{{ $r('player.bets') }}">View All →</a></h2>
                <ul class="gd-act" id="gd-act"></ul>
            </section>
            <section class="gd-c" aria-labelledby="gd-h6">
                <h2 class="gd-h" id="gd-h6"><svg class="ic"><use href="#i-crown"/></svg>Player Level</h2>
                <div class="gd-lv"><div><div class="gd-vg"><svg class="ic"><use href="#i-crown"/></svg><b data-bind="level.tier"></b></div><div class="gd-br"><div class="gd-bar"><i id="gd-bar"></i></div><small id="gd-pc"></small></div><small>Next Level: <span data-bind="level.next"></span></small></div><img class="gd-crest" src="{{ asset('images/gd/crest.png') }}" alt=""></div>
            </section>
            <section class="gd-c gd-help" aria-labelledby="gd-h7">
                <h2 class="gd-h" id="gd-h7"><svg class="ic"><use href="#i-headset"/></svg>Need Help?</h2>
                <p>Our 24/7 Support Team is always here for you!</p>
                <a class="gd-btn" href="{{ $r('support.portal') }}">Contact Us <svg class="ic"><use href="#i-arrow"/></svg></a>
                <img class="gd-agent" src="{{ asset('images/gd/agent.png') }}" alt="">
            </section>
        </div>
    </main>

    <footer class="gd-foot">
        <span class="gd-k"><img src="{{ asset('images/gd/f-shield.png') }}" alt="">Secure &amp; Trusted</span><span class="gd-k"><img src="{{ asset('images/gd/f-lock.png') }}" alt="">SSL Encrypted</span><span><img src="{{ asset('images/gd/f-check.png') }}" alt="">Licensed by GLO</span>
        <span class="gd-tag">Dream Big... Win Bigger...</span>
        <span>Follow Us:</span><span class="gd-soc"><a href="#" aria-label="Facebook"><img src="{{ asset('images/gd/s-fb.png') }}" alt=""></a><a href="#" aria-label="YouTube"><img src="{{ asset('images/gd/s-yt.png') }}" alt=""></a><a href="#" aria-label="Line"><img src="{{ asset('images/gd/s-line.png') }}" alt=""></a><a href="#" aria-label="Telegram"><img src="{{ asset('images/gd/s-tg.png') }}" alt=""></a></span>
        <span>Download Our App:</span><a href="#"><img class="gd-sb" src="{{ asset('images/gd/gplay.png') }}" alt="Get it on Google Play"></a><a href="#"><img class="gd-sb" src="{{ asset('images/gd/appstore.png') }}" alt="Download on the App Store"></a>
    </footer>
</div>
</body>
</html>
