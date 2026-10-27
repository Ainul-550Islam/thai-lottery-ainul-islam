{{--
    Privacy request — acknowledgement (sent only after verification).

    The due date is the row's own due_at, printed as a plain date. There is no
    promise about the outcome: some requests are answered with data, some with a
    reviewed refusal, and this mail must be true in both cases.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ trans('privacy_requests.mail.received.subject', ['reference' => $reference], $locale) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Helvetica,Arial,sans-serif;color:#14181f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dfe4ea;border-radius:6px;">
                <tr>
                    <td style="padding:24px 28px 8px 28px;">
                        <h1 style="margin:0;font-size:18px;line-height:1.35;">{{ trans('privacy_requests.mail.received.subject', ['reference' => $reference], $locale) }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 0 28px;font-size:14px;line-height:1.55;">
                        <p style="margin:0 0 14px 0;">{{ trans('privacy_requests.mail.received.intro', [], $locale) }}</p>

                        <p style="margin:0 0 6px 0;"><strong>{{ trans('privacy_requests.mail.received.type_label', [], $locale) }}:</strong> {{ $typeLabel }}</p>
                        <p style="margin:0 0 6px 0;"><strong>{{ trans('privacy_requests.verify.reference_label', [], $locale) }}:</strong> {{ $reference }}</p>

                        @if ($receivedAt !== '')
                            <p style="margin:0 0 6px 0;"><strong>{{ trans('privacy_requests.mail.received.received_label', [], $locale) }}:</strong> {{ $receivedAt }}</p>
                        @endif

                        @if ($dueAt !== '')
                            <p style="margin:0 0 14px 0;"><strong>{{ trans('privacy_requests.mail.received.due_label', [], $locale) }}:</strong> {{ $dueAt }}</p>
                        @endif

                        <p style="margin:0 0 14px 0;">{{ trans('privacy_requests.mail.received.next', [], $locale) }}</p>
                        <p style="margin:0 0 6px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.received.no_documents', [], $locale) }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px 24px 28px;border-top:1px solid #eef1f5;color:#7a8699;font-size:12px;line-height:1.5;">
                        {{ trans('privacy_requests.statuses.received', [], $locale) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
