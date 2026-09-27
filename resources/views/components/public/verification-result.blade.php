@props([
    'result' => null,
])

{{--
    Public verification verdict card (PROMPT 4).

    RENDERS ONLY THE PUBLIC VOCABULARY. $result comes from
    App\Services\Lottery\PrizeVerificationService, which builds its array with a
    key-by-key allow-list. This component therefore cannot render a field the
    service did not deliberately publish: there is no loop over arbitrary keys
    anywhere below, and no model is reachable from here.

    NO PII, EVER. Owner name, contact detail, payment detail, claim narrative,
    freeze reason and settlement figures are absent from the service payload by
    construction, so they are absent here.
--}}

@php
    $statusKeyMap = [
        'NOT_FOUND' => 'not_found',
        'FOUND_NOT_WINNING' => 'found_not_winning',
        'WINNING' => 'winning',
        'PAYMENT_HOLD' => 'payment_hold',
        'PAID' => 'paid',
        'INVALID' => 'invalid',
        'UNAVAILABLE' => 'unavailable',
    ];

    $authenticityKeyMap = [
        'DIGITAL_RECORD_VERIFIED' => 'digital_record_verified',
        'PUBLIC_RECORD_FOUND' => 'public_record_found',
        'NOT_VERIFIED' => 'not_verified',
        'REVOKED' => 'revoked',
    ];

    $status = (string) ($result['status'] ?? 'UNAVAILABLE');
    $slug = $statusKeyMap[$status] ?? 'unavailable';

    $authState = (string) (data_get($result, 'authenticity.state') ?? 'NOT_VERIFIED');
    $authSlug = $authenticityKeyMap[$authState] ?? 'not_verified';

    $reasonKey = $result['reason'] ?? null;
    $reasonTransKey = $reasonKey !== null
        ? 'prize_discount.reason_'.strtolower((string) $reasonKey)
        : null;
@endphp

<section
    class="pd-result pd-result--{{ strtolower(str_replace('_', '-', $status)) }}"
    data-pd-result="{{ $status }}"
    data-pd-official-source="{{ ($result['official_source'] ?? false) ? 'true' : 'false' }}"
    aria-live="polite"
>
    <h2 class="pd-result__heading">{{ trans('prize_discount.result_heading') }}</h2>

    <p class="pd-result__status" data-pd-status-label>
        <span class="pd-badge pd-badge--{{ $slug }}">{{ trans('prize_discount.status_'.$slug) }}</span>
    </p>

    <p class="pd-result__detail">{{ trans('prize_discount.status_'.$slug.'_detail') }}</p>

    @if ($reasonTransKey !== null && trans()->has($reasonTransKey))
        <p class="pd-result__reason">
            <strong>{{ trans('prize_discount.reason_heading') }}:</strong>
            {{ trans($reasonTransKey) }}
        </p>
    @endif

    <dl class="pd-result__facts">
        @if (! empty($result['query_echo']))
            <div class="pd-result__fact">
                <dt>{{ trans('prize_discount.result_query_echo') }}</dt>
                {{-- The echo is the SERVER-normalised value, never the raw input. --}}
                <dd class="pd-mono">{{ $result['query_echo'] }}</dd>
            </div>
        @endif

        <div class="pd-result__fact">
            <dt>{{ trans('prize_discount.result_product') }}</dt>
            <dd>{{ $result['product'] ?? trans('prize_discount.value_unavailable') }}</dd>
        </div>

        @if (data_get($result, 'draw.number') !== null)
            <div class="pd-result__fact">
                <dt>{{ trans('prize_discount.result_draw') }}</dt>
                <dd class="pd-mono">{{ data_get($result, 'draw.number') }}</dd>
            </div>
        @endif

        @if (data_get($result, 'draw.date') !== null)
            <div class="pd-result__fact">
                <dt>{{ trans('prize_discount.result_draw_date') }}</dt>
                <dd>{{ data_get($result, 'draw.date') }}</dd>
            </div>
        @endif

        @if (data_get($result, 'prize.category') !== null)
            <div class="pd-result__fact">
                <dt>{{ trans('prize_discount.result_prize_category') }}</dt>
                <dd>{{ data_get($result, 'prize.category') }}</dd>
            </div>
        @endif

        @if (data_get($result, 'prize.amount') !== null)
            <div class="pd-result__fact">
                <dt>{{ trans('prize_discount.result_prize_amount') }}</dt>
                {{-- Exact decimal string from the service; never re-formatted as a float here. --}}
                <dd class="pd-mono">{{ data_get($result, 'prize.amount') }}</dd>
            </div>
        @endif

        <div class="pd-result__fact">
            <dt>{{ trans('prize_discount.result_checked_at') }}</dt>
            <dd>{{ $result['verified_at'] ?? trans('prize_discount.value_unavailable') }}</dd>
        </div>

        @if (! empty($result['evidence_uuid']))
            <div class="pd-result__fact">
                <dt>{{ trans('prize_discount.result_reference') }}</dt>
                <dd class="pd-mono">{{ $result['evidence_uuid'] }}</dd>
            </div>
        @endif
    </dl>

    @php $matches = (array) data_get($result, 'prize.matches', []); @endphp

    <div class="pd-result__matches">
        <h3>{{ trans('prize_discount.result_matches') }}</h3>
        @if ($matches === [])
            <p class="pd-muted">{{ trans('prize_discount.result_no_matches') }}</p>
        @else
            <ul>
                @foreach ($matches as $match)
                    <li class="pd-mono">
                        @if (is_array($match))
                            {{ (string) ($match['category'] ?? $match['tier'] ?? '') }}
                            @if (isset($match['amount']))
                                — {{ (string) $match['amount'] }}
                            @endif
                        @else
                            {{ (string) $match }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="pd-result__authenticity" data-pd-authenticity="{{ $authState }}">
        <h3>{{ trans('prize_discount.authenticity_heading') }}</h3>
        <p><strong>{{ trans('prize_discount.authenticity_'.$authSlug) }}</strong></p>
        <p>{{ trans('prize_discount.authenticity_'.$authSlug.'_detail') }}</p>
        {{-- Stated on EVERY verdict, including a winning one: a database row
             cannot certify a piece of paper. --}}
        <p class="pd-disclaimer">{{ trans('prize_discount.authenticity_paper_disclaimer') }}</p>
    </div>

    @if (! empty($result['barcode']))
        @php
            $barcodeStateMap = [
                'SUPPORTED' => 'supported',
                'NOT_CONFIGURED' => 'not_configured',
                'UNSUPPORTED_FORMAT' => 'unsupported_format',
                'INVALID' => 'invalid',
            ];
            $barcodeState = (string) ($result['barcode']['state'] ?? 'INVALID');
            $barcodeSlug = $barcodeStateMap[$barcodeState] ?? 'invalid';
        @endphp
        <div class="pd-result__barcode" data-pd-barcode="{{ $barcodeState }}">
            <h3>{{ trans('prize_discount.barcode_heading') }}</h3>
            <p>{{ trans('prize_discount.barcode_'.$barcodeSlug) }}</p>
            @if (($result['fixture'] ?? false) === true)
                <p class="pd-disclaimer" data-pd-fixture="true">
                    {{ trans('prize_discount.barcode_fixture_notice') }}
                </p>
            @endif
        </div>
    @endif

    {{-- Hard-coded false in the service; rendered so the claim is visible and
         testable rather than merely absent. --}}
    <p class="pd-disclaimer pd-disclaimer--strong">{{ trans('prize_discount.not_official_notice') }}</p>
    <p class="pd-disclaimer">{{ trans('prize_discount.privacy_notice') }}</p>
</section>
