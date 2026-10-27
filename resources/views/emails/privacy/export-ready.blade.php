{{--
    Privacy request — the archive is ready.

    THE ARCHIVE IS NOT ATTACHED. It is fetched through a signed, expiring,
    owner-bound URL, downloaded at most the stated number of times, and deleted
    afterwards. The mail states both limits so a later refusal is not a surprise.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ trans('privacy_requests.mail.export_ready.subject', ['reference' => $reference], $locale) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Helvetica,Arial,sans-serif;color:#14181f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dfe4ea;border-radius:6px;">
                <tr>
                    <td style="padding:24px 28px 8px 28px;">
                        <h1 style="margin:0;font-size:18px;line-height:1.35;">{{ trans('privacy_requests.mail.export_ready.subject', ['reference' => $reference], $locale) }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 0 28px;font-size:14px;line-height:1.55;">
                        <p style="margin:0 0 14px 0;">{{ trans('privacy_requests.mail.export_ready.intro', [], $locale) }}</p>

                        <p style="margin:0 0 6px 0;"><strong>{{ trans('privacy_requests.verify.reference_label', [], $locale) }}:</strong> {{ $reference }}</p>
                        <p style="margin:0 0 14px 0;">{{ trans('privacy_requests.mail.export_ready.link_intro', [], $locale) }}</p>

                        <p style="margin:0 0 14px 0;word-break:break-all;">
                            <a href="{{ $downloadUrl }}" style="color:#0f62fe;">{{ $downloadUrl }}</a>
                        </p>

                        <p style="margin:0 0 6px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.export_ready.link_expiry', ['expires' => $linkExpiresAt], $locale) }}</p>
                        <p style="margin:0 0 6px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.export_ready.limit', ['count' => $maxDownloads], $locale) }}</p>
                        <p style="margin:0 0 6px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.export_ready.warning', [], $locale) }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px 24px 28px;border-top:1px solid #eef1f5;color:#7a8699;font-size:12px;line-height:1.5;">
                        {{ $archiveExpiresAt }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
