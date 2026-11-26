@php $r = fn ($n, $f = '#') => \Illuminate\Support\Facades\Route::has($n) ? route($n) : $f; @endphp
@extends('layouts.app')

@section('title', 'Dashboard')
@section('gd_mode', 'dashboard')
@section('gd_endpoint', $r('api.v1.player.dashboard', ''))

@section('content')
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
@endsection
