@extends('layouts.app')

@section('title', trans('public_pages.age_attestation_title'))
@section('meta_description', trans('public_pages.age_attestation_text'))

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="rounded-xl border border-slate-700 bg-slate-900 p-6 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-widest text-emerald-400">{{ config('app.name') }}</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-100">{{ trans('public_pages.age_attestation_title') }}</h1>
        <p class="mt-3 text-sm leading-6 text-slate-300">{{ trans('public_pages.age_attestation_text') }}</p>

        @if ($dateOfBirthOnFile)
            <p class="mt-5 rounded-lg border border-amber-700 bg-amber-950/30 p-4 text-sm text-amber-100">
                {{ trans('public_pages.age_dob_notice') }}
            </p>
        @else
            <label for="date_of_birth" class="mt-6 block text-sm font-medium text-slate-200">
                {{ trans('public_pages.age_dob_label') }}
            </label>
        @endif

        <form method="POST" action="{{ route('account.age-verification.store') }}" class="mt-6 space-y-5">
            @csrf
            @unless ($dateOfBirthOnFile)
                <input id="date_of_birth" name="date_of_birth" type="date" required value="{{ old('date_of_birth') }}"
                       max="{{ now()->toDateString() }}"
                       class="block w-full rounded-lg border border-slate-600 bg-slate-950 px-4 py-3 text-slate-100">
                @error('date_of_birth') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror
            @endunless

            <div class="flex items-start gap-3">
                <input id="age_attestation" name="age_attestation" type="checkbox" value="1" required class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-emerald-500">
                <label for="age_attestation" class="text-sm text-slate-200">{{ trans('public_pages.age_attestation_checkbox', ['age' => $status['minimum_age']]) }}</label>
            </div>
            @error('age_attestation') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

            <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-3 font-semibold text-slate-950 hover:bg-emerald-400">
                {{ trans('public_pages.age_attestation_submit') }}
            </button>
        </form>
    </div>
</div>
@endsection
