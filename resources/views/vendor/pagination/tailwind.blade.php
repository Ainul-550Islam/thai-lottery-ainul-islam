{{--
    PUBLISHED FROM vendor/laravel/framework/.../Pagination/resources/views/tailwind.blade.php
    with all five strings namespaced under pagination.* and Luxury Dark-Gold WCAG 2.2 AA styling.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('pagination.navigation') }}">

        <div class="flex gap-2 items-center justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="inline-flex min-h-[44px] items-center px-4 py-2 text-sm font-bold text-[#9D937D] bg-[#0B0904] border border-[#D4AF37]/20 cursor-not-allowed leading-5 rounded-xl">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex min-h-[44px] items-center px-4 py-2 text-sm font-bold text-[#F5E6B8] bg-[#141007] border border-[#D4AF37]/35 leading-5 rounded-xl hover:border-[#D4AF37] hover:text-[#FFF6D6] transition">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex min-h-[44px] items-center px-4 py-2 text-sm font-bold text-[#F5E6B8] bg-[#141007] border border-[#D4AF37]/35 leading-5 rounded-xl hover:border-[#D4AF37] hover:text-[#FFF6D6] transition">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="inline-flex min-h-[44px] items-center px-4 py-2 text-sm font-bold text-[#9D937D] bg-[#0B0904] border border-[#D4AF37]/20 cursor-not-allowed leading-5 rounded-xl">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>

        <div class="hidden sm:flex-1 sm:flex sm:gap-3 sm:items-center sm:justify-between flex-wrap">
            <div>
                <p class="text-sm text-[#D4CAB3] leading-5">
                    {!! __('pagination.showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-bold font-mono text-[#FFF6D6]">{{ $paginator->firstItem() }}</span>
                        {!! __('pagination.to') !!}
                        <span class="font-bold font-mono text-[#FFF6D6]">{{ $paginator->lastItem() }}</span>
                    @else
                        <span class="font-bold font-mono text-[#FFF6D6]">{{ $paginator->count() }}</span>
                    @endif
                    {!! __('pagination.of') !!}
                    <span class="font-bold font-mono text-[#FFF6D6]">{{ $paginator->total() }}</span>
                    {!! __('pagination.results') !!}
                </p>
            </div>

            <div>
                <span class="inline-flex rtl:flex-row-reverse rounded-xl overflow-hidden border border-[#D4AF37]/25 bg-[#141007]">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                            <span class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3 py-2 text-sm font-bold text-[#9D937D] bg-[#0B0904] cursor-not-allowed leading-5" aria-hidden="true">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3 py-2 text-sm font-bold text-[#F5E6B8] hover:bg-[#1C170B] hover:text-[#FFF6D6] transition leading-5" aria-label="{{ __('pagination.previous') }}">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span aria-disabled="true">
                                <span class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3 py-2 border-l border-[#D4AF37]/20 text-sm font-bold text-[#9D937D] cursor-default leading-5">{{ $element }}</span>
                            </span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page">
                                        <span class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3.5 py-2 border-l border-[#D4AF37]/30 text-sm font-black font-mono text-[#0B0904] bg-[#D4AF37] cursor-default leading-5">{{ $page }}</span>
                                    </span>
                                @else
                                    <a href="{{ $url }}" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3.5 py-2 border-l border-[#D4AF37]/20 text-sm font-bold font-mono text-[#FAF6EC] hover:bg-[#1C170B] hover:text-[#FFF6D6] transition leading-5" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3 py-2 border-l border-[#D4AF37]/20 text-sm font-bold text-[#F5E6B8] hover:bg-[#1C170B] hover:text-[#FFF6D6] transition leading-5" aria-label="{{ __('pagination.next') }}">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                            <span class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center px-3 py-2 border-l border-[#D4AF37]/20 text-sm font-bold text-[#9D937D] bg-[#0B0904] cursor-not-allowed leading-5" aria-hidden="true">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
