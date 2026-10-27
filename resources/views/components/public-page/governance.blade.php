{{-- Governance controls: only proven engineering controls; never absolute security claims. --}}
<section class="pp-section pp-section--muted" aria-labelledby="pp-gov-title" data-pp-section="governance">
    <div class="pp-section__inner">
        <h2 class="pp-section__title" id="pp-gov-title">{{ $governance['title'] }}</h2>
        <p class="pp-section__text">{{ $governance['text'] }}</p>
        <ul class="pp-gov">
            @foreach ($governance['controls'] as $control)
                <li class="pp-gov__item" data-pp-control="{{ $control['key'] }}">{{ $control['text'] }}</li>
            @endforeach
        </ul>
        <p class="pp-gov__disclaimer" data-pp-gov-disclaimer>{{ $governance['disclaimer'] }}</p>
    </div>
</section>
