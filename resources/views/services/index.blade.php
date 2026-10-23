@extends('layouts.app')

@section('title', $meta['title'].' | '.config('app.name'))
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['title'])
@section('meta_og_description', $meta['description'])
@section('meta_og_type', 'website')
@section('meta_og_url', $meta['canonical'])

@section('content')
<section class="mx-auto max-w-6xl space-y-10" aria-labelledby="services-title">
    <header class="tl-v2-card tl-v2-card--hero space-y-4">
        <p class="tl-v2-eyebrow">{{ trans('services.eyebrow') }}</p>
        <h1 id="services-title" class="text-3xl font-black tracking-tight text-[#FFF6D6] sm:text-5xl">{{ trans('services.heading') }}</h1>
        <p class="max-w-3xl text-base sm:text-lg leading-relaxed text-[#D4CAB3]">{{ trans('services.intro') }}</p>
    </header>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.catalogue_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.catalogue_body') }}</p>
            </div>
            <a class="mt-5 inline-flex items-center font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('lotteries.index') }}">{{ trans('services.catalogue_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.results_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.results_body') }}</p>
            </div>
            <a class="mt-5 inline-flex items-center font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('results.index') }}">{{ trans('services.results_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.ticket_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.ticket_body') }}</p>
            </div>
            <a class="mt-5 inline-flex items-center font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('ticket-check') }}">{{ trans('services.ticket_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.discount_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.discount_body') }}</p>
            </div>
            <a class="mt-5 inline-flex items-center font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('lotto-discount') }}">{{ trans('services.discount_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.account_help_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.account_help_body') }}</p>
            </div>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('how-to-play') }}">{{ trans('services.how_to_play') }}</a>
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('faq') }}">{{ trans('services.faq') }}</a>
            </div>
        </article>
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.fees_support_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.fees_support_body') }}</p>
            </div>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('fees') }}">{{ trans('services.view_fees') }}</a>
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('contact') }}">{{ trans('services.contact_us') }}</a>
            </div>
        </article>
        <article class="tl-v2-card flex flex-col justify-between">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.agent_inquiries_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.agent_inquiries_body') }}</p>
            </div>
            <a class="mt-5 inline-flex items-center font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('contact') }}">{{ trans('services.agent_inquiries_link') }} <span class="ml-2" aria-hidden="true">→</span></a>
        </article>
        <article class="tl-v2-card flex flex-col justify-between sm:col-span-2">
            <div>
                <h2 class="text-xl font-extrabold text-[#FFF6D6]">{{ trans('services.policies_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-[#D4CAB3]">{{ trans('services.policies_body') }}</p>
            </div>
            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2">
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('terms') }}">{{ trans('services.terms') }}</a>
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('account-verification-guide') }}">{{ trans('services.verification') }}</a>
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('account-grades') }}">{{ trans('services.grades') }}</a>
                <a class="font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('prize-verification') }}">{{ trans('services.prize_verification') }}</a>
            </div>
        </article>
    </div>

    <aside class="tl-v2-subcard border-[#D4AF37]/35 text-sm leading-7 text-[#FAF6EC]" aria-label="{{ trans('services.important_title') }}">
        <strong class="font-extrabold text-[#FFF6D6]">{{ trans('services.important_title') }}</strong>
        {{ trans('services.important_body') }}
        <a class="text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4 font-bold" href="{{ route('terms') }}">{{ trans('services.terms_link') }}</a>
    </aside>
</section>
@endsection
