@php
    $signIn = rtrim((string) config('services.frontend.url'), '/').'/login';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your Mackie17 sign-in code</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border:1px solid #e6e8ec;border-radius:12px;">
          <tr>
            <td style="padding:28px 32px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
              <p style="margin:0;font-size:13px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#6b7280;">Mackie17</p>
              <h1 style="margin:16px 0 0;font-size:22px;line-height:1.3;font-weight:600;color:#111827;">Your sign-in code</h1>
              <p style="margin:12px 0 0;font-size:15px;line-height:1.55;color:#374151;">
                Enter this code to finish signing in. It expires in {{ $minutes }} minutes.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 32px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:12px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#6b7280;">Copy this code</p>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #e6e8ec;border-radius:10px;">
                <tr>
                  <td align="center" style="padding:18px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:28px;font-weight:600;letter-spacing:0.22em;color:#111827;-webkit-user-select:all;user-select:all;">
                    {{ $code }}
                  </td>
                </tr>
              </table>
              <p style="margin:10px 0 0;font-size:13px;line-height:1.5;color:#6b7280;text-align:center;">
                Select the code above, then copy it. On a phone, press and hold the code and choose Copy.
              </p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:20px 32px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
              <a href="{{ $signIn }}" style="display:inline-block;padding:12px 22px;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;">Continue to sign in</a>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 32px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
              <p style="margin:0;font-size:14px;line-height:1.55;color:#111827;">
                <strong>Do not share this code with anyone.</strong>
                Mackie17 will never ask you for it by email, phone, or message.
              </p>
              <p style="margin:12px 0 0;font-size:13px;line-height:1.55;color:#6b7280;">
                If you did not request this code, you can ignore this email. Your account will not change.
              </p>
            </td>
          </tr>
        </table>
        <p style="margin:16px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#9ca3af;">
          Mackie17 · This message was sent because a sign-in code was requested for your email.
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
