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
                <td style="color: #94a3b8; padding: 6px 0;">Disbursed Amount:</td>
                <td style="color: #f59e0b; font-family: monospace; font-weight: bold; font-size: 16px; text-align: right; padding: 6px 0;">฿{{ number_format((float) ($amount ?? 0), 2) }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Destination:</td>
                <td style="color: #f8fafc; font-weight: bold; text-align: right; font-family: monospace; padding: 6px 0;">{{ $account_masked ?? 'Bank Account' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Current Status:</td>
                <td style="color: #38bdf8; font-weight: bold; text-align: right; text-transform: uppercase; padding: 6px 0;">{{ $status ?? 'Pending' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-size: 13px; color: #94a3b8; line-height: 1.6; margin-bottom: 24px;">
        @if(($status ?? '') === 'completed')
            Your withdrawal has been successfully disbursed to your designated bank account. Depending on your banking institution, funds typically arrive within minutes.
        @elseif(($status ?? '') === 'rejected')
            Your withdrawal could not be approved: {{ $reason ?? 'Destination verification failed.' }}. The reserved funds have been restored to your playable wallet balance.
        @else
            Your payout request is undergoing dual-authorization review. We will notify you once disbursement has completed.
        @endif
    </p>

    <div style="text-align: center;">
        <a href="{{ url('/player/wallet') }}" style="display: inline-block; padding: 12px 28px; background-color: #d97706; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 12px;">
            Check Wallet History
        </a>
    </div>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #1e293b; text-align: center; font-size: 11px; color: #64748b;">
        &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}. All payout operations are bound by KYC compliance regulations.
    </div>
</div>
