{{-- Public Home footer: required nav set, dynamic year, configured app name. --}}
<footer class="home-footer" role="contentinfo">
    <div class="home-footer__inner">
        <nav class="home-footer__nav" aria-label="Footer">
            <a href="{{ route('home') }}">{{ $text['meta_title'] ?? 'Home' }}</a>
            <a href="{{ route('results.index') }}">{{ $text['footer_results'] ?? 'Results' }}</a>
            <a href="{{ route('ticket-check') }}">{{ $text['footer_checker'] ?? 'Ticket Checker' }}</a>
            <a href="{{ route('sales-points') }}">{{ $text['footer_sales'] ?? 'Sales Points' }}</a>
            <a href="{{ route('terms') }}">{{ $text['footer_terms'] ?? 'Terms' }}</a>
            <a href="{{ route('fees') }}">{{ $text['footer_fees'] ?? 'Fees' }}</a>
            <a href="{{ route('contact') }}">{{ $text['footer_contact'] ?? 'Contact' }}</a>
            <a href="{{ route('privacy') }}">{{ $text['footer_privacy'] ?? 'Privacy' }}</a>
            @auth
                <a href="{{ route('player.dashboard') }}">Dashboard</a>
                <form method="POST" action="{{ route('logout') }}" class="home-footer__logout">
                    @csrf
                    <button type="submit" class="home-link-btn">Log out</button>
                </form>
            @else
                <a href="{{ route('login') }}">{{ $text['cta_sign_in'] ?? 'Login' }}</a>
                <a href="{{ route('register') }}">{{ $text['cta_register'] ?? 'Register' }}</a>
            @endauth
        </nav>
        <p class="home-footer__legal">
            &copy; {{ date('Y') }} {{ $appName ?? config('app.name', 'Thai Lottery') }}.
            Government Lottery Office rules apply; this site is not the official GLO website.
        </p>
    </div>
</footer>
