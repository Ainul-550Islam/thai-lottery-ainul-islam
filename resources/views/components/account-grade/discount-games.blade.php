{{--
    Accessible game-entitlement renderer for one grade tier.

    Renders the tier's eligible-game list as a keyboard-reachable,
    dialog-associable block:
      - the host page wires the toggle button (grade-tier partial) to
        this block's id;
      - native <details>/<summary> semantics keep it operable without
        JavaScript;
      - a visible close control with Escape handling is provided for the
        dialog pattern (hidden until opened by the page's toggle logic);
      - the list itself is a semantic <ul>; every game name arrives
        pre-resolved from the projection service.

    NO VALUES ARE CALCULATED HERE and nothing is hardcoded: the games,
    their labels and the tier identity all arrive as data.
--}}
<div id="grade-games-{{ $tier['key'] }}"
     class="grade-games"
     data-grade-games="{{ $tier['key'] }}"
     data-grade-game-count="{{ $tier['eligible_game_count'] }}"
     hidden
>
    <div class="grade-games__panel" role="group" aria-labelledby="grade-games-title-{{ $tier['key'] }}">
        <h3 class="grade-games__title" id="grade-games-title-{{ $tier['key'] }}">
            {{ trans('account_info.discount_of_game_title', ['grade' => $tier['name']]) }}
        </h3>

        <p class="grade-games__lead">
            {{ trans('account_info.discount_of_game_lead', ['percent' => $tier['discount_display']]) }}
        </p>

        @if (($tier['eligible_games'] ?? []) === [])
            <p class="grade-games__empty">{{ trans('account_info.discount_of_game_empty') }}</p>
        @else
            <ul class="grade-games__list">
                @foreach ($tier['eligible_games'] as $game)
                    <li class="grade-games__item" data-grade-game="{{ $game['key'] }}" data-grade-game-lottery="{{ $game['lottery'] }}">
                        {{ $game['label'] }}
                    </li>
                @endforeach
            </ul>
        @endif

        <button type="button"
                class="grade-games__close"
                data-grade-games-close
                aria-label="{{ trans('account_info.discount_of_game_close') }}"
        >
            {{ trans('account_info.discount_of_game_close') }}
        </button>
    </div>
</div>
