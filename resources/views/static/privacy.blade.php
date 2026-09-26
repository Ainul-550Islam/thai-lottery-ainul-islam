@extends('layouts.app')

@section('title', 'Privacy')

@section('content')
    <article class="home-page home-page--narrow legal-page">
        <h1 class="home-page__title">Privacy notice</h1>
        <p class="home-muted">Last updated: {{ date('Y-m-d') }}</p>

        <section class="home-card">
            <h2>1. Data we process</h2>
            <p>
                Account registration data, wallet and transaction records, ticket check inputs
                (six-digit numbers only — not stored as identity), and technical logs required
                for security. We do not publish personal data on public pages.
            </p>
        </section>

        <section class="home-card">
            <h2>2. Public pages</h2>
            <p>
                Home, results, ticket checker and sales-point search are anonymous. They do not
                require login and do not display other users' personal information, balances,
                or admin metrics.
            </p>
        </section>

        <section class="home-card">
            <h2>3. Security</h2>
            <p>
                Communications should use TLS. Administrative and financial actions are logged
                in an append-only audit trail. Rate limiting protects public check endpoints.
            </p>
        </section>

        <section class="home-card">
            <h2>4. Contact</h2>
            <p>
                For privacy requests, use the
                <a href="{{ route('contact') }}">contact page</a>
                when support contact details are configured.
            </p>
        </section>

        <footer class="home-footer" role="contentinfo">
            <div class="home-footer__inner">
                <nav class="home-footer__nav" aria-label="Footer">
                    <a href="{{ route('home') }}">Home</a>
                    <a href="{{ route('results.index') }}">Results</a>
                    <a href="{{ route('ticket-check') }}">Ticket Checker</a>
                    <a href="{{ route('sales-points') }}">Sales Points</a>
                    <a href="{{ route('terms') }}">Terms</a>
                    <a href="{{ route('contact') }}">Contact</a>
                    <a href="{{ route('privacy') }}">Privacy</a>
                    <a href="{{ route('login') }}">Login</a>
                    <a href="{{ route('register') }}">Register</a>
                </nav>
                <p class="home-footer__legal">
                    &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}.
                </p>
            </div>
        </footer>
    </article>
@endsection
