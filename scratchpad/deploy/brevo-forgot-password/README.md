# BRBI CIMS Brevo forgot-password deployment package

Status: prepared locally; nothing deployed and no email sent.

Confirmed by the operator: Brevo sender `BRBI CIMS Support <cimsbinhiruralbankinc@gmail.com>` is
verified and a restricted API key is stored privately. Gmail-domain DKIM/DMARC warnings remain, so
the controlled delivery test is still required after deployment.

## Upload root and files

This mapping assumes `/htdocs/artisan` exists and `/htdocs` is the Laravel root. Stop if it is not.
Upload the contents of `package/htdocs/` to these exact destinations:

1. `/htdocs/app/Notifications/Channels/BrevoPasswordResetChannel.php` (new)
2. `/htdocs/app/Services/Email/BrevoTransactionalEmail.php` (new)
3. `/htdocs/config/brevo.php` (new)
4. `/htdocs/resources/views/emails/auth/reset-password.blade.php` (new)
5. `/htdocs/app/Actions/Authentication/ResetUserPassword.php` (replace; includes admin reset-token revocation)
6. `/htdocs/app/Notifications/Auth/ResetPasswordNotification.php` (replace)
7. `/htdocs/app/Http/Controllers/Auth/PasswordResetLinkController.php` (replace)

Upload new dependencies first (1-4), then replacements (5-7). Do not upload `.env`, `.env.example`,
tests, frontend assets, `vendor/`, migrations, routes, or any n8n file.

## Backup

Before uploading:

1. Export the production database from phpMyAdmin and retain the SQL file privately. This deployment
   has no SQL, but the backup preserves all user accounts and password-reset tokens.
2. Download the three existing files that will be replaced (items 5-7) into a dated private backup.
3. Download `/htdocs/.env` into that private backup. Never place it in this package or source control.
4. If `/htdocs/bootstrap/cache/config.php` exists, download it too and note that configuration is cached.
5. Record the existing n8n-related `.env` lines without changing them. Never paste their values into
   notes, chat, screenshots, or terminal output.

## Production environment configuration

Edit the existing production `.env` in place. Preserve every existing line, especially database,
session, n8n reminder, Gmail, and storage settings. Set only:

```dotenv
APP_URL=https://cis.kesug.com
BREVO_API_KEY=<private Brevo API key>
BREVO_SENDER_EMAIL=cimsbinhiruralbankinc@gmail.com
BREVO_SENDER_NAME="BRBI CIMS Support"
BREVO_TIMEOUT_SECONDS=10
```

Do not store the actual values in this package. If `/htdocs/bootstrap/cache/config.php` existed,
rename it to a dated `.bak` after the file and `.env` backups so Laravel reloads the new configuration.
Do not touch any other file in `bootstrap/cache/`.

## Sender preflight

The operator has confirmed the selected sender as verified. Optionally recheck immediately before
upload with `operator-tools/verify-brevo-sender.ps1`; it prompts privately for the API key and makes
read-only GET requests only. Required result: `SENDER_FOUND=true` and `SENDER_ACTIVE=True`. The Gmail
domain is not BRBI-controlled, so its DKIM/DMARC warnings are recorded rather than treated as a
successful custom-domain authentication check. The script never prints the key or sender address.

## Verification and controlled test design

Before the email test, load the login and forgot-password pages normally and confirm no 500 errors.
Then, with separate approval, use one dedicated non-production test user whose inbox is controlled by
the operator:

1. Submit Forgot Password once in a browser and record only the time and generic on-screen response.
2. Confirm exactly one Brevo transaction and one inbox message. Do not copy the reset token into logs.
3. Confirm the link origin is exactly `https://cis.kesug.com`, then use it once to set a new unique test password.
4. Confirm the old password fails, the new password succeeds, existing sessions are invalidated, and
   reusing the same link is rejected.
5. Submit an unknown address once and confirm the same generic response without a Brevo transaction.

## Rollback

If preflight or the controlled test fails:

1. Restore the three replaced files from the dated backup.
2. Remove only the four new files listed in items 1-4 above.
3. Restore the original `/htdocs/.env` from the private backup.
4. If configuration had been cached, remove the newly generated/current `config.php` if present and
   restore the backed-up `/htdocs/bootstrap/cache/config.php` under its original name.
5. Do not restore the database unless an independently verified database change occurred; this
   deployment performs none. Do not modify n8n, Gmail reminders, PisoWiFi, user rows, or sessions.

Verify package files against `MANIFEST.sha256` before upload and again after downloading the uploaded
files from FileZilla.
