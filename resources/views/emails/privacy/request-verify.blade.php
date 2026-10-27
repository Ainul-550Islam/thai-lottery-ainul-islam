{{--
    Privacy request — verification mail.

    WHAT THIS TEMPLATE MAY PRINT: the reference, the request type, the signed
    link and the expiry. Nothing else. No name, no account id, no statement
    about whether the address has an account, no IP address and no operator
    name. This message is sent to an address that may belong to somebody with
    no account at all, and it is forwarded, printed and quoted.

    EVERY VALUE IS ESCAPED. {{ }} in Blade escapes by default and nothing here
    uses {!! !!}. The URL is rendered as text so that, even if a mail client
    refuses to link it, the recipient can read it.

    NO EXTERNAL ASSET. Plain HTML with inline styles: a remote logo would tell
    a third party every time this mail is opened.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ trans('privacy_requests.verify.title', [], $locale) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Helvetica,Arial,sans-serif;color:#14181f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dfe4ea;border-radius:6px;">
                <tr>
                    <td style="padding:24px 28px 8px 28px;">
                        <h1 style="margin:0;font-size:18px;line-height:1.35;">{{ trans('privacy_requests.mail.verify.subject', ['reference' => $reference], $locale) }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 0 28px;font-size:14px;line-height:1.55;">
                        <p style="margin:0 0 14px 0;">{{ trans('privacy_requests.mail.verify.intro', ['app' => $appName], $locale) }}</p>

                        <p style="margin:0 0 6px 0;"><strong>{{ trans('privacy_requests.mail.verify.type_label', [], $locale) }}:</strong> {{ $typeLabel }}</p>
                        <p style="margin:0 0 14px 0;"><strong>{{ trans('privacy_requests.verify.reference_label', [], $locale) }}:</strong> {{ $reference }}</p>

                        <p style="margin:0 0 10px 0;">{{ trans('privacy_requests.mail.verify.link_intro', [], $locale) }}</p>

                        <p style="margin:0 0 14px 0;word-break:break-all;">
                            <a href="{{ $verifyUrl }}" style="color:#0f62fe;">{{ $verifyUrl }}</a>
                        </p>

                        <p style="margin:0 0 14px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.verify.link_expiry', ['expires' => $expiresAt], $locale) }}</p>

                        <p style="margin:0 0 14px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.verify.ignore', [], $locale) }}</p>

                        <p style="margin:0 0 6px 0;color:#4a5568;font-size:13px;">{{ trans('privacy_requests.mail.verify.no_documents', [], $locale) }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px 24px 28px;border-top:1px solid #eef1f5;margin-top:18px;color:#7a8699;font-size:12px;line-height:1.5;">
                        {{ $appName }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
