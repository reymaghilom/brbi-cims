# BRBI CIMS — Phase 3E: production HMAC and origin preparation (read-only, nothing applied)

Status: **read-only verification and preparation COMPLETE. Nothing on n8n, the VM, Caddy, PisoWiFi,
Data Tables, credentials or production Laravel was changed.** Workflows A and B remain inactive.
Date: 2026-10-26 (checks 07:38–07:45Z).

Inputs: Phase 3D PASS (InfinityFree → `https://brbi-cims.pesowifi.duckdns.org` over trusted TLS,
request seen in the BRBI Caddy log at 07:35:01.790Z from `185.27.134.53`). Production `APP_URL`
origin: **`https://cis.kesug.com`**. The probe file was deleted (operator: now 404).

## 1. Current Workflow A, verified read-only

Source: n8n SQLite opened with `mode=ro` as user `n8n`, query piped over stdin (no file written on
the VM). Credential secret columns were not read.

| Item | Live value |
|---|---|
| Workflow | `Moj7uJTsrdtcY9H9` "BRBI CIMS — Schedule Receiver", **`active=0`, `activeVersionId=null`**, 0 published versions, versionId `42f3d8d8-…`, updatedAt `2026-10-26 04:47:51` (unchanged since Phase 2A-8) |
| Webhook | `POST brbi-cims/reminder-events`, `responseMode: responseNode`, `rawBody: true`. `webhook_entity` holds only PisoWiFi's `pisowifi-status` → **A is not registered** |
| Raw body | `Read Raw Body`: text, `utf8`, `stripBOM: false` |
| Crypto nodes (3) | All three bound to **`TdGXOxTww2uazRV9` "CIMS Reminder HMAC (staging)"** |
| `Verify Signature and Validate` | sha `4cd19494049a13b0`. Diff vs the repo artifact (`docs/n8n/phase-2a-7/workflows/brbi-cims-schedule-receiver.json`) = **exactly one line**: `ALLOWED_ACTIVITIES_ORIGIN = 'https://cims.staging.invalid'` (artifact `''`). `MAX_BODY_BYTES 16 KiB`, `TOLERANCE_SECONDS 300`, schema `brbi.cims.reminder.v1` |
| `Decide Outcome` | sha `f03d47ac17ccd1d7` = artifact |
| Settings | executions saved: none (success, error, manual), no progress, `callerPolicy workflowsFromSameOwner`, `availableInMCP false`, `binaryMode separate` |
| Instance | `N8N_PUBLIC_API_DISABLED=true`, `N8N_BLOCK_ENV_ACCESS_IN_NODE=true`, `NODES_EXCLUDE` (exec/file/ssh/ftp/git), `N8N_PAYLOAD_SIZE_MAX=4` (MiB), `GENERIC_TIMEZONE=TZ=Asia/Manila`. VM clock NTP-synchronised |
| Credentials (metadata only) | `CdasUisiKtZUlVwz` Header Auth account, `eG8qq0MU27sbrya2` Gmail account (PisoWiFi), `TdGXOxTww2uazRV9` CIMS Reminder HMAC (staging), `rD7vDt4KYtPc8iqC` BRBI CIMS Gmail (reminders). **No production HMAC credential exists yet** |
| Execution counter | 55 (unchanged since Phase 2B) |

Workflow B (`tiYIF6ccGU2LZVLb`): inactive, unpublished, gate `NOT_APPROVED` ≠ `APPROVED_FOR_PHASE_2B`,
`Send Reminder Email` **disabled** (credential `rD7vDt4KYtPc8iqC`).

**Observation:** B's `updatedAt` changed to 07:42:33Z during this check. `workflow_history` shows two
**editor autosaves** by the operator account: 07:42:27 `Send Reminder Email` enabled, and 07:42:32
disabled again. Net diff vs the 06:37:43 closed baseline = **none**. The gate stayed closed and the
workflow stayed inactive throughout; the execution counter is still 55. The operator should confirm
this was their own UI interaction.

## 2. Production origin change (prepared, not applied)

Only change in Workflow A's `Verify Signature and Validate`, line 12:

