@php
    /** @var \App\Services\Marketing\SocialProofService $socialProofService */
    $socialProofService = app(\App\Services\Marketing\SocialProofService::class);
    $proof = $socialProofService->publicFeed(auth()->user(), app()->getLocale());
@endphp

@if ($proof['visible'])
    <section aria-labelledby="social-proof-heading" class="home-section mt-8" data-social-proof>
        <div class="rounded-2xl border border-line bg-surface-1 p-6">
            <p class="home-eyebrow">{{ __('conversion.social_proof_eyebrow') }}</p>
            <h2 id="social-proof-heading" class="home-title mt-1">{{ __('conversion.social_proof_heading') }}</h2>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                @if ($proof['recent_wins'] !== [])
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-content-secondary">
                            {{ __('conversion.social_wins_title') }}
                        </h3>
                        <ul class="mt-3 divide-y divide-line rounded-xl border border-line bg-surface-0">
                            @foreach ($proof['recent_wins'] as $win)
                                <li class="flex items-center justify-between px-4 py-3 text-sm">
                                    <span class="font-medium text-content-strong">{{ $win['masked_member'] }}</span>
                                    <span class="font-semibold text-emerald-400">
                                        {{ $win['amount'] }} {{ $win['currency'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($proof['testimonials'] !== [])
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-content-secondary">
                            {{ __('conversion.social_testimonials_title') }}
                        </h3>
                        <div class="mt-3 space-y-3">
                            @foreach ($proof['testimonials'] as $item)
                                <blockquote class="rounded-xl border border-line bg-surface-0 p-4">
                                    <p class="text-sm text-content-primary">“{{ $item['quote'] }}”</p>
                                    <footer class="mt-2 flex items-center justify-between text-xs text-content-secondary">
                                        <span>{{ $item['display_name'] }}</span>
                                        <span aria-label="{{ $item['rating'] }} / 5">{{ str_repeat('★', $item['rating']) }}</span>
                                    </footer>
                                </blockquote>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <p class="mt-5 border-t border-line pt-4 text-xs text-content-secondary" data-social-proof-disclosure>
                {{ $proof['disclosure'] }}
            </p>
        </div>
    </section>
@endif
