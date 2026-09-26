{{-- Shared page header for public informational/legal pages. Semantic h1 + skip target. --}}
<header class="pp-header" role="banner">
    <div class="pp-header__inner">
        <p class="pp-header__eyebrow" data-pp-eyebrow>{{ $eyebrow ?? '' }}</p>
        <h1 class="pp-header__title" id="pp-main-title">{{ $title }}</h1>
        @isset($lead)
            <p class="pp-header__lead">{{ $lead }}</p>
        @endisset
        @isset($version)
            <dl class="pp-header__meta" data-pp-meta>
                <div class="pp-header__meta-item">
                    <dt>{{ $versionLabel ?? 'Version' }}</dt>
                    <dd data-pp-version>{{ $version }}</dd>
                </div>
                @isset($effectiveAt)
                    <div class="pp-header__meta-item">
                        <dt>{{ $effectiveLabel ?? 'Effective date' }}</dt>
                        <dd data-pp-effective>{{ $effectiveAt }}</dd>
                    </div>
                @endisset
                @isset($updatedAt)
                    <div class="pp-header__meta-item">
                        <dt>{{ $updatedLabel ?? 'Last updated' }}</dt>
                        <dd data-pp-updated>{{ $updatedAt }}</dd>
                    </div>
                @endisset
            </dl>
        @endisset
    </div>
</header>
