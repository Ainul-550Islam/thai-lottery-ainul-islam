<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #0f172a; color: #f8fafc; border-radius: 16px; padding: 32px; border: 1px solid #1e293b;">
    <div style="margin-bottom: 24px; text-align: center;">
        <span style="display: inline-block; padding: 6px 12px; background-color: rgba(56, 189, 248, 0.15); color: #38bdf8; border-radius: 9999px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em;">
            Identity Verification
        </span>
        <h1 style="font-size: 24px; font-weight: 800; color: #ffffff; margin-top: 12px; margin-bottom: 4px;">
            @if(($status ?? '') === 'approved' || ($status ?? '') === 'verified')
                Identity Verified Successfully
            @elseif(($status ?? '') === 'rejected')
                Verification Action Required
            @else
                Verification Document Received
            @endif
        </h1>
    </div>

    <div style="background-color: #020617; border-radius: 12px; padding: 20px; border: 1px solid #1e293b; margin-bottom: 24px;">
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Account User:</td>
                <td style="color: #f8fafc; font-weight: bold; text-align: right; padding: 6px 0;">{{ $user_name ?? 'Player' }}</td>
            </tr>
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Verification Status:</td>
                <td style="color: {{ ($status ?? '') === 'approved' || ($status ?? '') === 'verified' ? '#10b981' : (($status ?? '') === 'rejected' ? '#f43f5e' : '#38bdf8') }}; font-weight: bold; text-align: right; text-transform: uppercase; padding: 6px 0;">
                    {{ $status ?? 'Under Review' }}
                </td>
            </tr>
            @if(isset($reason) && $reason)
            <tr>
                <td style="color: #94a3b8; padding: 6px 0;">Review Notes:</td>
                <td style="color: #fb7185; text-align: right; padding: 6px 0;">{{ $reason }}</td>
            </tr>
            @endif
        </table>
    </div>

    <p style="font-size: 13px; color: #94a3b8; line-height: 1.6; margin-bottom: 24px;">
        @if(($status ?? '') === 'approved' || ($status ?? '') === 'verified')
            Your submitted documents have been approved by our compliance department. Your account now holds full unconstrained betting, deposit, and fast withdrawal privileges.
        @elseif(($status ?? '') === 'rejected')
            We could not verify your documents. Please ensure your photo identification is clearly visible, within its validity period, and not cropped. You may re-upload at any time.
        @else
            We have securely received your verification document package. Our compliance team verifies documents within 24 hours.
        @endif
    </p>

    <div style="text-align: center;">
        <a href="{{ url('/player/verification') }}" style="display: inline-block; padding: 12px 28px; background-color: #0284c7; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 12px;">
            Go to Verification Center
        </a>
    </div>

    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #1e293b; text-align: center; font-size: 11px; color: #64748b;">
        &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}. Identity verification is conducted in accordance with international AML/CTF standards.
    </div>
</div>
