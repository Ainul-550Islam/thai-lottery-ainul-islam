@extends('layouts.app')

@section('title', __('public_pages.register_meta_title'))
@section('meta_description', __('public_pages.register_meta_description'))

@section('content')
<div class="min-h-[75vh] py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl mx-auto space-y-8 bg-slate-900/90 border border-slate-800 rounded-2xl p-8 shadow-2xl backdrop-blur">
        <div class="text-center">
            <h2 class="text-3xl font-extrabold tracking-tight text-white">
                {{ __('public_pages.register_heading') }}
            </h2>
            <p class="mt-2 text-sm text-slate-400">
                {{ __('public_pages.register_lead') }}
            </p>
        </div>

        @if ($errors->any())
            <div class="bg-rose-950/60 border border-rose-600/50 rounded-xl p-4 text-rose-200 text-sm" role="alert" aria-live="polite">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="mt-8 space-y-10" action="{{ route('register.attempt') }}" method="POST" novalidate>
            @csrf

            {{-- ================= Accounts Information ================= --}}
            <fieldset class="space-y-5">
                <legend class="text-lg font-bold text-emerald-300 border-b border-slate-800 w-full pb-2">
                    {{ __('public_pages.register_section_account') }}
                </legend>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="referral_id" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_referral') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="referral_id" name="referral_id" type="text" required value="{{ old('referral_id') }}"
                               aria-required="true" @if ($errors->has('referral_id')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('referral_id') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('referral_id'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('referral_id') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="mobile" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_mobile') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="mobile" name="mobile" type="text" inputmode="numeric" required value="{{ old('mobile') }}"
                               aria-required="true" aria-describedby="mobile-hint"
                               @if ($errors->has('mobile')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('mobile') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                               placeholder="0812345678">
                        <p id="mobile-hint" class="mt-1 text-xs text-slate-500">{{ __('public_pages.register_mobile_hint') }}</p>
                        @if ($errors->has('mobile'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('mobile') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_password') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required
                               aria-required="true" @if ($errors->has('password')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('password') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('password'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_password_confirm') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                               aria-required="true" @if ($errors->has('password_confirmation')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('password_confirmation') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('password_confirmation'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('password_confirmation') }}</p>
                        @endif
                    </div>
                </div>
            </fieldset>

            {{-- ================= Personal Details ================= --}}
            <fieldset class="space-y-5">
                <legend class="text-lg font-bold text-emerald-300 border-b border-slate-800 w-full pb-2">
                    {{ __('public_pages.register_section_personal') }}
                </legend>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_first_name') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="first_name" name="first_name" type="text" required value="{{ old('first_name') }}"
                               aria-required="true" @if ($errors->has('first_name')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('first_name') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('first_name'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('first_name') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="last_name" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_last_name') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="last_name" name="last_name" type="text" required value="{{ old('last_name') }}"
                               aria-required="true" @if ($errors->has('last_name')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('last_name') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('last_name'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('last_name') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="gender" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_gender') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <select id="gender" name="gender" required aria-required="true"
                                @if ($errors->has('gender')) aria-invalid="true" @endif
                                class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('gender') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                            <option value="">{{ __('public_pages.register_select') }}</option>
                            @foreach ($genders as $gender)
                                <option value="{{ $gender }}" @selected(old('gender') === $gender)>
                                    {{ __('public_pages.register_gender_'.$gender) }}
                                </option>
                            @endforeach
                        </select>
                        @if ($errors->has('gender'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('gender') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="city" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_city') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="city" name="city" type="text" required value="{{ old('city') }}"
                               aria-required="true" @if ($errors->has('city')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('city') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('city'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('city') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="country" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_country') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="country" name="country" type="text" required value="{{ old('country') }}"
                               aria-required="true" @if ($errors->has('country')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('country') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('country'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('country') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_email') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}"
                               aria-required="true" @if ($errors->has('email')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('email') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('email'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('email') }}</p>
                        @endif
                    </div>
                </div>
            </fieldset>

            {{-- ================= Birth Information ================= --}}
            <fieldset class="space-y-5">
                <legend class="text-lg font-bold text-emerald-300 border-b border-slate-800 w-full pb-2">
                    {{ __('public_pages.register_section_birth') }}
                </legend>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="date_of_birth" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_dob') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="date_of_birth" name="date_of_birth" type="date" required value="{{ old('date_of_birth') }}"
                               aria-required="true" @if ($errors->has('date_of_birth')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('date_of_birth') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100' }}">
                        @if ($errors->has('date_of_birth'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('date_of_birth') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="nationality" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_nationality') }} <span class="text-rose-400" aria-hidden="true">*</span>
                        </label>
                        <input id="nationality" name="nationality" type="text" required value="{{ old('nationality') }}"
                               aria-required="true" @if ($errors->has('nationality')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('nationality') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('nationality'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('nationality') }}</p>
                        @endif
                    </div>
                </div>
            </fieldset>

            {{-- ================= Terms ================= --}}
            <div class="flex items-start">
                <input id="terms" name="terms" type="checkbox" value="1" required
                       aria-required="true" aria-describedby="terms-hint"
                       @if ($errors->has('terms')) aria-invalid="true" @endif
                       class="mt-1 h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                <label for="terms" class="ml-3 block text-sm text-slate-300">
                    {{ __('public_pages.register_terms') }}
                </label>
            </div>
            <p id="terms-hint" class="text-xs text-slate-500 -mt-3 ml-7">{{ __('public_pages.register_terms_hint') }}</p>
            @if ($errors->has('terms'))
                <p class="text-xs text-rose-300 -mt-3 ml-7" role="alert">{{ $errors->first('terms') }}</p>
            @endif

            <div>
                <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg transition duration-200">
                    {{ __('public_pages.register_submit') }}
                </button>
            </div>
        </form>

        <div class="text-center text-sm">
            <a href="{{ route('login') }}" class="font-medium text-emerald-400 hover:text-emerald-300">
                {{ __('public_pages.register_back_to_login') }}
            </a>
        </div>
    </div>
</div>
@endsection
