<x-filament-panels::page>
    @php
        $games = $this->games();
        $ceiling = $this->ceiling();
        $master = $this->masterEnabled();
    @endphp

    <div style="display:flex;flex-direction:column;gap:1.5rem;">

        {{-- Deployment state first: everything below is read in the light of these two facts. --}}
        <div style="padding:1.25rem 1.5rem;border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;">
            <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;justify-content:space-between;">
                <div>
                    <h2 style="margin:0;font-size:1.125rem;font-weight:700;">Rules &amp; payback report</h2>
                    <p style="margin:0.4rem 0 0;font-size:0.875rem;color:#4b5563;">
                        A game sells only while the deployed rule set still matches the checksum an operator verified.
                        RTP is computed from the configured multipliers, not assumed.
                    </p>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;font-size:0.75rem;">
                    <span style="padding:0.2rem 0.7rem;border-radius:9999px;font-weight:600;
                        background:{{ $master ? '#ecfdf5' : '#fef2f2' }};
                        color:{{ $master ? '#047857' : '#b91c1c' }};">
                        master switch {{ $master ? 'open' : 'closed' }}
                    </span>
                    <span style="padding:0.2rem 0.7rem;border-radius:9999px;background:#eff6ff;color:#1d4ed8;font-weight:600;">
                        RTP ceiling {{ $ceiling }}
                    </span>
                </div>
            </div>
        </div>

        @foreach ($games as $game)
            @php
                $verdictColor = match ($game['verdict']) {
                    'matches deployed rules' => ['#ecfdf5', '#047857'],
                    'DRIFTED' => ['#fef2f2', '#b91c1c'],
                    default => ['#fffbeb', '#b45309'],
                };
                $hasBlockers = $game['missing'] !== [] || $game['problems'] !== [];
            @endphp

            <div style="border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;overflow:hidden;">

                <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:baseline;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;">
                    <div>
                        <span style="font-size:1rem;font-weight:700;">{{ $game['name'] }}</span>
                        <span style="margin-left:0.5rem;font-size:0.75rem;font-family:ui-monospace,monospace;color:#6b7280;">{{ $game['code'] }}</span>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;">
                        <span style="padding:0.2rem 0.7rem;border-radius:9999px;font-weight:600;background:{{ $verdictColor[0] }};color:{{ $verdictColor[1] }};">
                            {{ $game['verdict'] }}
                        </span>
                        <span style="padding:0.2rem 0.7rem;border-radius:9999px;background:#f9fafb;color:#374151;font-weight:600;">
                            rules {{ $game['status'] }}
                        </span>
                        <span style="padding:0.2rem 0.7rem;border-radius:9999px;font-weight:600;
                            background:{{ $game['enabled'] ? '#ecfdf5' : '#f3f4f6' }};
                            color:{{ $game['enabled'] ? '#047857' : '#4b5563' }};">
                            {{ $game['enabled'] ? 'selling enabled' : 'switched off' }}
                        </span>
                        @if ($game['reason'] !== null)
                            <span style="padding:0.2rem 0.7rem;border-radius:9999px;background:#fffbeb;color:#92400e;font-weight:600;">
                                {{ $game['reason'] }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Checksums: the evidence. Both values, always, so drift is self-explanatory. --}}
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;font-size:0.8rem;">
                    <div>
                        <div style="color:#6b7280;">Checksum verified by an operator</div>
                        <div style="font-family:ui-monospace,monospace;word-break:break-all;">
                            {{ $game['checksum_stored'] ?? '— never verified —' }}
                        </div>
                    </div>
                    <div>
                        <div style="color:#6b7280;">Checksum of the deployed rules</div>
                        <div style="font-family:ui-monospace,monospace;word-break:break-all;">
                            {{ $game['checksum_current'] ?? '— not readable —' }}
                        </div>
                    </div>
                    <div>
                        <div style="color:#6b7280;">Verified at</div>
                        <div>{{ $game['verified_at'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div style="color:#6b7280;">Verified by (operator id)</div>
                        <div>{{ $game['verified_by'] ?? '—' }}</div>
                    </div>
                </div>

                @if ($hasBlockers)
                    <div style="padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#fffbeb;font-size:0.8rem;">
                        @if ($game['missing'] !== [])
                            <div style="font-weight:700;color:#92400e;">Required inputs still owed by the operator</div>
                            <ul style="margin:0.35rem 0 0.75rem;padding-left:1.2rem;color:#78350f;font-family:ui-monospace,monospace;">
                                @foreach ($game['missing'] as $field)
                                    <li>{{ $field }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($game['problems'] !== [])
                            <div style="font-weight:700;color:#92400e;">Rule set contradictions</div>
                            <ul style="margin:0.35rem 0 0;padding-left:1.2rem;color:#78350f;">
                                @foreach ($game['problems'] as $problem)
                                    <li>{{ $problem }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                @if ($game['warnings'] !== [])
                    <div style="padding:0.75rem 1.25rem;border-bottom:1px solid #f3f4f6;background:#f9fafb;font-size:0.8rem;color:#4b5563;">
                        <span style="font-weight:600;">Warnings:</span>
                        {{ implode(' · ', $game['warnings']) }}
                    </div>
                @endif

                @if (count($game['bet_types']) === 0)
                    <div style="padding:1rem 1.25rem;font-size:0.875rem;color:#4b5563;">
                        No market could be priced for this game, so there is no RTP to report.
                    </div>
                @else
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                            <thead>
                                <tr style="text-align:left;background:#f9fafb;color:#374151;">
                                    <th style="padding:0.6rem 1.25rem;font-weight:600;">Market</th>
                                    <th style="padding:0.6rem 1rem;font-weight:600;text-align:right;">Win probability</th>
                                    <th style="padding:0.6rem 1rem;font-weight:600;text-align:right;">Multiplier</th>
                                    <th style="padding:0.6rem 1rem;font-weight:600;text-align:right;">RTP</th>
                                    <th style="padding:0.6rem 1.25rem;font-weight:600;">Against ceiling {{ $ceiling }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($game['bet_types'] as $betType)
                                    <tr style="border-top:1px solid #f3f4f6;">
                                        <td style="padding:0.6rem 1.25rem;font-family:ui-monospace,monospace;">{{ $betType['key'] }}</td>
                                        <td style="padding:0.6rem 1rem;text-align:right;">{{ $betType['probability'] ?? '—' }}</td>
                                        <td style="padding:0.6rem 1rem;text-align:right;">{{ $betType['multiplier'] ?? 'REQUIRED_INPUT' }}</td>
                                        <td style="padding:0.6rem 1rem;text-align:right;font-weight:600;">{{ $betType['rtp'] ?? '—' }}</td>
                                        <td style="padding:0.6rem 1.25rem;">
                                            @if ($betType['multiplier'] === null)
                                                <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#fffbeb;color:#b45309;font-weight:600;">not priced</span>
                                            @elseif ($betType['breaches'])
                                                <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#fef2f2;color:#b91c1c;font-weight:600;">breaches ceiling</span>
                                            @else
                                                <span style="padding:0.15rem 0.6rem;border-radius:9999px;background:#ecfdf5;color:#047857;font-weight:600;">within ceiling</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        <p style="font-size:0.75rem;color:#6b7280;margin:0;">
            Verification is a write and belongs on the game itself, where one rule set is accepted at a time —
            this report has no verify button on purpose.
        </p>
    </div>
</x-filament-panels::page>
