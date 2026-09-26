{{-- Core values grid: this platform's own framework (not a competitor's). --}}
<section class="pp-section" aria-labelledby="pp-values-title" data-pp-section="core-values">
    <div class="pp-section__inner">
        <h2 class="pp-section__title" id="pp-values-title">{{ $title }}</h2>
        <ul class="pp-values">
            @foreach ($values as $value)
                <li class="pp-values__item" data-pp-value="{{ $value['key'] }}">
                    <h3 class="pp-values__label">{{ $value['label'] }}</h3>
                    <p class="pp-values__text">{{ $value['text'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>
