{{-- Footer for public informational/legal pages: nav landmarks + independent-platform legal line. --}}
<footer class="pp-footer" role="contentinfo">
    <div class="pp-footer__inner">
        <nav class="pp-footer__nav" aria-label="Footer">
            <a href="{{ route('home') }}">{{ trans('public_pages.back_to_home') }}</a>
            <a href="{{ route('results.index') }}">{{ trans('public_pages.useful_links_results') }}</a>
            <a href="{{ route('ticket-check') }}">{{ trans('public_pages.useful_links_ticket_check') }}</a>
            <a href="{{ route('about') }}">{{ trans('public_pages.about_meta_title') }}</a>
            <a href="{{ route('vision') }}">{{ trans('public_pages.vision_meta_title') }}</a>
            <a href="{{ route('terms') }}">{{ trans('public_pages.useful_links_terms') }}</a>
            <a href="{{ route('privacy') }}">{{ trans('public_pages.useful_links_privacy') }}</a>
            <a href="{{ route('contact') }}">{{ trans('public_pages.useful_links_contact') }}</a>
        </nav>
        <p class="pp-footer__legal">
            &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery Enterprise Wagering Platform') }}.
            Independent platform. GLO rules are referenced for compatibility only;
            this site is not the official GLO website and is not a government portal.
        </p>
    </div>
</footer>
