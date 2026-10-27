{{-- Terms sections: versioned legal body rendered from TermsPageService data (no raw HTML). --}}
<section class="pp-section pp-terms" aria-labelledby="pp-terms-body-title" data-pp-section="terms-body">
    <div class="pp-section__inner">
        <h2 class="visually-hidden" id="pp-terms-body-title">{{ $title }}</h2>
        <div class="pp-terms__toc" data-pp-terms-toc>
            <nav aria-label="Terms sections">
                <ol class="pp-terms__toc-list">
                    @foreach ($sections as $section)
                        <li>
                            <a href="#terms-{{ $section['id'] }}">{{ $section['title'] }}</a>
                        </li>
                    @endforeach
                </ol>
            </nav>
        </div>

        <article class="pp-terms__article">
            @foreach ($sections as $section)
                <section class="pp-terms__section" id="terms-{{ $section['id'] }}" aria-labelledby="terms-{{ $section['id'] }}-title" data-pp-terms-section="{{ $section['id'] }}">
                    <h3 class="pp-terms__section-title" id="terms-{{ $section['id'] }}-title">{{ $section['title'] }}</h3>
                    <p class="pp-terms__section-body">{{ $section['body'] }}</p>
                    @foreach (($section['extra'] ?? []) as $extra)
                        <p class="pp-terms__section-body">{{ $extra }}</p>
                    @endforeach
                </section>
            @endforeach
        </article>

        @isset($operator)
            <section class="pp-section pp-section--muted" aria-labelledby="pp-operator-title" data-pp-section="operator">
                <h3 class="pp-section__title" id="pp-operator-title">{{ $operatorTitle }}</h3>
                <dl class="pp-operator">
                    <div><dt>{{ $operatorLabels['legal_name'] ?? 'Legal name' }}</dt><dd data-pp-operator="legal_name">{{ $operator['legal_name'] }}</dd></div>
                    <div><dt>{{ $operatorLabels['registration'] ?? 'Registration' }}</dt><dd data-pp-operator="registration_number">{{ $operator['registration_number'] }}</dd></div>
                    <div><dt>{{ $operatorLabels['email'] ?? 'Support email' }}</dt><dd data-pp-operator="support_email">{{ $operator['support_email'] }}</dd></div>
                    <div><dt>{{ $operatorLabels['phone'] ?? 'Support phone' }}</dt><dd data-pp-operator="support_phone">{{ $operator['support_phone'] }}</dd></div>
                    <div><dt>{{ $operatorLabels['address'] ?? 'Address' }}</dt><dd data-pp-operator="address">{{ $operator['address'] }}</dd></div>
                </dl>
            </section>
        @endisset
    </div>
</section>
