@extends('layouts.app')

@section('title', 'Betting Access')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-16">
        <section class="rounded-2xl border border-amber-500/30 bg-slate-950 p-8 text-slate-100">
            <h1 class="text-2xl font-bold">Betting</h1>
            <p class="mt-3 text-sm text-slate-300">
                Sign in to review currently available betting options and applicable account requirements.
            </p>
            <a class="mt-6 inline-flex rounded-lg bg-amber-500 px-5 py-3 font-semibold text-slate-950" href="{{ route('player.bet') }}">
                Continue to betting
            </a>
        </section>
    </div>
@endsection
