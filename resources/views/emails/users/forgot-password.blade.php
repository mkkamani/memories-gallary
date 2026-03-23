<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName }} Password Reset</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Segoe UI,Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;background:#f4f4f5;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:14px;border:1px solid #e6e6e9;overflow:hidden;">
                    <tr>
                        <td style="padding:30px 36px 20px 36px;background:#ffffff;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center">
                                        <img src="{{ $logoUrl }}" alt="{{ $appName }}" width="300" style="display:block;height:auto;max-width:300px;">
                                    </td>
                                </tr>
                            </table>
                            <h1 style="margin:20px 0 0 0;text-align:center;font-size:26px;line-height:1.2;font-weight:800;color:#111827;">Password Reset</h1>
                            <div style="height:3px;width:86px;background:#ff5a1f;border-radius:999px;margin:14px auto 0 auto;"></div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 36px 34px 36px;">
                            <p style="margin:0 0 12px 0;font-size:20px;line-height:1.25;font-weight:700;color:#111827;">Hello {{ $name }},</p>
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.8;color:#374151;">
                                We received a request to reset your {{ $appName }} account password.
                            </p>

                            <p style="margin:0 0 22px 0;font-size:14px;line-height:1.8;color:#4b5563;">
                                Use the button below to set a new password. This reset link will expire in
                                <strong>{{ $expireMinutes }} minutes</strong>.
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin-top:2px;">
                                <tr>
                                    <td>
                                        <a href="{{ $resetUrl }}" style="display:inline-block;background:#ff5a1f;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:999px;">
                                            Reset Password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:18px 0 0 0;padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;font-size:13px;line-height:1.6;color:#9a3412;">
                                If you did not request this reset, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="margin:14px 0 0 0;font-size:12px;line-height:1.6;color:#9ca3af;text-align:center;">
                    &copy; {{ date('Y') }} <b style="color:#4f4f4f;">Cypherox Technologies</b>. All Rights Reserved.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
