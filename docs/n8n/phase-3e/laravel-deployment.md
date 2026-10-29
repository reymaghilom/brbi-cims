# BRBI CIMS — Phase 3E step 4: production Laravel deployment (PREPARED, NOT DEPLOYED)

Status: **prepared locally. Nothing was uploaded, no production migration was run, production
`.env` was not touched.** n8n Workflows A/B stay inactive. Date: 2026-10-26.

Local working files for the operator (session scratchpad, not in git):
`scratchpad/deploy/` → `package/` (upload tree + `MANIFEST.sha256`), `01-precheck.READONLY.sql`,
`02-migrate.sql`, `03-rollback.sql`.

## 1. What is deployed today (baseline)

There is **no deployment manifest** in the repo or docs. The only record is commit `21e0d8e`
("Update BRBI CIMS features and production fixes", 2026-09-23), the base of all n8n phases.
**Assumption: production = `21e0d8e`.** Confirm it in the File Manager before uploading
(read-only, open each file and search):

| File (Laravel root) | Must show in production (= `21e0d8e`) | Must NOT show |
|---|---|---|
| `app/Http/Requests/Auth/LoginRequest.php` | `No login rate limiting or lockout by product decision` | `MAX_ATTEMPTS` |
| `bootstrap/app.php` | `'role' => EnsureUserHasRole::class,` | `submission.once`, `routes/api.php` |
| `app/Http/Middleware/` | — | `PreventDuplicateSubmission.php` |
| `routes/` | `web.php`, `console.php` | `api.php` |
| `app/Services/` | — | `Integrations/` folder |
| `bootstrap/cache/` | `packages.php`, `services.php` | `config.php`, `routes-v7.php`, `events.php` (see §5) |

If any check differs, **stop**: production is not `21e0d8e` and the file list must be redone.

## 2. Deployment-ready file list (delta `21e0d8e` → working tree)

All paths are relative to the **Laravel root** (the folder containing `artisan`, `app/`,
`bootstrap/`, `vendor/`). The list was cross-checked against `git diff` + untracked files: nothing
missing, nothing extra.

**New (13):** upload.
```
app/Console/Commands/DeliverN8nReminderEvents.php
app/Http/Controllers/Integrations/N8nReminderAuthorizationController.php
app/Http/Middleware/AuthenticateN8nReminderAuthorization.php
app/Http/Middleware/PreventDuplicateSubmission.php
app/Http/Requests/Integrations/ClaimN8nReminderRequest.php
app/Observers/ReminderScheduleObserver.php
app/Services/Integrations/N8n/ReminderAuthorizationException.php
app/Services/Integrations/N8n/ReminderDeliveryAuthorizer.php
app/Services/Integrations/N8n/ReminderOutboxDispatcher.php
app/Services/Integrations/N8n/ReminderScheduleOutbox.php
app/Services/Integrations/N8n/ReminderWebhookSignature.php
database/migrations/2026_10_25_000100_create_reminder_schedule_outbox_tables.php
database/migrations/2026_10_25_000200_create_reminder_delivery_claims_table.php
routes/api.php
```
(14 lines: the 2 migration files are uploaded only so the code base matches the `migrations` table.)

**Replaced (10):**
```
app/Actions/Admin/ResetOperationalData.php
app/Http/Controllers/CiActivityNotificationFeedController.php   (outbox retry pass, this phase)
app/Http/Requests/Auth/LoginRequest.php
app/Providers/AppServiceProvider.php
bootstrap/app.php
config/services.php
routes/web.php
resources/views/client-folders/income-sources/business-edit.blade.php
resources/views/client-folders/residence-business/partials/checks-listing.blade.php
resources/views/components/ui/confirmation-dialog.blade.php
```

**Front-end build:** `resources/js/app.js` changed, so the compiled assets change. Upload the
rebuilt **public directory** files `build/manifest.json` and `build/assets/*` (hashed names; old
files may stay). Path: inside the folder that contains the live `index.php`.

