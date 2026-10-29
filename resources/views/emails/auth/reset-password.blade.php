<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BRBI CIMS Password Reset</title>
</head>
<body style="margin:0;background:#f4f7f6;color:#18352f;font-family:Arial,sans-serif;line-height:1.6">
    <div style="max-width:620px;margin:0 auto;padding:32px 20px">
        <div style="background:#ffffff;border:1px solid #dbe6e2;border-radius:12px;padding:32px">
            <p style="margin:0 0 20px;font-size:20px;font-weight:700">BRBI CIMS</p>
            <p>Hello{{ $recipientName !== '' ? ' '.$recipientName : '' }},</p>
            <p>We received a request to reset the password for your BRBI Credit Investigation Management System account.</p>
            <p style="margin:28px 0">
                <a href="{{ $resetUrl }}" style="display:inline-block;border-radius:8px;background:#166b55;color:#ffffff;padding:12px 20px;text-decoration:none;font-weight:700">Reset Password</a>
            </p>
            <p>This secure link expires in {{ $expirationMinutes }} minutes and can be used only once.</p>
            <p>If you did not request a password reset, you can safely ignore this email.</p>
            <p style="margin-bottom:0">Regards,<br>BRBI CIMS</p>
        </div>
    </div>
</body>
</html>
