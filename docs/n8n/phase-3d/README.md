# BRBI CIMS — Phase 3D: InfinityFree outbound HTTPS probe (prepared, NOT uploaded)

Status: **local preparation complete. Nothing has been uploaded to or executed on InfinityFree.**
Workflows A/B inactive; Caddy, n8n, PisoWiFi and databases untouched. Date: 2026-10-26.

## Why a standalone file

The deployed app is standard Laravel: `public/.htaccess` routes to `index.php` **only if the
requested file does not exist** (`RewriteCond %{REQUEST_FILENAME} !-f`). A standalone PHP file
placed **next to `index.php`** is therefore served directly by Apache and never boots Laravel. No
route, middleware, session, cookie, `.env` or database is involved, and no application code changes.

## The probe

| Property | Value |
|---|---|
| File (local only) | session scratchpad `p3d/cims-reach-f261958327d8001f.php`, 5,429 bytes, sha256 `2e31e00486dd33bd80a928161feb04fa5c0a53acb869504c801fe590ed8bed21` (not committed). The first draft `cims-reach-5398189447bf.php` and its key were **retired** (key shown in chat) and deleted locally |
| Does | One `POST` of `{}` (`Content-Type: application/json`) to `https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events`. cURL with `SSL_VERIFYPEER=true`, `VERIFYHOST=2`, HTTPS-only, no redirects, 5 s connect / 10 s total. A PHP-stream fallback is used if cURL is missing |
| Prints | `http_status`, `tls_verify_result` (0 = trusted), `curl_errno`, a sanitised `curl_error` (URLs/IPs removed, one line, ≤ 160 chars), timings, PHP/cURL/OpenSSL versions, a yes/no for n8n's "not registered" reply, and a verdict. **Never** the response body, headers, cookies or any app data |
| Guards | Random file name. One-time access key: only its SHA-256 is in the file; without the key the page answers 404 `{"probe":"not found"}`. **Hard expiry 2026-10-27T07:17:06Z** (then 410). GET only. `Cache-Control: no-store`, `X-Robots-Tag: noindex` |
| Local validation | `php -l` clean. Wrong key → `not found`. Correct key (from the operator PC) → HTTP **404**, TLS verify **0**, `curl_errno 0`, n8n "not registered" **true**, 0.72 s. This proves the probe logic, not InfinityFree |

Expected on InfinityFree: **`http_status 404` + `n8n_not_registered_reply true` + `tls_verify_result 0`**
means InfinityFree can reach the endpoint over verified TLS. `curl_errno` 6 means DNS failure, 7
connect refused/blocked, 28 timeout, and 35/60 TLS or certificate problems.

## Upload, invoke, remove (operator; only after separate approval)

1. **Upload:** InfinityFree control panel → *Online File Manager* (or FTP) → open the folder that contains the live `index.php` and `.htaccess` (normally `htdocs/`) → upload `cims-reach-f261958327d8001f.php` **unchanged** (same name). Do not touch any other file.
2. **Invoke once:** in a normal browser (InfinityFree's browser check needs one), open
   `https://<production-domain>/cims-reach-f261958327d8001f.php?k=<access key>`. Copy the JSON shown.
3. **Remove immediately:** delete `cims-reach-f261958327d8001f.php` in the File Manager.
4. **Confirm removal:**
   - File Manager listing no longer shows the file.
   - Re-open the same URL (with the key): it must show the **Laravel 404 page** (file gone → rewritten to `index.php`), **not** the probe JSON. Do a hard refresh (Ctrl+F5); the probe sends `no-store`.
   - Optional read-only VM check: the BRBI access log shows exactly one `POST /webhook/brbi-cims/reminder-events` (status 404) at the invocation time, and none after.

The probe expires by itself on 2026-10-27 even if deletion were missed.

## Remaining dependencies

- The production domain (the `APP_URL` origin) is still needed for step 2 and later for Workflow A's `ALLOWED_ACTIVITIES_ORIGIN`.
- An InfinityFree reachability result makes outbound delivery feasible. It does not replace the retry change (`laravel-outbox-feed-pass.PROPOSED.diff`, Phase 3A).
