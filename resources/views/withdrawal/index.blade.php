@extends('layouts.app')

@section('title', 'Withdrawal Access')

@section('content')
    <main class="mx-auto max-w-3xl px-4 py-16" role="main">
        <section class="rounded-2xl border border-sky-500/30 bg-slate-950 p-8 text-slate-100">
            <h1 class="text-2xl font-bold">Secure withdrawal access</h1>
            <p class="mt-3 text-sm text-slate-300">
                Withdrawable balance, destinations, fees, approval, and settlement status are supplied only by the authenticated withdrawal service.
            </p>
            <a class="mt-6 inline-flex rounded-lg bg-sky-500 px-5 py-3 font-semibold text-slate-950" href="{{ route('player.withdraw') }}">
                Open withdrawal page
            </a>
        </section>
    </main>
@endsection
