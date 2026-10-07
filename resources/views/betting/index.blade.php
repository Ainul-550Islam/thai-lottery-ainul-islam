@extends('layouts.app')

@section('title', 'Betting Access')

@section('content')
    <main class="mx-auto max-w-3xl px-4 py-16" role="main">
        <section class="rounded-2xl border border-amber-500/30 bg-slate-950 p-8 text-slate-100">
            <h1 class="text-2xl font-bold">Canonical betting access</h1>
            <p class="mt-3 text-sm text-slate-300">
                Wagers are accepted only through the authenticated betting service, where draw state,
                limits, wallet locks, idempotency, ledger posting, and responsible-gaming controls are enforced.
            </p>
            <a class="mt-6 inline-flex rounded-lg bg-amber-500 px-5 py-3 font-semibold text-slate-950" href="{{ route('player.bet') }}">
                Open secure bet slip
            </a>
        </section>
    </main>
@endsection