```diff
-const ALLOWED_ACTIVITIES_ORIGIN = 'https://cims.staging.invalid';
+const ALLOWED_ACTIVITIES_ORIGIN = 'https://cis.kesug.com';
```

Reference check of the live code with this value (local Node run, **not an n8n test**):

| `activities_url` | Result |
|---|---|
| `https://cis.kesug.com/client-folders/12/activities` | accept |
| `https://CIS.KESUG.COM/…?person=co-maker&co_maker_id=3` | accept (case-insensitive) |
| `http://cis.kesug.com/…` | **422 `activities_url_origin_not_allowed`** |
| `https://cis.kesug.com:443/…` | 422 (explicit port is a different origin string) |
| `https://cis.kesug.com.evil.example/x`, `https://evil.example/?u=https://cis.kesug.com` | 422 |
| `https://cis.kesug.com@evil.example/x` | 422 `activities_url_invalid` |
| `https://cims.staging.invalid/…` (staging rows) | 422 |

**Pre-activation risk: production Laravel must generate `https://` URLs.** `activities_url` comes
from `route('client-folders.activities.index', …)` inside the saving web request, so it uses the
request's scheme and host. `bootstrap/app.php` has no `trustProxies`, and `URL::forceScheme` /
`forceRootUrl` are not used anywhere. If InfinityFree's front end did not signal HTTPS to PHP, every
created/changed event would be rejected with 422. Operator check (read-only, browser): on any
production page, View Source → generated links/form actions/asset URLs must start with
`https://cis.kesug.com/`. If they start with `http://`, the fix is a Laravel change (separate
approval), not a weaker origin rule.

## 3. Separate production HMAC credential (prepared, not created)

