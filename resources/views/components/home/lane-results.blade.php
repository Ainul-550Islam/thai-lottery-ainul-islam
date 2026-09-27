@props([
    'text' => [],
    'lanes' => [],
])

{{--
    Latest result from each public lane (Home page).

    WHY IT IS HERE. The four lanes shipped reachable only from the top
    navigation. A visitor landing on the Home page had no sign that National,
    Weekly, Mega or PCSO results were published at all.

    EVERY VALUE IS A STRING AND EVERY ABSENCE IS STATED. A lane with no
    published draw renders the "no result yet" line - never a row of zeros,
    which is a result a real draw could produce. A field that did not run
    renders the "not published" label for the same reason.

    NO CALENDAR ARITHMETIC AND NO NUMBER PARSING HAPPENS HERE. The digest
    service already asked each lane's own service, and the field list comes
    from each lane's schema, so PCSO shows its four numbers and the others
    show three without this template knowing anything about either.
--}}

@php
    $laneList = is_array($lanes['lanes'] ?? null) ? $lanes['lanes'] : [];
    $isThai = app()->getLocale() === 'th';
@endphp

@if ($laneList !== [])
    <section class="home-card home-lanes" aria-labelledby="home-lanes-title">
        <div class="home-card__head">
            <h2 id="home-lanes-title">{{ $text['lane_results_title'] ?? 'Latest lottery results' }}</h2>
        </div>

        <ul class="home-lanes__list">
            @foreach ($laneList as $lane)
                @php
                    $displayDate = $isThai
                        ? ($lane['date_display_th'] ?? null)
                        : ($lane['date_display_en'] ?? null);
                @endphp

                <li class="home-lanes__item">
                    <h3 class="home-lanes__title">
                        <a class="home-lanes__link" href="{{ $lane['route'] }}">
                            {{ trans($lane['title_key']) }}
                        </a>
                    </h3>

                    @if (! $lane['available'])
                        <p class="home-lanes__empty" role="status">
                            {{ $text['lane_results_none'] ?? 'No published result yet.' }}
                        </p>
                    @else
                        @if (is_string($displayDate) && $displayDate !== '')
                            <p class="home-lanes__date">
                                <time datetime="{{ $lane['date_iso'] }}">{{ $displayDate }}</time>
                                @if (! empty($lane['time_display']))
                                    <span class="home-lanes__time">{{ $lane['time_display'] }}</span>
                                @endif
                            </p>
                        @endif

                        <dl class="home-lanes__numbers">
                            @foreach ($lane['fields'] as $field)
                                <div class="home-lanes__number">
                                    <dt class="home-lanes__label">{{ trans($field['label_key']) }}</dt>
                                    <dd class="home-lanes__value">
                                        @if ($field['value'] === null)
                                            <span class="home-lanes__missing">{{ $text['lane_results_field_none'] ?? 'Not published' }}</span>
                                        @else
                                            {{-- String in, string out: leading zeros survive. --}}
                                            <span class="home-digits" data-ticket-digit>{{ $field['value'] }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        @if (! empty($lane['source_state']))
                            {{-- The source is stated, so a fixture is never
                                 mistaken for an official record. --}}
                            <p class="home-lanes__source">{{ $lane['source_state'] }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
