@extends('layouts.app')

@section('title', 'Terms')

@section('content')
    <article class="home-page home-page--narrow legal-page">
        <h1 class="home-page__title">Terms of use</h1>
        <p class="home-muted">Last updated: {{ date('Y-m-d') }}</p>

        <section class="home-card">
            <h2>1. Scope</h2>
            <p>
                This site publishes Government Lottery related information, ticket checking tools and
                account features for registered players where enabled. Lottery participation follows
                the official rules of the Government Lottery Office (GLO) and applicable law.
            </p>
        </section>

        <section class="home-card">
            <h2>2. Results and prizes</h2>
            <p>
                Draw results display with an explicit source state
                (<code>OFFICIAL_SOURCE_VERIFIED</code>, <code>INTERNAL_RECONCILED</code>,
                <code>FIXTURE_ONLY</code>, <code>NOT_CONFIGURED</code>, or <code>UNAVAILABLE</code>).
                Fixture or sample data is never presented as an official government announcement.
                Prize amounts follow the configured official catalogue; N3 payouts are draw-calculated.
            </p>
        </section>

        <section class="home-card">
            <h2>3. Accounts</h2>
            <p>
                You must provide accurate registration information. Access controls, two-factor
                authentication where enabled, and KYC checks for withdrawals apply as configured.
                You are responsible for keeping your credentials secure.
            </p>
        </section>

        <section class="home-card">
            <h2>4. Prohibited conduct</h2>
            <p>
                Automated abuse of public endpoints, attempted circumvention of rate limits, and
                fraudulent claim or identity information may result in account restriction.
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
