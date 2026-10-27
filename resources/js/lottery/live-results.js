/**
 * Draw detail — published results board.
 *
 * WHAT THIS OWNS
 * The #live-results-board region of resources/views/player/draw-detail.blade.php,
 * which the server renders ONLY when a result has been published. If results are
 * pending, that element does not exist and this module does nothing at all.
 *
 * THE ONE RULE: NO INVENTED NUMBERS
 * Every figure on that board is server-rendered and stays exactly as rendered.
 * This module adds precisely one derived value, and it is derived from a number
 * already on the page rather than fetched or guessed: the 3-Digit Tod cell,
 * which the blade fills with the placeholder text "All Permutations", is
 * replaced with the actual distinct permutations of the published 3-digit top
 * result. Tod (โต๊ด) wins on any ordering of those digits — listing them is
 * arithmetic on a published number, not a claim about an unpublished one.
 *
 * WHY THERE IS NO POLLING HERE
 * GET /api/v1/draws/{draw}/results is behind `auth:sanctum` and this
 * application does not enable Sanctum's stateful-frontend middleware, so a
 * page session cannot read it. Rather than ship a polling loop that would
 * only ever produce 401s, this module accepts a `lottery:results-updated`
 * DOM event carrying a result payload. Whatever transport is added later —
 * broadcast, token fetch, or a server-sent stream — feeds that event and this
 * file does not change. Until then the board is simply correct and static.
 *
 * ACCESSIBILITY AND MOTION
 * The reveal is a staggered fade that is skipped entirely under
 * prefers-reduced-motion: reduce, and the board is announced politely once.
 */
(function () {
    'use strict';

    /**
     * Distinct permutations of a short digit string, in ascending order.
     * "112" yields 3 arrangements, not 6 — repeated digits do not create
     * distinct tod outcomes.
     */
    function distinctPermutations(digits) {
        var results = Object.create(null);

        function walk(prefix, rest) {
            if (rest.length === 0) {
                results[prefix] = true;

                return;
            }

            for (var i = 0; i < rest.length; i++) {
                walk(prefix + rest[i], rest.slice(0, i) + rest.slice(i + 1));
            }
        }

        walk('', digits);

        return Object.keys(results).sort();
    }

    function readDigits(element) {
        if (!element) {
            return '';
        }

        var text = (element.textContent || '').trim();

        return /^[0-9]+$/.test(text) ? text : '';
    }

    function renderTod(board) {
        var topCell = board.querySelector('[data-prize="3d_top"]');
        var todCell = board.querySelector('[data-prize="3d_tod"]');

        if (!todCell) {
            return;
        }

        var top = readDigits(topCell);

        if (top.length !== 3) {
            // No usable published top number: keep the server's own wording.
            todCell.setAttribute('data-tod-state', 'unavailable');

            return;
        }

        var permutations = distinctPermutations(top);

        todCell.textContent = permutations.length + (permutations.length === 1 ? ' way' : ' ways');
        todCell.setAttribute('data-tod-state', 'derived');
        todCell.setAttribute(
            'title',
            'Tod wins on any ordering of ' + top + ': ' + permutations.join(', ')
        );

        var existing = board.querySelector('[data-tod-permutations]');

        if (existing) {
            existing.remove();
        }

        var list = document.createElement('div');
        list.className = 'mt-1 text-[10px] font-mono text-slate-400 leading-relaxed break-words';
        list.setAttribute('data-tod-permutations', 'true');
        list.textContent = permutations.join(' · ');

        // Placed after the cell, inside the same card, so the card's own
        // layout and the server-rendered label are untouched.
        if (todCell.parentNode) {
            todCell.parentNode.appendChild(list);
        }
    }

    /**
     * Apply a result payload delivered by some future transport. Only keys
     * that are present and well-formed are written; anything else leaves the
     * server-rendered value in place.
     */
    function applyPayload(board, payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        var map = {
            first: payload.first_prize,
            '3d_top': payload.three_digits_top,
            '2d_top': payload.two_digits_top,
            '2d_bottom': payload.two_digits_bottom,
        };

        Object.keys(map).forEach(function (key) {
            var value = map[key];

            if (typeof value !== 'string' || !/^[0-9]+$/.test(value)) {
                return;
            }

            var cell = board.querySelector('[data-prize="' + key + '"]');

            if (cell) {
                cell.textContent = value;
            }
        });

        renderTod(board);
        board.setAttribute('data-results-updated-at', new Date().toISOString());
    }

    function reveal(board) {
        var prefersReduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var cells = board.querySelectorAll('[data-prize]');

        if (prefersReduced) {
            for (var i = 0; i < cells.length; i++) {
                cells[i].style.opacity = '1';
            }

            return;
        }

        for (var j = 0; j < cells.length; j++) {
            (function (cell, index) {
                cell.style.opacity = '0';
                cell.style.transition = 'opacity 240ms ease-out';

                window.setTimeout(function () {
                    cell.style.opacity = '1';
                }, 60 * index);
            })(cells[j], j);
        }
    }

    function init(board) {
        board.setAttribute('aria-live', 'polite');
        board.setAttribute('data-results-state', 'published');

        renderTod(board);
        reveal(board);

        document.addEventListener('lottery:results-updated', function (event) {
            applyPayload(board, event.detail);
        });

        board.setAttribute('data-live-results-ready', 'true');
    }

    function boot() {
        var boards = document.querySelectorAll('#live-results-board');

        for (var i = 0; i < boards.length; i++) {
            init(boards[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
