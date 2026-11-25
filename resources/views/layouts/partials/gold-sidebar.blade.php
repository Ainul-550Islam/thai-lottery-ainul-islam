{{-- Gold sidebar. Single owner of the site navigation: the player's menu for a signed-in viewer, the public menu for a guest. Expects $gdGuest, $gdDashboard, $gdRoute and $gdLabel from layouts/app.blade.php. --}}
@php
    // One row per sidebar item (member menu and guest menu use the same shape).
    //   key     lang key the old top navigation already used (null = no key yet, the English label is used)
    //   routes  first route name that exists wins; a second entry is the name the rest of the views use
    //   active  route patterns that highlight the item (the first matching item wins, so at most one lights up)
    $gdMemberNav = [
        ['key' => 'player.nav_dashboard',          'label' => 'Dashboard',      'icon' => 'svg:i-home',       'routes' => ['player.dashboard'],                          'active' => ['player.dashboard'], 'dashboard' => true],
        ['key' => 'player.nav_place_bet',          'label' => 'Place Bet',      'icon' => 'img:nav-bet',      'routes' => ['player.bet'],                                'active' => ['player.bet', 'player.bet.*']],
        ['key' => 'player.nav_draws',              'label' => 'Draws & Results','icon' => 'img:nav-draws',    'routes' => ['player.draws'],                              'active' => ['player.draw*']],
        ['key' => 'player.nav_bets',               'label' => 'My Bets',        'icon' => 'img:nav-mybets',   'routes' => ['player.bets'],                               'active' => ['player.bets', 'player.bets.*']],
        ['key' => 'player.nav_wallet',             'label' => 'Wallet',         'icon' => 'img:nav-wallet',   'routes' => ['player.wallet'],                             'active' => ['player.wallet']],
        ['key' => 'wallet_conversion.nav_convert', 'label' => 'Convert',        'icon' => 'img:nav-convert',  'routes' => ['wallet.conversion.index', 'player.wallet.conversion'], 'active' => ['wallet.conversion.*', 'player.wallet.conversion*']],
        ['key' => 'player.nav_glo_l6',             'label' => 'GLO L6',         'icon' => 'img:nav-glo',      'routes' => ['glo-l6.index'],                              'active' => ['glo-l6.*']],
        ['key' => 'player.nav_deposit',            'label' => 'Deposit',        'icon' => 'img:nav-deposit',  'routes' => ['player.deposit'],                            'active' => ['player.deposit*']],
        ['key' => null,                            'label' => 'Withdrawal',     'icon' => 'img:nav-withdraw', 'routes' => ['player.withdraw'],                           'active' => ['player.withdraw*']],
        ['key' => 'player.nav_profile',            'label' => 'Profile',        'icon' => 'img:nav-profile',  'routes' => ['player.profile'],                            'active' => ['player.profile*']],
        ['key' => null,                            'label' => 'Security',       'icon' => 'img:nav-security', 'routes' => ['player.security'],                           'active' => ['player.security*']],
        ['key' => null,                            'label' => 'Rewards',        'icon' => 'img:nav-rewards',  'routes' => ['member.rewards'],                            'active' => ['member.rewards*']],
        ['key' => null,                            'label' => 'Referral',       'icon' => 'img:nav-referral', 'routes' => ['agent.portal', 'agent.dashboard'],           'active' => ['agent.*']],
        ['key' => null,                            'label' => 'Help & Support', 'icon' => 'img:nav-help',     'routes' => ['support.portal', 'support.index'],           'active' => ['support.*']],
        ['key' => null,                            'label' => 'Settings',       'icon' => 'img:nav-settings', 'routes' => ['player.security'],                           'active' => []],
    ];

    $gdGuestNav = [
        ['key' => null,                       'label' => 'Home',           'icon' => 'svg:i-home',       'routes' => ['home'],            'active' => ['home']],
        ['key' => 'player.nav_lotteries',     'label' => 'Lotteries',      'icon' => 'img:nav-bet',      'routes' => ['lotteries.index'], 'active' => ['lotteries.*', 'national-lottery.*', 'weekly-lottery.*', 'bingo-lottery.*', 'pcso-lottery.*']],
        ['key' => 'player.nav_glo_l6',        'label' => 'GLO L6',         'icon' => 'img:nav-glo',      'routes' => ['glo-l6.index'],    'active' => ['glo-l6.*']],
        ['key' => 'player.nav_results',       'label' => 'Results',        'icon' => 'img:nav-draws',    'routes' => ['results.index'],   'active' => ['results.*']],
        ['key' => 'player.nav_services',      'label' => 'Services',       'icon' => 'img:nav-settings', 'routes' => ['services'],        'active' => ['services']],
        ['key' => 'player.nav_about',         'label' => 'About',          'icon' => 'img:nav-security', 'routes' => ['about'],           'active' => ['about']],
        ['key' => 'player.nav_lotto_discount','label' => 'Lotto Discount', 'icon' => 'img:nav-rewards',  'routes' => ['lotto-discount'],  'active' => ['lotto-discount']],
        ['key' => 'player.nav_check_ticket',  'label' => 'Check Ticket',   'icon' => 'img:nav-mybets',   'routes' => ['ticket-check'],    'active' => ['ticket-check']],
        ['key' => 'player.nav_contact',       'label' => 'Contact',        'icon' => 'img:nav-help',     'routes' => ['contact'],         'active' => ['contact']],
    ];

    $gdNav = $gdGuest ? $gdGuestNav : $gdMemberNav;
    $gdActive = null;
    foreach ($gdNav as $gdIndex => $gdItem) {
        $gdHit = (($gdItem['dashboard'] ?? false) && $gdDashboard)
            || ($gdItem['active'] !== [] && request()->routeIs(...$gdItem['active']));
        if ($gdHit) {
            $gdActive = $gdIndex;
            break;
        }
    }
@endphp
<aside class="gd-side" aria-label="Main navigation">
    <a class="gd-logo" href="{{ $gdRoute('home', '/') }}"><img class="gd-mark" src="{{ asset('images/gd/logo-mark.png') }}" alt=""><span><b>THAI<br>LOTTERY</b><small>Play Smart - Win Big</small></span></a>
    <nav class="gd-nav">
        @foreach ($gdNav as $gdIndex => $gdItem)
            @php [$gdIconKind, $gdIconName] = explode(':', $gdItem['icon'], 2); @endphp
            <a href="{{ $gdRoute($gdItem['routes']) }}"@if ($gdIndex === $gdActive) class="on" aria-current="page"@endif>@if ($gdIconKind === 'svg')<svg class="ic"><use href="#{{ $gdIconName }}"/></svg>@else<img class="gd-ni" src="{{ asset('images/gd/'.$gdIconName.'.png') }}" alt="">@endif{{ $gdItem['key'] ? $gdLabel($gdItem['key'], $gdItem['label']) : $gdItem['label'] }}</a>
        @endforeach
        @unless ($gdGuest)
            <form method="POST" action="{{ $gdRoute('logout') }}">@csrf<button type="submit"><img class="gd-ni" src="{{ asset('images/gd/nav-logout.png') }}" alt="">{{ $gdLabel('player.logout', 'Logout') }}</button></form>
        @endunless
    </nav>
    @if (\Illuminate\Support\Facades\Route::has('locale.switch'))
        <div class="gd-lang" role="group" aria-label="{{ $gdLabel('player.language_label', 'Language') }}">
            @foreach (['en' => 'EN', 'th' => 'TH', 'bn' => 'বাংলা'] as $gdCode => $gdText)
                <a href="{{ route('locale.switch', $gdCode) }}"{!! $gdCode === 'bn' ? ' lang="bn"' : '' !!}{!! app()->getLocale() === $gdCode ? ' class="on" aria-current="true"' : '' !!}>{{ $gdText }}</a>
            @endforeach
        </div>
    @endif
    <div class="gd-vip"><img src="{{ asset('images/gd/vip-crown.png') }}" alt=""><b>VIP CLUB</b><small>Exclusive Benefits</small><a class="gd-btn" href="{{ $gdGuest ? $gdRoute('register') : $gdRoute('member.rewards') }}">Join Now <svg class="ic"><use href="#i-arrow"/></svg></a></div>
</aside>
