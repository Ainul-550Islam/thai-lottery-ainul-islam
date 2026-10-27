{{--
    Compliance-desk SLA digest (internal).

    One line per request, each already localized by the job. Every value is
    escaped; there is no requester address and no free text from any request in
    this message.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ trans('privacy_requests.sla.subject', ['overdue' => '—', 'due_soon' => '—'], 'en') }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Helvetica,Arial,sans-serif;color:#14181f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #dfe4ea;border-radius:6px;">
                <tr>
                    <td style="padding:24px 28px 16px 28px;font-size:14px;line-height:1.6;">
                        @foreach ($lines as $line)
                            <div style="margin:0 0 4px 0;">{{ $line }}</div>
                        @endforeach

                        <p style="margin:18px 0 0 0;color:#4a5568;font-size:13px;">{{ $footer }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
