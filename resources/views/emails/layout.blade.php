@php($siteName = \App\Support\Settings::siteName())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#F6F4EF;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F4EF;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                <tr>
                    <td style="padding:0 0 16px 0;">
                        {{-- The logo sits on a white panel (and the PNG itself is opaque white) so it stays legible on every background, dark mode included. --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border:1px solid #E6E1D8;border-radius:10px;">
                            <tr>
                                <td bgcolor="#FFFFFF" style="padding:20px 28px;border-radius:10px;">
                                    <img src="{{ asset('images/brand/statementra-logo-email.png') }}?v=2" width="200" height="56" alt="{{ $siteName }}" style="display:block;border:0;outline:none;text-decoration:none;height:auto;max-width:200px;font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#12403A;">
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="background:#FFFFFF;border:1px solid #E6E1D8;border-radius:10px;padding:36px 36px 28px 36px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#22322F;">
                        <div class="content">
                            {!! str_replace(
                                ['<p>', '<ul>', '<ol>', '<li>', '<strong>', '<a '],
                                ['<p style="margin:0 0 16px 0;">', '<ul style="margin:0 0 16px 0;padding-left:20px;">', '<ol style="margin:0 0 16px 0;padding-left:20px;">', '<li style="margin:0 0 6px 0;">', '<strong style="color:#12403A;">', '<a style="color:#12403A;" '],
                                $content
                            ) !!}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 8px 0 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7a76;text-align:center;">
                        {{ \App\Support\Settings::get('general.tagline') }}<br>
                        Need help? <a href="mailto:{{ \App\Support\Settings::supportEmail() }}" style="color:#12403A;">{{ \App\Support\Settings::supportEmail() }}</a><br>
                        &copy; {{ date('Y') }} {{ $siteName }}. Secure payments via Paystack.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