**Not uploaded:** `tests/`, `docs/`, `scripts/`, `phpunit.xml`, `README.md`, `.env.example`,
`resources/js/app.js` (source only). **`composer.json`/`composer.lock` are unchanged: `vendor/` is
not touched.** The autoloader is not classmap-authoritative, so the new `App\` classes load
through PSR-4 without a `composer dump-autoload`.

### Behaviour included in this delta (all of it is deployed together)

| Change | Effect in production | Active at deploy? |
|---|---|---|
| n8n reminder outbox (observer, outbox, dispatcher, signature, command, config, reset hook) | Records/sends reminder events | **No** while `N8N_REMINDERS_ENABLED=false` |
| Bell-feed outbox retry pass (this phase) | ≤ 1 pass/minute globally, ≤ 2 events | **No** while disabled |
| Pre-send authorization route (`routes/api.php`) | Answers 404 | **No** (`N8N_REMINDER_AUTHORIZATION_ENABLED=false`) |
| **Login throttling** (`LoginRequest`) | 5 failed sign-ins per email + IP within 60 s → "Too many sign-in attempts…" for the rest of the minute; success clears it; never a permanent lockout | **Yes, immediately**. Replaces the earlier "no rate limiting" product decision |
| **Duplicate-submission guard** (`PreventDuplicateSubmission` on Business Report create/update) + pending labels on deletes (`app.js`, 3 views) | An identical retry of a save replays the first result instead of saving twice | **Yes, immediately** |

## 3. Database migrations (2 pending, additive only)

| Migration | Creates | Touches existing tables? |
|---|---|---|
| `2026_10_25_000100_create_reminder_schedule_outbox_tables` | `reminder_schedule_states`, `reminder_outbox_events` | **No** (no FK, no ALTER of existing tables) |
| `2026_10_25_000200_create_reminder_delivery_claims_table` | `reminder_delivery_claims` (Phase 2A-5, unused while authorization is disabled) | **No** |

Users, CI activities, targets, folders and notifications are not altered. The tables have no
foreign keys, so they can never cascade into existing data. The `down()` methods drop only these
3 tables.

InfinityFree has no CLI, so `php artisan migrate` cannot run. `02-migrate.sql` is the **exact DDL**
Laravel's MySQL grammar produces for these migrations (generated offline in pretend mode, no DB
contacted), plus the two `migrations` rows in one new batch, as `migrate` would write them.

**MySQL/MariaDB note:** `reminder_delivery_claims` has two `timestamp NOT NULL` columns without a
default. With `explicit_defaults_for_timestamp = OFF` the server would give the first one an
implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` and may reject the second under
strict `sql_mode`. `01-precheck` reads the setting; `02-migrate` contains a commented `SET SESSION`
to enable only if it is OFF.

## 4. HTTPS URL generation for `https://cis.kesug.com`

