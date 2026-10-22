{{--
    PUBLISHED FROM vendor/livewire/livewire/src/Features/SupportPagination/views/simple-bootstrap.blade.php
    Do not "tidy" this back to the vendor file.

    WHY THIS COPY EXISTS
    Livewire's SupportPagination swaps the paginator's default view for its own while it
    boots, so links() can be rendered by THIS file rather than by Laravel's. Laravel's copy
    was already published and fixed at resources/views/vendor/pagination/, but that fix did
    not cover this path, and the failure only appears once something in the process has
    triggered Livewire - which is why the same page renders fine in isolation and 500s in a
    full suite run.

    The stock file echoes the bare translation key "results" with unescaped blade braces.
    With no dot in the key, Laravel treats it as a whole language GROUP and loads
    lang/en/results.php - the lottery results page - so the paginator hands an array to the
    echo and dies with "Array to string conversion".

    It is worse than Laravel's version in one respect: the value is printed unescaped.
    Showing / to / of / results are plain words, so they now use escaped blade echo.
    previous and next keep unescaped echo because their translations contain HTML entities
    (the double angle quotation marks) that must render as glyphs, not as text.

    All four strings now resolve under the pagination group, defined in
    lang/en/pagination.php and lang/th/pagination.php, so they cannot collide with a page
    namespace again. TranslationGroupCollisionTest enforces that.
--}}
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav>
            <ul class="pagination">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link">@lang('pagination.previous')</span>
                    </li>
                @else
                    @if(method_exists($paginator,'getCursorName'))
                        <li class="page-item">
                            <button dusk="previousPage" type="button" class="page-link" wire:key="cursor-{{ $paginator->getCursorName() }}-{{ $paginator->previousCursor()->encode() }}" wire:click="setPage('{{$paginator->previousCursor()->encode()}}','{{ $paginator->getCursorName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">@lang('pagination.previous')</button>
                        </li>
                    @else
                        <li class="page-item">
                            <button type="button" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}" class="page-link" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">@lang('pagination.previous')</button>
                        </li>
                    @endif
                @endif

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    @if(method_exists($paginator,'getCursorName'))
                        <li class="page-item">
                            <button dusk="nextPage" type="button" class="page-link" wire:key="cursor-{{ $paginator->getCursorName() }}-{{ $paginator->nextCursor()->encode() }}" wire:click="setPage('{{$paginator->nextCursor()->encode()}}','{{ $paginator->getCursorName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">@lang('pagination.next')</button>
                        </li>
                    @else
                        <li class="page-item">
                            <button type="button" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}" class="page-link" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">@lang('pagination.next')</button>
                        </li>
                    @endif
                @else
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link">@lang('pagination.next')</span>
                    </li>
                @endif
            </ul>
        </nav>
    @endif
</div>
