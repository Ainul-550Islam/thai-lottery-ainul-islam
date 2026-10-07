@extends('layouts.app')

@section('title', 'Wallet Access')

@section('content')
    <main class="mx-auto max-w-3xl px-4 py-16" role="main">
        <section class="rounded-2xl border border-indigo-500/30 bg-slate-950 p-8 text-slate-100">
            <h1 class="text-2xl font-bold">Canonical wallet access</h1>
            <p class="mt-3 text-sm text-slate-300">
                Balances, reservations, transactions, and ledger-backed activity are read only from the authenticated wallet service.
            </p>
            <a class="mt-6 inline-flex rounded-lg bg-indigo-500 px-5 py-3 font-semibold text-white" href="{{ route('player.wallet') }}">
                Open wallet
            </a>
        </section>
    </main>
@endsection
