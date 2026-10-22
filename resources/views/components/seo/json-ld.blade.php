@props([
    'data' => [],
])

@php
    /*
     * JSON-LD emitter (D2).
     *
     * One <script> block, one graph. A single block that describes the whole
     * page is easier for a crawler to resolve than several competing blocks,
     * and the @graph envelope lets the nodes cross-reference each other by
     * @id — WebSite declares Organization as its publisher rather than
     * repeating the organisation's name.
     *
     * JSON_HEX_TAG IS THE SECURITY BOUNDARY, NOT A FORMATTING CHOICE.
     * This is the only place in the app where translation strings and database
     * values are emitted inside a <script> element. Without JSON_HEX_TAG, an
     * answer or step containing the literal text "</script>" would terminate
     * the element early and everything after it would be parsed as markup —
     * a stored-XSS path through the CMS. The flag escapes < and > to their
     * \u003C / \u003E equivalents, which JSON decoders read back as the
     * original characters, so the data is unchanged and the element is safe.
     *
     * JSON_UNESCAPED_SLASHES keeps URLs readable in the source;
     * JSON_UNESCAPED_UNICODE keeps Thai and Bengali text readable instead of
     * escaping every character to \u0E01-style sequences.
     *
     * An empty graph emits NOTHING. Half-built schema published to Google is
     * worse than no schema.
     */

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
    $graph = is_array($data) ? $data : [];
@endphp

@if ($graph !== [])
<script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! json_encode($graph, $flags) !!}</script>
@endif
