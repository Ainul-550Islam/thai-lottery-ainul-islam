@extends('layouts.app')

@section('title', trans('public_pages.age_attestation_title'))
@section('meta_description', trans('public_pages.age_attestation_text'))

@section('content')
<div class="mx-auto max-w-2xl py-6">
    <div class="tl-v2-card tl-v2-card--hero">
        <p class="tl-v2-eyebrow">{{ config('app.name') }}</p>
        <h1 class="mt-2 text-3xl font-black text-[#FFF6D6]">{{ trans('public_pages.age_attestation_title') }}</h1>
        <p class="mt-3 text-sm leading-relaxed text-[#D4CAB3]">{{ trans('public_pages.age_attestation_text') }}</p>

        @if ($dateOfBirthOnFile)
            <p class="mt-5 tl-v2-subcard border-[#D4AF37]/35 text-sm text-[#F5E6B8]" role="status">
                {{ trans('public_pages.age_dob_notice') }}
            </p>
        @endif

        <form method="POST" action="{{ route('account.age-verification.store') }}" class="mt-6 space-y-5">
            @csrf
            @unless ($dateOfBirthOnFile)
                <div class="space-y-2">
                    <label for="date_of_birth" class="block text-sm font-bold text-[#F5E6B8]">
                        {{ trans('public_pages.age_dob_label') }}
                    </label>
                    <input id="date_of_birth" name="date_of_birth" type="date" required value="{{ old('date_of_birth') }}"
                           max="{{ now()->toDateString() }}"
                           class="tl-v2-input">
                    @error('date_of_birth') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror
                </div>
            @endunless

            <div class="flex items-start gap-3 tl-v2-subcard">
                <input id="age_attestation" name="age_attestation" type="checkbox" value="1" required class="mt-1 h-5 w-5 rounded border-[#D4AF37]/40 bg-[#0B0904] accent-[#D4AF37]">
                <label for="age_attestation" class="text-sm text-[#FAF6EC] leading-relaxed">{{ trans('public_pages.age_attestation_checkbox', ['age' => $status['minimum_age']]) }}</label>
            </div>
            @error('age_attestation') <p class="text-sm text-rose-300" role="alert">{{ $message }}</p> @enderror

            <button type="submit" class="tl-v2-btn-gold">
                {{ trans('public_pages.age_attestation_submit') }}
            </button>
        </form>
    </div>
</div>
@endsection
