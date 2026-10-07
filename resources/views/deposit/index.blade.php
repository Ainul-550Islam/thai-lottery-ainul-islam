@extends('layouts.app')

@section('title', 'Deposit Access')

@section('content')
    <main class="mx-auto max-w-3xl px-4 py-16" role="main">
        <section class="rounded-2xl border border-emerald-500/30 bg-slate-950 p-8 text-slate-100">
            <h1 class="text-2xl font-bold">Secure deposit access</h1>
            <p class="mt-3 text-sm text-slate-300">
                Deposit availability, limits, instructions, references, and provider status are supplied only by the authenticated payment service.
            </p>
            <a class="mt-6 inline-flex rounded-lg bg-emerald-500 px-5 py-3 font-semibold text-slate-950" href="{{ route('player.deposit') }}">
                Open deposit page
            </a>
        </section>
    </main>
@endsection
