{{--
    Links to public surfaces that actually exist (PROMPT 10, file 17).

    EVERY ENTRY IS CHECKED BEFORE IT IS RENDERED. Route::has() gates each one,
    so this component cannot throw when a lane has not shipped yet and cannot
    render a dead link. A "Useful links" block containing a 404 is worse than
    a shorter list.

    NAMED ROUTES ONLY. No hardcoded path and no external URL, so nothing here
    can point at another site.
--}}

@php
    $candidates = [
        ['route' => 'home', 'label' => trans('contact.link_home')],
        ['route' => 'about', 'label' => trans('contact.link_about')],
        ['route' => 'vision', 'label' => trans('contact.link_vision')],
        ['route' => 'fees', 'label' => trans('contact.link_fees')],
        ['route' => 'results.index', 'label' => trans('contact.link_results')],
        ['route' => 'prize-verification', 'label' => trans('contact.link_prize_verification')],
        ['route' => 'national-lottery.index', 'label' => trans('contact.link_national')],
        ['route' => 'weekly-lottery.index', 'label' => trans('contact.link_weekly')],
        ['route' => 'bingo-lottery.index', 'label' => trans('contact.link_mega')],
        ['route' => 'pcso-lottery.index', 'label' => trans('contact.link_pcso')],
        ['route' => 'terms', 'label' => trans('contact.link_terms')],
    ];

    $links = [];

    foreach ($candidates as $candidate) {
        if (Route::has($candidate['route'])) {
            $links[] = ['href' => route($candidate['route']), 'label' => $candidate['label']];
        }
    }
@endphp

@if ($links !== [])
    <nav class="ct-links" aria-labelledby="contact-links-heading">
        <h2 class="ct-links__heading" id="contact-links-heading">{{ trans('contact.useful_links') }}</h2>

        <ul class="ct-links__list">
            @foreach ($links as $link)
                <li class="ct-links__item">
                    <a class="ct-links__link" href="{{ $link['href'] }}">{{ $link['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
