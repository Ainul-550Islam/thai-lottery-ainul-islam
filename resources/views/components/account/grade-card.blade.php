{{-- Current grade card: presentation only; spend/grade computed server-side. --}}
<section class="acct-card acct-card--grade" aria-labelledby="grade-card-title" data-account-grade-card>
    <h2 class="acct-card__title" id="grade-card-title">{{ $title }}</h2>

    <div class="grade-badge-row">
        <span class="grade-badge grade-badge--{{ strtolower((string) ($grade['grade_key'] ?? 'unavailable')) }}"
              role="img"
              aria-label="{{ trans('account_services.grade_current') }}: {{ $grade['grade_name'] ?? trans('account_services.not_recorded') }}"
              data-grade-key="{{ $grade['grade_key'] ?? 'UNAVAILABLE' }}">
            {{ $grade['grade_name'] ?? trans('account_services.not_recorded') }}
        </span>
    </div>

    <dl class="acct-dl">
        <div>
            <dt>{{ trans('account_services.grade_period') }}</dt>
            <dd data-grade-period>
                {{ array_key_exists('period_days', $grade) ? str_replace('{days}', (string) $grade['period_days'], trans('account_services.grade_period_days')) : trans('account_services.not_recorded') }}
            </dd>
        </div>
        <div>
            <dt>{{ trans('account_services.grade_qualifying_spend') }}</dt>
            <dd class="grade-spend" data-grade-spend>
                @if (isset($grade['qualifying_spend']))
                    @php
                        $gradeCurrency = \App\Enums\Currency::tryFrom((string) ($grade['currency'] ?? config('account_grades.currency', 'THB'))) ?? \App\Enums\Currency::THB;
                    @endphp
                    <span class="grade-spend__value">{{ \App\Services\Finance\Money::of((string) $grade['qualifying_spend'], $gradeCurrency)->format() }}</span>
                @else
                    <span class="grade-spend__value">{{ trans('account_services.not_recorded') }}</span>
                @endif
                <span class="visually-hidden">({{ trans('account_services.grade_qualifying_spend') }})</span>
            </dd>
        </div>
        <div>
            <dt>{{ trans('account_services.grade_next_tier') }}</dt>
            <dd data-grade-next>
                @if (! empty($grade['next_tier']))
                    {{ $grade['next_tier']['name'] ?? trans('account_services.not_recorded') }}
                    @if (isset($grade['next_tier']['min_spend']))
                        ({{ \App\Services\Finance\Money::of((string) $grade['next_tier']['min_spend'], \App\Enums\Currency::THB)->format() }})
                    @endif
                @else
                    {{ trans('account_services.not_recorded') }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ trans('account_services.grade_col_calculated') }}</dt>
            <dd data-grade-calculated-at>{{ $grade['calculated_at'] ?? trans('account_services.not_recorded') }}</dd>
        </div>
    </dl>

    {{-- Progress: not color-only — numeric text always present. --}}
    @if (! empty($grade['next_tier']) && isset($grade['qualifying_spend'], $grade['next_tier']['min_spend']))
        @php
            $spend = (string) $grade['qualifying_spend'];
            $nextMin = (string) $grade['next_tier']['min_spend'];
            $pct = 0;
            if (is_numeric($nextMin) && bccomp($nextMin, '0', 2) > 0) {
                $ratio = bcdiv($spend, $nextMin, 4);
                $pct = min(100, max(0, intval(bcmul($ratio, '100', 0))));
            }
        @endphp
        <div class="grade-progress" role="group" aria-label="{{ trans('account_services.grade_progress') }}">
            <div class="grade-progress__bar"
                 role="progressbar"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-valuenow="{{ $pct }}"
                 aria-valuetext="{{ $pct }}%">
                <span class="grade-progress__fill" style="width: {{ $pct }}%"></span>
            </div>
            <p class="grade-progress__text">{{ $pct }}% — {{ \App\Services\Finance\Money::of($spend, \App\Enums\Currency::THB)->format() }} / {{ \App\Services\Finance\Money::of($nextMin, \App\Enums\Currency::THB)->format() }}</p>
        </div>
    @endif

    @if (! empty($canRefresh))
        <form method="POST" action="{{ $refreshAction }}" class="grade-refresh-form">
            @csrf
            <button type="submit" class="acct-btn acct-btn--ghost" data-grade-refresh>
                {{ trans('account_services.grade_refresh') }}
            </button>
        </form>
    @endif
</section>
