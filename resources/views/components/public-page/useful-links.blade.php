{{-- Useful links: only routes that exist on this application. --}}
<section class="pp-section" aria-labelledby="pp-links-title" data-pp-section="useful-links">
    <div class="pp-section__inner">
        <h2 class="pp-section__title" id="pp-links-title">{{ $title }}</h2>
        <p class="pp-section__text">{{ $text }}</p>
        <ul class="pp-links">
            @foreach ($links as $link)
                <li class="pp-links__item">
                    <a class="pp-links__anchor" href="{{ $link['href'] }}" data-pp-link="{{ $link['key'] }}">
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
