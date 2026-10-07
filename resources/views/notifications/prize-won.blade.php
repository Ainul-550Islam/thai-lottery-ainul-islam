<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #0f172a; color: #f8fafc; border-radius: 16px; padding: 32px; border: 1px solid #1e293b;">
    <div style="margin-bottom: 24px; text-align: center;">
        <span style="display: inline-block; padding: 6px 12px; background-color: rgba(245, 158, 11, 0.2); color: #fbbf24; border-radius: 9999px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">
            🎉 Congratulations!
        </span>
        <h1 style="font-size: 26px; font-weight: 900; color: #ffffff; margin-top: 12px; margin-bottom: 4px;">
            You Won a Lottery Prize!
        </h1>
        <p style="color: #94a3b8; font-size: 14px; margin: 0;">Your winning ticket has been validated and settled.</p>
    </div>

    <div style="background-color: #020617; border-radius: 12px; padding: 24px; border: 1px solid #334155; margin-bottom: 24px; text-align: center;">
        <span style="color: #94a3b8; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em;">Total Prize Amount</span>
        <div style="font-size: 36px; font-weight: 900; color: #10b981; font-family: monospace; margin-top: 4px; margin-bottom: 12px;">
            {{ isset($prize_amount) ? \App\Support\Admin\AdminFormat::money($prize_amount, $currency ?? null) : '—' }}
        </div>

        <table style="width: 100%; font-size: 13px; border-top: 1px solid #1e293b; padding-top: 12px; margin-top: 12px; border-collapse: collapse;">
            <tr>
                <td style="color: #94a3b8; text-align: left; padding: 4px 0;">Draw Number:</td>
                <td style="color: #f8fafc; font-weight: bold; text-align: right; padding: 4px 0;">#{{ $draw_number ?? '—' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; text-align: left; padding: 4px 0;">Ticket Number:</td>
                <td style="color: #f8fafc; font-family: monospace; font-weight: bold; text-align: right; padding: 4px 0;">{{ $ticket_number ?? '—' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; text-align: left; padding: 4px 0;">Winning Category:</td>
                <td style="color: #fbbf24; font-weight: bold; text-align: right; padding: 4px 0;">{{ $prize_category ?? 'Standard Tier' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-size: 13px; color: #94a3b8; line-height: 1.6; margin-bottom: 24px; text-align: center;">
        The prize amount has been credited directly to your primary wallet balance and is ready for withdrawal or future draws.
    </p>

    <div style="text-align: center;">
        <a href="{{ url('/player/payouts') }}" style="display: inline-block; padding: 12px 28px; background-color: #059669; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 12px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);">
            Claim &amp; View Payouts
        </a>
    </div>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #1e293b; text-align: center; font-size: 11px; color: #64748b;">
        &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}. Licensed official draw results.
    </div>
</div>
