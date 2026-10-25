{{--
    Support reply to a stored contact message.

    The operator's text is printed ESCAPED with newlines preserved, and the
    ticket reference is quoted so the visitor can look the ticket up. Nothing
    internal is here: no note, no classification, no other ticket, no operator
    identity.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ trans('support_tickets.mail.reply.subject', ['subject' => $subject, 'reference' => $reference], $locale) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Helvetica,Arial,sans-serif;color:#14181f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dfe4ea;border-radius:6px;">
                <tr>
                    <td style="padding:24px 28px 8px 28px;">
                        <h1 style="margin:0;font-size:18px;line-height:1.35;">{{ trans('support_tickets.mail.reply.subject', ['subject' => $subject, 'reference' => $reference], $locale) }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 0 28px;font-size:14px;line-height:1.55;">
                        <p style="margin:0 0 14px 0;">{{ trans('support_tickets.mail.reply.intro', ['subject' => $subject], $locale) }}</p>

                        <p style="margin:0 0 6px 0;"><strong>{{ trans('support_tickets.mail.reply.reference_label', [], $locale) }}:</strong> {{ $reference }}</p>

                        <div style="margin:0 0 16px 0;white-space:pre-line;background:#f7f9fc;border-left:3px solid #c9d1dc;padding:10px 12px;">{{ $body }}</div>

                        <p style="margin:0 0 6px 0;color:#4a5568;font-size:13px;">{{ trans('support_tickets.mail.reply.footer', [], $locale) }}</p>
                        <p style="margin:0 0 6px 0;word-break:break-all;">
                            <a href="{{ $contactUrl }}" style="color:#0f62fe;">{{ $contactUrl }}</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
