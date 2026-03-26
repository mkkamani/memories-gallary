<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Memories by Cypherox</title>
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
                            <h1 style="margin:20px 0 0 0;text-align:center;font-size:26px;line-height:1.2;font-weight:800;color:#111827;">Welcome to Memories by Cypherox</h1>
                            <div style="height:3px;width:86px;background:#ff5a1f;border-radius:999px;margin:14px auto 0 auto;"></div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 36px 34px 36px;">
                            <p style="margin:0 0 12px 0;font-size:20px;line-height:1.25;font-weight:700;color:#111827;">Hello {{ $name }},</p>
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.8;color:#374151;">
                                Your <b>Memories</b> account has been successfully created.
                            </p>
                            <p style="margin:0 0 22px 0;font-size:15px;line-height:1.8;color:#4b5563;">
                                You can now access and contribute to the albums and media shared with you.
                            </p>

                            <p style="margin:0 0 12px 0;font-size:15px;line-height:1.8;font-weight:700;color:#111827;">Login Details:</p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #ececec;border-radius:12px;overflow:hidden;background:#fafafa;">
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #ececec;font-size:15px;color:#6b7280;width:130px;">Email</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #ececec;font-size:15px;font-weight:600;color:#111827;">{{ $email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;font-size:15px;color:#6b7280;">Password</td>
                                    <td style="padding:14px 16px;font-size:13px;font-weight:700;color:#111827;letter-spacing:.2px;">{{ $password }}</td>
                                </tr>
                            </table>

                            <p style="margin:16px 0 0 0;padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;font-size:15px;line-height:1.6;color:#9a3412;">
                                For security reasons, we recommend that you log in and update your password from your profile settings as soon as possible.
                            </p>

                            <p style="margin:18px 0 0 0;font-size:15px;line-height:1.8;color:#4b5563;">Click the button below to get started:</p>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin-top:26px;">
                                <tr>
                                    <td>
                                        <a href="{{ $loginUrl }}" style="display:inline-block;background:#ff5a1f;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 22px;border-radius:999px;">
                                            Go to Login
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 0 0;font-size:15px;line-height:1.8;color:#4b5563;">Warm regards,<br><strong>Team Cypherox</strong></p>
                        </td>
                    </tr>
                </table>
                <p style="margin:14px 0 0 0;font-size:15px;line-height:1.6;color:#9ca3af;text-align:center;">
                    &copy; {{ date('Y') }} <b style="color:#4f4f4f;">Cypherox Technologies</b>. All Rights Reserved.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
