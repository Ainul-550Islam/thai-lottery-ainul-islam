<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #0f172a; color: #f8fafc; border-radius: 16px; padding: 32px; border: 1px solid #1e293b;">
    <div style="margin-bottom: 24px; text-align: center;">
        <span style="display: inline-block; padding: 6px 12px; background-color: rgba(245, 158, 11, 0.15); color: #f59e0b; border-radius: 9999px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em;">
            Withdrawal Update
        </span>
        <h1 style="font-size: 24px; font-weight: 800; color: #ffffff; margin-top: 12px; margin-bottom: 4px;">
            @if(($status ?? '') === 'completed')
                Withdrawal Disbursed
            @elseif(($status ?? '') === 'rejected')
                Withdrawal Rejected
            @else
                Withdrawal Under Review
            @endif
        </h1>
    </div>

    <div style="background-color: #020617; border-radius: 12px; padding: 20px; border: 1px solid #1e293b; margin-bottom: 24px;">
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Withdrawal ID:</td>
                <td style="color: #f8fafc; font-family: monospace; font-weight: bold; text-align: right; padding: 6px 0;">{{ $reference ?? '—' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Recorded Amount:</td>
                <td style="color: #f59e0b; font-family: monospace; font-weight: bold; font-size: 16px; text-align: right; padding: 6px 0;">{{ isset($amount) ? \App\Support\Admin\AdminFormat::money($amount, $currency ?? null) : '—' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Destination:</td>
                <td style="color: #f8fafc; font-weight: bold; text-align: right; font-family: monospace; padding: 6px 0;">{{ $account_masked ?? 'Configured payout destination' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Current Status:</td>
                <td style="color: #38bdf8; font-weight: bold; text-align: right; text-transform: uppercase; padding: 6px 0;">{{ $status ?? 'Pending' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-size: 13px; color: #94a3b8; line-height: 1.6; margin-bottom: 24px;">
        @if(($status ?? '') === 'completed')
            The platform records this withdrawal as completed. Check your destination account or provider statement for receipt details; timing depends on the destination provider.
        @elseif(($status ?? '') === 'rejected')
            The withdrawal was rejected.
            @if (! empty($reason))
                Review note: {{ $reason }}.
            @endif
            Check your account for current wallet status and any next steps.
        @else
            The current withdrawal status is shown above. Check your account for the latest wallet information and instructions; no completion time is promised.
        @endif
    </p>

    <div style="text-align: center;">
        <a href="{{ url('/wallet') }}" style="display: inline-block; padding: 12px 28px; background-color: #d97706; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 12px;">
            Check Wallet History
        </a>
    </div>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #1e293b; text-align: center; font-size: 11px; color: #64748b;">
        &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}. Review the recorded withdrawal status and applicable account requirements.
    </div>
</div>
