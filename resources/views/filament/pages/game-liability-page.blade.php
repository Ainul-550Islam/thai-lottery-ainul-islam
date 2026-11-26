<x-filament-panels::page>
    @php
        $games = $this->games();
        $withDraw = array_values(array_filter($games, static fn (array $row): bool => $row['draw_id'] !== null));
    @endphp

    <div style="display:flex;flex-direction:column;gap:1.5rem;">

        {{-- Header: what this page answers, in one sentence, so nobody reads the numbers as bets. --}}
        <div style="padding:1.5rem;border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;">
            <h2 style="margin:0;font-size:1.125rem;font-weight:700;">Reserved potential payout, per number</h2>
            <p style="margin:0.5rem 0 0;font-size:0.875rem;color:#4b5563;">
                These counters are what the platform will owe if a number wins, not what has been staked.
                A bet is accepted only while both the per-number and the per-draw ceiling have room,
                so the headroom below is the amount still sellable on each number.
            </p>
            @if (count($withDraw) === 0)
                <p style="margin:0.75rem 0 0;font-size:0.875rem;font-weight:600;color:#b45309;">
                    No live digit game draw is open right now, so there is no liability to report.
                </p>
            @endif
        </div>

        @foreach ($games as $game)
            <div style="border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;overflow:hidden;">

                {{-- Per-game summary --}}
                <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:baseline;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;">
                    <div>
                        <span style="font-size:1rem;font-weight:700;">{{ $game['name'] }}</span>
                        <span style="margin-left:0.5rem;font-size:0.75rem;font-family:ui-monospace,monospace;color:#6b7280;">{{ $game['code'] }}</span>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;">
                        @if ($game['sellable'])
                            <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#ecfdf5;color:#047857;font-weight:600;">sellable</span>
                        @else
                            <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#f3f4f6;color:#374151;font-weight:600;">not sellable: {{ $game['reason'] }}</span>
                        @endif

                        @if ($game['draw_number'] !== null)
                            <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#eff6ff;color:#1d4ed8;font-weight:600;">
                                draw {{ $game['draw_number'] }}
                            </span>
                            <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#f9fafb;color:#4b5563;">
                                scheduled {{ $game['draws_at'] }}
                            </span>
                        @endif
                    </div>
                </div>

                @if ($game['note'] !== null)
                    <div style="padding:1rem 1.25rem;font-size:0.875rem;color:#92400e;background:#fffbeb;">
                        {{ $game['note'] }}
                    </div>
                @else
                    {{-- Draw-level ceilings --}}
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;">
                        <div>
                            <div style="font-size:0.75rem;color:#6b7280;">Per-number ceiling</div>
                            <div style="font-size:1rem;font-weight:700;">{{ $game['per_number_cap'] }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem;color:#6b7280;">Per-draw ceiling</div>
                            <div style="font-size:1rem;font-weight:700;">{{ $game['per_draw_cap'] }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem;color:#6b7280;">Reserved on this draw</div>
                            <div style="font-size:1rem;font-weight:700;">{{ $game['draw_reserved'] }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.75rem;color:#6b7280;">Headroom on this draw</div>
                            <div style="font-size:1rem;font-weight:700;color:{{ $game['draw_remaining'] === '0.00' ? '#b91c1c' : '#047857' }};">
                                {{ $game['draw_remaining'] }}
                            </div>
                        </div>
                    </div>

                    @if (count($game['numbers']) === 0)
                        <div style="padding:1rem 1.25rem;font-size:0.875rem;color:#4b5563;">
                            The draw is open and no bet has been placed on any number yet.
                        </div>
                    @else
                        {{-- Concentrations, worst first --}}
                        <div style="overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                                <thead>
                                    <tr style="text-align:left;background:#f9fafb;color:#374151;">
                                        <th style="padding:0.6rem 1.25rem;font-weight:600;">Market / number</th>
                                        <th style="padding:0.6rem 1rem;font-weight:600;text-align:right;">Reserved payout</th>
                                        <th style="padding:0.6rem 1rem;font-weight:600;text-align:right;">Headroom</th>
                                        <th style="padding:0.6rem 1.25rem;font-weight:600;">Room left</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($game['numbers'] as $number)
                                        <tr style="border-top:1px solid #f3f4f6;">
                                            <td style="padding:0.6rem 1.25rem;font-family:ui-monospace,monospace;">{{ $number['selection'] }}</td>
                                            <td style="padding:0.6rem 1rem;text-align:right;font-weight:600;">{{ $number['reserved'] }}</td>
                                            <td style="padding:0.6rem 1rem;text-align:right;color:{{ $number['remaining_raw'] === '0.00' ? '#b91c1c' : '#047857' }};">
                                                {{ $number['remaining'] }}
                                            </td>
                                            <td style="padding:0.6rem 1.25rem;">
                                                @if ($number['remaining_raw'] === '0.00')
                                                    <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#fef2f2;color:#b91c1c;font-weight:600;">at ceiling</span>
                                                @else
                                                    <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#ecfdf5;color:#047857;font-weight:600;">open</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach

        {{-- The counter is a risk view, not a control. Say so on the page. --}}
        <p style="font-size:0.75rem;color:#6b7280;margin:0;">
            Ceilings are operator configuration (config/lottery_games.php) and are not editable here.
            Reservations are released when a bet is cancelled; this page never changes a counter.
        </p>
    </div>
</x-filament-panels::page>