| Item | Plan |
|---|---|
| New credential | Type **Crypto**, name **`CIMS Reminder HMAC (production)`**, HMAC secret = new random value |
| Secret | 32 random bytes as 64 lowercase hex, generated on the operator PC, e.g. `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Entered directly into the n8n credential form and the InfinityFree `.env` (`N8N_REMINDERS_SIGNING_SECRET`). **Never pasted into chat, workflow JSON, docs or git.** Laravel enforces ≥ 32 characters; n8n does not, so the length is the operator's responsibility |
| Staging credential | `TdGXOxTww2uazRV9` is **not edited or deleted**. It stays available for rollback and for the step-0 synthetic cancellation |
| Rebinding | Workflow A can only use one secret: the three Crypto nodes (`HMAC Expected Signature`, `Seal Expected Signature`, `Seal Supplied Signature`) are switched from staging to production. All three must use the **same** credential: a mixed binding makes every request `signature_invalid` (fail-closed, not fail-open) |
| Verification after the rebind | Read-only: all 3 nodes reference the new credential id; `nodes`/`connections` diff vs `42f3d8d8` = the 3 credential references + the origin line only |

## 4. HMAC / validation audit (live code, read-only)

| Check | Finding |
|---|---|
| Signed string | Laravel `ReminderWebhookSignature::sign`: `sha256=` + HMAC-SHA256(secret, `"{ts}.{raw body}"`), lowercase hex. n8n recomputes over `x-cims-timestamp` + `.` + `raw_body`: **matches** |
| Raw body fidelity | Laravel sends the stored `payload` string unchanged (`withBody`). It is encoded with `JSON_UNESCAPED_SLASHES` only, so non-ASCII (e.g. `ñ`) is `ñ` escaped → body is pure ASCII → the n8n UTF-8 text decode is byte-exact. `stripBOM: false`. **OK** |
| Comparison | Double-HMAC (`HMAC(secret, expected)` vs `HMAC(secret, supplied)`): timing-safe without a constant-time helper. Format gate `^sha256=[0-9a-f]{64}$` first. **OK** |
| Timestamp | `^\d{1,12}$`, ±300 s vs n8n `Date.now()`. Laravel stamps **at send time**, so retries get a fresh timestamp. VM is NTP-synced; InfinityFree's clock is not verified (a skew > 300 s would show as 401 `timestamp_outside_tolerance` in the outbox `last_response_status`) |
| Replay | A captured request is replayable for ≤ 300 s; the replay is then answered `duplicate` (same event id + version) and changes nothing. **Acceptable** |
| Header binding | `X-CIMS-Event-Id` and `Idempotency-Key` are not in the MAC but must equal the signed body's `event_id` / `idempotency_key`. Repeated headers fail the format checks. **OK** |
| Body size | 16 KiB in code (413) and in Caddy (Caddy 2.6.2 answers 502). n8n limit 4 MiB. **OK** |
| Schema | Exact key sets at every level, `brbi.cims.reminder.v1`, UUID event id, subject type enum, positive integers, `key = type:id`, `idempotency_key = key:v{version}`, `Asia/Manila`, trigger consistency, cancel shape (no email). **Matches the Laravel contract** |
| Version | Guarded write updates only rows with a lower version. `Decide Outcome`: same version + other event id → **409 `version_identity_conflict`**, same event → `duplicate`, newer stored → `stale`, else insert. **OK** |
| Minor (no change proposed) | The freshness and format checks run before the MAC, so an unauthenticated caller can see *which* pre-check failed (401 codes). This reveals no secret or data |

No validation change is needed for production beyond the origin line.

## 5. Synthetic rows

| Subject | State | Risk in production |
|---|---|---|
| **`ci_activity:920001`** | v3 `scheduled`, delivery `pending`, attempts 0, trigger **2026-11-04 02:00Z (10:00 Manila)**, recipient 990001 `stage3.creator@example.test`, `activities_url https://cims.staging.invalid/…` | **Live hazard.** Once B is active it is due on 2026-11-04 and B would attempt a real Gmail send to a reserved `.test` address (bounce, sender reputation). It must be cancelled **before** B is activated |
| `ci_activity:920002` | v2 `cancelled` | Inert |
| `ci_activity:920003` | v1 `scheduled`, delivery **`sent`** (log #1, message `1a176ea5633f2a07`) | Inert for B (already sent) |
| 8 receipts, 1 delivery log | history | Inert |
| ID collision | A real production `ci_activity` 920001–920003 would hit these rows: 920001 v1–v3 → `stale` (silently ignored); 920003 v1 → 409. Only possible once production IDs reach 920001. Cleanup is optional and would need a separate Data Table approval |

**Recommended handling of 920001 (step 0):** one signed `schedule.cancelled` **v4** through the
**test** webhook while A is still bound to the **staging** credential, with the operator pressing
*Test workflow* once (no activation, no direct Data Table edit). Body shape:

```json
{"schema":"brbi.cims.reminder.v1","event_id":"<new uuid v4>","idempotency_key":"ci_activity:920001:v4",
 "event_type":"schedule.cancelled","occurred_at":"<now, UTC Z>",
 "reminder":{"key":"ci_activity:920001","subject_type":"ci_activity","subject_id":920001,"ci_activity_id":920001,
  "version":4,"scheduled_at":null,"has_specific_time":null,"trigger_at":null,"trigger_at_local":null,
  "timezone":"Asia/Manila","cancel_reason":"unscheduled"},
 "recipient":{"user_id":990001},"content":null}
```

Expected: HTTP 200 `applied`; row `id=1` → v4 `cancelled`, delivery `cancelled`, email cleared;
receipt #9 `applied`; no B execution, no delivery log. This must happen **before** the credential
switch, because afterwards only the production secret is accepted.

## 6. Activation and rollback checklist

Order (each line is a separate approval gate):

| # | Action | Where | Verify (read-only) |
|---|---|---|---|
| 0 | Cancel synthetic `920001` (signed v4, test webhook, staging credential) | operator PC + n8n editor | §5 expected state; counter +1; A still inactive |
| 1 | Create `CIMS Reminder HMAC (production)` (unbound) | n8n UI | new credential id exists; staging credential `updatedAt` unchanged; A unchanged |
| 2 | Edit A: rebind 3 Crypto nodes + set the origin line; save, **do not activate** | n8n UI | diff vs `42f3d8d8` = exactly those 4 edits; `active=0` |
| 3 | Operator check: production pages generate `https://cis.kesug.com/` URLs | browser | §2 |
| 4 | Laravel production deploy (code, 2 migrations, `.env`: secret, URL `https://brbi-cims.pesowifi.duckdns.org/webhook/brbi-cims/reminder-events`, `N8N_REMINDERS_ENABLED=false` at first) and the proposed retry pass (Phase 3A diff) | InfinityFree | separate phase |
| 5 | Activate A | n8n UI | `webhook_entity` has A's path; unsigned `POST {}` → **401**; other paths still 404; PisoWiFi webhook unaffected |
| 6 | `N8N_REMINDERS_ENABLED=true`, `DISPATCH_AFTER_COMMIT=true`, `MAX_ATTEMPTS=30` | InfinityFree `.env` | controlled create → schedule row `applied` with `https://cis.kesug.com` URL; then cancel |
| 7 | B: gate `APPROVED_FOR_PHASE_2B`, Gmail node enabled, activate | n8n UI | separate phase (only after 920001 is cancelled) |

Rollback:

| Situation | Action | Effect |
|---|---|---|
| Problem after 5/6 | **Deactivate A** | n8n answers 404 "not registered"; Laravel keeps events `pending` and retries with backoff (nothing lost until `max_attempts`) |
| Stop recording | `N8N_REMINDERS_ENABLED=false` | No new outbox rows; schedules changed meanwhile need a resync later |
| Wrong secret / origin | Re-bind the 3 Crypto nodes to `TdGXOxTww2uazRV9` and restore the origin line (or restore A's version `42f3d8d8` from history) | Back to the verified staging state |
| Production secret exposed | Generate a new secret, update the production credential and `.env` together; old-secret requests fail closed (401) and retry | — |
| Caddy | Unchanged by this phase; Phase 3A rollback (`Caddyfile.pre-brbi-cims.bak`) remains valid | — |

## 7. Exact changes needed (none applied)

1. Data: `ci_activity:920001` → v4 `cancelled` via one signed test-webhook request (staging secret).
2. n8n: new Crypto credential `CIMS Reminder HMAC (production)`.
3. n8n Workflow A: 3 Crypto node credential references → production; line 12 origin → `https://cis.kesug.com`.
4. Production Laravel (later phase): deploy + `.env` secret/URL/flags, plus confirmation of `https://` URL generation.
5. Activation of A, then B, each separately approved.

## 8. Step 0 result — synthetic cancellation of `ci_activity:920001` — PASSED (2026-10-26)

Approved by the operator as one request. Preconditions verified read-only at 07:50:27Z (A inactive,
staging credential only; row v3 `scheduled`/`pending`; counter 55; B closed). Sender: a copy of the
G10 cancel sender (fixed body sha `436aca69…05ed1`, 510 B, event `a9c9c1db-d78f-469d-9d89-837fd5802186`,
one-request lock, secret entered by the operator on stdin). The operator pressed *Test workflow* on A.

| Item | Evidence (read-only, 08:01:05Z) |
|---|---|
| Response | 08:00:03.891Z, HTTP **200** `{"result":"applied","event_id":"a9c9c1db-…"}` |
| Row `id=1` | **v4, `cancelled`, `cancel_reason unscheduled`, delivery `cancelled`**, attempts 0, claim empty, `delivery_next_attempt_at` null, `recipient_email ""`, trigger/scheduled cleared, `activities_url ""`, `last_event_id a9c9c1db…`, `last_execution_id 56` |
| Receipt | #9: `ci_activity:920001:v4`, `schedule.cancelled`, **`applied`** |
| Unchanged | Schedule rows 2–3, receipts 1–8, delivery log #1: row hashes identical to the pre-test snapshot |
| Executions | Counter 55 → **56**; A `manual_success` 16 → 17 (08:00:04). #56 purged (retention none). B `manual_success` still 4 (06:33:52); no other statistic changed |
| Gmail | No delivery log added, egress proxy 0 lines since 07:50Z, no Gmail/OAuth/error lines in the n8n log |
| Workflows / credentials | A, B, PisoWiFi and N1G workflows identical (nodes, connections, settings, versionId, updatedAt). A and B `active=0`; B gate `NOT_APPROVED`, Gmail node disabled. Credential metadata identical. Only `pisowifi-status` registered |
| PisoWiFi / host | All services active, 0 restarts, start times unchanged, 0 warnings since 07:50Z. Caddyfile `a3957fc9…`. Public 200 / 401 |

`ci_activity:920001` is no longer deliverable. Workflow A remains bound to the staging credential.

## 9. Step 1 result — production HMAC credential created (unbound) — PASSED (2026-10-26)

Secret: 32 random bytes (`crypto.randomBytes`) as 64 lowercase hex, generated locally into a
session-scratchpad file (ACL: operator account only), never displayed or logged. Key-check
fingerprint `1ab7ae71ef26` = first 12 hex of HMAC(secret, `"cims-key-check"`). The operator copied
it from the file into the n8n credential form. It must also go to the operator's password manager
(the scratchpad is temporary) and later to `N8N_REMINDERS_SIGNING_SECRET`.

| Item | Evidence (read-only metadata, 08:07:53Z; `data` column not read) |
|---|---|
| New credential | **`zBwoi7jveW0NkPSv` "CIMS Reminder HMAC (production)", type `crypto`**, created 08:06:34Z, owner project `IGG3PPEmByCMZIXR` (same as all others), not managed/global |
| References | 0 in `workflow_entity`, 0 in `workflow_history` |
| Staging credential | `TdGXOxTww2uazRV9` metadata identical (`updatedAt 2026-10-26 03:22:36.607`) |
| Other credentials | Header Auth, Gmail account (PisoWiFi), BRBI CIMS Gmail: metadata identical |
| Workflow A | Unchanged (versionId `42f3d8d8`, nodes `f4d87a9b…`); its 3 Crypto nodes use only `TdGXOxTww2uazRV9` |
| A / B state | Both inactive; B gate `NOT_APPROVED`, `Send Reminder Email` disabled |
| Everything else | Every workflow, Data Table row, execution counter (56) and statistic identical to the step 0 snapshot. PisoWiFi services active, 0 restarts, 0 warnings; Caddyfile `a3957fc9…` |

## 10. Step 2 result — Workflow A rebound to production, origin set — PASSED (2026-10-26)

Baseline 08:09:38Z: A = history version `42f3d8d8`, 0 differences. The operator edited and saved
in the editor (autosaves 08:10:04, 08:10:08, 08:10:13; final 08:11:15, versionId
**`08cd9515-ce59-474f-817e-0146844dfcd2`**). Read-only comparison at 08:12:20Z against `42f3d8d8`:

| Item | Evidence |
|---|---|
| Differences | **Exactly 4**: `HMAC Expected Signature`, `Seal Expected Signature`, `Seal Supplied Signature` credentials `TdGXOxTww2uazRV9` → **`zBwoi7jveW0NkPSv`**; `Verify Signature and Validate` line 12 → **`const ALLOWED_ACTIVITIES_ORIGIN = 'https://cis.kesug.com';`** (214 lines before and after) |
| Unchanged | 16 nodes (same names), connections identical, all other node fields/parameters identical, settings identical |
| A state | `active=0`, `activeVersionId` null, 0 published versions, not archived, not registered |
| B | Inactive, versionId `15ce2cca` / `updatedAt 07:42:33` unchanged, gate `NOT_APPROVED`, Gmail node disabled |
| Credentials | All 5 metadata identical (staging `updatedAt 03:22:36.607`) |
| Data / runs | Data Table hashes identical (3 / 9 / 1 rows), execution counter 56, statistics unchanged |
| PisoWiFi | Workflow active, versionId unchanged; N1G unchanged; services active, 0 restarts, 0 warnings since 08:00Z; Caddyfile `a3957fc9…`; public 200 / 401 |

Rollback for this step: restore A's history version `42f3d8d8` (staging credential, staging origin).
From now on a signed request is accepted only with the production secret; the staging secret gets
401 `signature_invalid`.

**Next actions requiring approval:** the production-URL check (step 3, operator, browser) and the
production Laravel deployment (step 4, separate phase). Workflow A activation (step 5) comes after.
