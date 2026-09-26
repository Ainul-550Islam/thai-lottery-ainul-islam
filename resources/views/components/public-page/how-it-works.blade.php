{{-- How it works: Choose → Buy → Result → Claim (reflects the real platform flow). --}}
<section class="pp-section" aria-labelledby="pp-how-title" data-pp-section="how-it-works">
    <div class="pp-section__inner">
        <h2 class="pp-section__title" id="pp-how-title">{{ $title }}</h2>
        <ol class="pp-steps">
            @foreach ($steps as $step)
                <li class="pp-steps__item" data-pp-step="{{ $step['key'] }}">
                    <span class="pp-steps__index" aria-hidden="true">{{ $step['index'] }}</span>
                    <div class="pp-steps__body">
                        <h3 class="pp-steps__label">{{ $step['label'] }}</h3>
                        <p class="pp-steps__text">{{ $step['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</section>
