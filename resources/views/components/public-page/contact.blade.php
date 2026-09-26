{{-- Contact block: routes to the existing public contact page. --}}
<section class="pp-section pp-section--muted" aria-labelledby="pp-contact-title" data-pp-section="contact">
    <div class="pp-section__inner">
        <h2 class="pp-section__title" id="pp-contact-title">{{ $title }}</h2>
        <p class="pp-section__text">{{ $text }}</p>
        <p class="pp-section__action">
            <a class="pp-btn" href="{{ $href }}">{{ $title }}</a>
        </p>
    </div>
</section>
