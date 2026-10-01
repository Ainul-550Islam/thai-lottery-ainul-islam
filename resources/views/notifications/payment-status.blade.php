<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #0f172a; color: #f8fafc; border-radius: 16px; padding: 32px; border: 1px solid #1e293b;">
    <div style="margin-bottom: 24px; text-align: center;">
        <span style="display: inline-block; padding: 6px 12px; background-color: rgba(16, 185, 129, 0.15); color: #10b981; border-radius: 9999px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em;">
            Payment Notification
        </span>
        <h1 style="font-size: 24px; font-weight: 800; color: #ffffff; margin-top: 12px; margin-bottom: 4px;">
            @if(($status ?? '') === 'completed')
                Deposit Confirmed
            @elseif(($status ?? '') === 'failed')
                Payment Unsuccessful
            @else
                Payment Update
            @endif
        </h1>
    </div>

    <div style="background-color: #020617; border-radius: 12px; padding: 20px; border: 1px solid #1e293b; margin-bottom: 24px;">
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Reference:</td>
                <td style="color: #f8fafc; font-family: monospace; font-weight: bold; text-align: right; padding: 6px 0;">{{ $reference ?? '—' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Amount:</td>
                <td style="color: #10b981; font-family: monospace; font-weight: bold; font-size: 16px; text-align: right; padding: 6px 0;">฿{{ number_format((float) ($amount ?? 0), 2) }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Channel:</td>
                <td style="color: #f8fafc; font-weight: bold; text-align: right; text-transform: uppercase; padding: 6px 0;">{{ $channel ?? 'Gateway' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Status:</td>
                <td style="color: #f59e0b; font-weight: bold; text-align: right; text-transform: uppercase; padding: 6px 0;">{{ $status ?? 'Pending' }}</td>
            </tr>
        </table>
    </div>

    <p style="font-size: 13px; color: #94a3b8; line-height: 1.6; margin-bottom: 24px;">
        @if(($status ?? '') === 'completed')
            Your funds have been securely credited to your platform wallet and are available immediately for wagering.
        @elseif(($status ?? '') === 'failed')
            Your payment could not be processed by the payment provider. No funds were debited from your account.
        @else
            Your transaction is currently being processed. You will receive an update once settlement is confirmed.
        @endif
    </p>

    <div style="text-align: center;">
        <a href="{{ url('/player/wallet') }}" style="display: inline-block; padding: 12px 28px; background-color: #059669; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 12px;">
            View Wallet Balance
        </a>
    </div>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #1e293b; text-align: center; font-size: 11px; color: #64748b;">
        &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}. All transactions are recorded in the immutable audit ledger.
    </div>
</div>