The n8n `activities_url` is built with `route(...)` inside the user's save request. There is no
`trustProxies`, `forceScheme` or `forceRootUrl`, so the scheme comes from what InfinityFree passes
to PHP. That **cannot be verified from here** (InfinityFree's browser check blocks cURL).

**Decisive read-only evidence already exists in production:** `CiActivityScheduledReminder`
(in-app bell notification) stores `url => route('client-folders.activities.index', …)`. It is
created by `CreateCiActivity` / `SaveCiActivity*Target` in the same save request and context as
the outbox payload. `01-precheck` query **E** groups those stored URLs by origin.

- Only `https://cis.kesug.com` → URL generation is correct; Workflow A's origin rule will accept.
- No rows (no activity was ever scheduled) → use the browser check instead: View Source on any
  signed-in page; the Vite `<link>`/`<script>` and form `action` URLs must start with
  `https://cis.kesug.com/`. These come from the same URL generator (no `ASSET_URL`, no
  `forceScheme` in the code); `http://` assets on an HTTPS page would already be blocked as mixed
  content, so a working production UI is strong but indirect evidence.
- `http://cis.kesug.com` appears → every created/changed event would be rejected (422). **Do not
  enable** the integration; a small Laravel fix (trusted proxy / forced https scheme) is needed first,
  with separate approval.

## 5. Procedure (operator, InfinityFree control panel; each part needs approval)

### 5.0 Locate folders (read-only)
File Manager → open `htdocs/`. Note where `index.php` (**PUBLIC_DIR**) and `artisan` (**LARAVEL_ROOT**)
are. They may be the same folder layout as uploaded originally (`htdocs/` = Laravel root with the
public files inside, or `htdocs/` = public with the app beside it). Use those two locations below.

### 5.1 Backup (before any change)
1. **Database:** phpMyAdmin → select the production database → *Export* → Quick, SQL → download.
   Keep the file. Note its size and the date.
2. **Files:** with FileZilla (FTP details in the control panel) download the whole `htdocs/`
   folder, or at least: the 10 files to be replaced, `PUBLIC_DIR/build/`, `bootstrap/cache/`,
   and `.env`. Store the `.env` copy privately (it contains secrets).
3. Run `01-precheck.READONLY.sql` (phpMyAdmin → SQL tab) and keep the output (row counts are the
   data-preservation baseline).

**Stop conditions:** reminder tables or `2026_10_25_*` rows already exist; precheck **E** shows
`http://`; any §1 baseline check differs.

### 5.2 Database
phpMyAdmin → SQL tab → paste `02-migrate.sql` (uncomment the `SET SESSION` line only if
precheck A showed `explicit_defaults_for_timestamp = 0`) → *Go*. Expected: 3 tables, 2 migration
rows in one new batch, `should_be_zero = 0`. The old code ignores these tables, so this is safe
before the files.

### 5.3 Files (upload in this order; File Manager *Upload* into the matching folder)
1. The 14 **new** files (old code does not reference them).
2. `PUBLIC_DIR/build/assets/*` (new hashed names, beside the old ones), then `build/manifest.json`.
3. `config/services.php`, `app/Services/...` already present → `app/Providers/AppServiceProvider.php`.
4. `bootstrap/app.php` → then `routes/web.php` (it uses the middleware alias registered in
   `bootstrap/app.php`).
5. `app/Http/Requests/Auth/LoginRequest.php`, `app/Actions/Admin/ResetOperationalData.php`,
   `app/Http/Controllers/CiActivityNotificationFeedController.php`, the 3 Blade views.
6. Compare each uploaded file's size with `package/MANIFEST.sha256` / the local file.
7. `bootstrap/cache/`: if `config.php`, `routes-v7.php` or `events.php` exist (they did not exist
   locally; they appear only after `artisan optimize`/`config:cache`), download them, then delete
   them, otherwise the new routes, middleware and `.env` keys are ignored. Leave `packages.php` and
   `services.php`. Compiled Blade views refresh by themselves (the views are newer).

### 5.4 `.env` (File Manager → edit LARAVEL_ROOT/.env; append, do not change other lines)
```
# n8n CI Activity email reminders (Phase 3E). Integration stays OFF at deployment.
N8N_REMINDERS_ENABLED=false
N8N_REMINDERS_WEBHOOK_URL=https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events
N8N_REMINDERS_SIGNING_SECRET=<paste the production secret, key-check 1ab7ae71ef26>
N8N_REMINDERS_DISPATCH_AFTER_COMMIT=true
N8N_REMINDERS_TIMEOUT_SECONDS=5
N8N_REMINDERS_MAX_ATTEMPTS=30
N8N_REMINDER_AUTHORIZATION_ENABLED=false
N8N_REMINDER_AUTHORIZATION_TOKEN=
```
Also confirm (do not change unless wrong) `APP_URL=https://cis.kesug.com`, `APP_ENV=production`,
`APP_DEBUG=false`. The secret is pasted from the local secret file (§ step 1 of Phase 3E), never
typed into chat. `N8N_REMINDERS_ENABLED=true` is a **later, separate** step (after Workflow A is
active, step 5/6 of the Phase 3E checklist).

### 5.5 Smoke test (integration still off)
Sign in; open a client folder; open CI Activities; schedule a test activity in a test folder →
the bell notification still appears; open a Business Report and save once (single save);
open the Residence/Business delete dialog (shows "Deleting..." when confirmed). phpMyAdmin:
`SELECT COUNT(*) FROM reminder_outbox_events` must stay **0** (disabled), and the precheck **D**
counts must be unchanged except rows you deliberately created in the test.

## 6. Rollback

| Situation | Action |
|---|---|
| Error after file upload | Re-upload the backed-up originals of the 10 replaced files (and old `build/manifest.json`). The 14 new files can stay (unreferenced) or be deleted. Restore any deleted `bootstrap/cache/*.php` only if you also restore the old files |
| Problem only with reminders | `.env`: `N8N_REMINDERS_ENABLED=false` (immediate; nothing is recorded or sent) |
| Undo the schema | `03-rollback.sql` (drops only the 3 new tables and their 2 migration rows), together with the file rollback |
| Data issue (not expected: no existing table is written by this deployment) | phpMyAdmin *Import* of the §5.1 export |

## 7. Test results (local)

Readiness re-check, 2026-10-26 (local only, nothing deployed):

| Check | Result |
|---|---|
| Upload set vs working tree | All 24 Laravel files byte-identical to the working tree; the list matches `git status` (10 modified app files + 14 new, excluding tests/docs/scripts/`phpunit.xml`/`README.md`/`.env.example`/`resources/js/app.js`) |
| Front-end build | `public/build` (12:46) is newer than `app.js` (12:41) and the 3 changed views; the built JS contains the new `Deleting...` pending label. `app.css` unchanged since 2026-09-15. Upload set: `build/manifest.json`, `assets/app-oHU76TA-.js`, `assets/app-QcMaqemO.css`, `assets/official-report-DW3lvfgL.css` |
| Upload package | Session scratchpad `deploy/package/` (`laravel-root/`, `public-dir/build/`) + `MANIFEST.sha256` (28 files) + `SIZES.txt`; `01-precheck.READONLY.sql`, `02-migrate.sql`, `03-rollback.sql` copied beside it |
| Migration DDL | Re-generated in pretend mode: identical to `02-migrate.sql` (migration files unchanged since generation) |
| Outbox retry pass | Implemented in `CiActivityNotificationFeedController` (= Phase 3A proposed diff); `N8nReminderOutboxFeedPassTest` 6/6 pass |
| Targeted suites | `tests/Feature/Integrations` (54 tests), `tests/Feature/Auth`, the 3 duplicate-submission tests, `DeleteDialogMobileButtonWidthTest`: **OK, 139 tests, 1232 assertions** |
| Known failures | 16 tests in 7 classes (`BusinessReportAndReportsUiTest` 3, `BusinessNameAndBranchFieldTest` 1, `BusinessCheckRepointGuardTest` 7, `BusinessCheckBusinessSelectionUxTest` 1, `BusinessCheckDeleteConcurrencyTest` 1, `BusinessReportBusinessCheckIndependenceTest` 1, `EvidenceStorageUploadTest` 2) fail **identically on a clean `git archive` of `21e0d8e`**: pre-existing, not caused by this delta |

## 8. Remaining blockers / decisions

1. **Confirm production = `21e0d8e`** (§1 checks).
2. **HTTPS evidence** (§4 query E) must show only `https://cis.kesug.com`.
3. **Scope decision:** the delta also turns on login throttling and the duplicate-submission guard
   immediately (§2). Approve deploying them together (recommended: tested together), or ask for an
   n8n-only subset.
4. **Existing schedules are not backfilled.** When the integration is enabled, only schedules
   created/changed afterwards produce events. The backfill (`n8n-reminders:deliver --reconcile`)
   needs a CLI, which InfinityFree lacks. Decide: forward-only (no code), or approve a small
   admin-only web trigger.
5. **Login throttle key uses `$request->ip()`** without trusted proxies. If InfinityFree's
   `REMOTE_ADDR` is a shared proxy address, the limit becomes effectively per email (anyone could
   delay one account's sign-in for ≤ 60 s). Acceptable but worth knowing.
6. Production `CACHE_STORE`: throttling, the duplicate guard (`Cache::lock`) and the retry-pass
   guard need a working cache. `file` and `database` both work (the `cache`/`cache_locks` tables come
   from the original base migration); precheck D counts the `cache` table.
