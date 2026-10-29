# BRBI CIMS — Phase 2A-8: Real n8n staging import and functional testing

Status: **Stage 1 (read-only remote verification) VERIFIED. Stage 2 (localhost-only IAP tunnel)
VERIFIED. Stage 3 (staging import/configuration) VERIFIED, plus real-n8n tests G4 (v1 create), G8 (v2
reschedule), G7 (v3 duplicate replay), G9 (out-of-order v2 → stale), G5 (one-byte tampered
body, expired timestamp and wrong secret → 401), G6 (missing creator email → 422) and G10 (create, then cancel as `completed`) PASSED.** Real-n8n results not listed as PASSED in section 3–8 are still
**NOT TESTED**. See section 12 for the Stage 2/3 evidence.

Date: 2026-10-25 (Stage 1), 2026-10-25/26 (Stages 2–3).

## 1. Workspace verification — VERIFIED (local only)

| Check | Result |
|---|---|
| Repository / branch / commit | `E:\My projects\Credit Investigation Management System`, `main`, `21e0d8e` |
| Uncommitted work | 31 status entries preserved. Tracked diff unchanged by this phase. |
| Artifacts | `docs/n8n/phase-2a-7/workflows/brbi-cims-schedule-receiver.json` (16 nodes) and `…/brbi-cims-email-reminder.json` (13 nodes). Both `active: false`. Generator drift check clean. |
| Local model tests | `node --test scripts/n8n-phase2a/phase-2a-7/workflows.test.cjs`: 34 passed (reference model, **not n8n**) |
| Credential placeholders | The 3 Crypto `hmac` nodes have **no credential** (must be assigned on import). No secret is present in the JSON. |
| Data Table references | By name: Receiver uses `cims_reminder_schedules` and `cims_reminder_events`; Reminder uses `cims_reminder_schedules` and `cims_reminder_delivery_logs`. |
| Gmail | `Send Reminder Email`: `disabled: true`, no credential. Gate `Live Sending Approved?` is closed (`NOT_APPROVED`). |
| Full Laravel suite | Not run (it previously exhausted memory, as instructed) |

## 2. Access preflight

### Stage 1 read-only verification — VERIFIED (approved by the operator, 2026-10-25)

Approved target: project `project-d54d0392-1941-4432-bd6`, VM `n8n-zerotier-pisowifi-monitoring`,
zone `asia-southeast1-c`, identity `crisbasco04@gmail.com`, IAP SSH.

What was done (read-only only): `gcloud auth list`, `instances describe`, `project-info describe`
(SSH-key and OS Login presence, compared locally with no key printed), and 5 IAP SSH sessions
running inspection commands. No gcloud configuration, metadata, service, firewall, Caddy, systemd,
container, workflow, Data Table or credential was changed. A one-off read-only helper script was
written to `/tmp` on the VM and removed in the same command.

**Access safeguards observed**

- The local key was already present in instance and project `ssh-keys` metadata for user
  `crisbasco04`, and OS Login is not enabled. SSH was made explicitly as `crisbasco04@…` so gcloud
  would not add a key for the local user name `rey`.
- Today's local gcloud logs (`%APPDATA%/gcloud/logs/2026.10.25`) contain **0** metadata-write
  operations. They do contain other gcloud activity from earlier today that this session did not
  run (about 37 `compute ssh`, `instances list`, `projects list`). It is recorded here for the
  operator's awareness.
- Windows note: gcloud uses PuTTY/plink, which injects a `y` into a piped stdin. Remote scripts
  were therefore passed base64-encoded inside `--command`.

**Verified VM and n8n facts**

| Item | Result |
|---|---|
| VM | `n8n-zerotier-pisowifi-monitoring`, RUNNING, `asia-southeast1-c`, e2-medium |
| n8n service | System user `n8n` (uid 988, lingering). User unit `n8n.service`: "n8n (PisoWiFi notifications; official image, rootless, loopback only)", active |
| **Container name** | **`n8n`**, image `localhost/pisowifi/n8n:2.43.8` (digest `sha256:bdeac5e5…5361e1`), up 2 days, `--network=none`, `--read-only`, `--cap-drop=all`, no-new-privileges, 1 GiB memory, 1 CPU |
| **n8n version** | **2.43.8** (`n8n --version` inside the container) |
| Endpoint | `127.0.0.1:5678` on the VM is `n8n-ingress.socket` → `systemd-socket-proxyd --connections-max=64` → the UNIX socket `/run/n8n-ingress/n8n.sock` in the container. Loopback only. |
| Egress | No container network. `HTTPS_PROXY=http://127.0.0.1:3128` → `n8n-egress-proxy.service` ("CONNECT allowlist to Google OAuth/Gmail API"). Plus `n8n-netguard.service` (nftables). **n8n cannot reach Laravel**, which is consistent with the one-way design. |
| Public route | `/etc/caddy/` contains no line mentioning `n8n`, `5678` or `webhook`. **No public n8n webhook route exists.** |
| Co-located services (untouched) | caddy, pisowifi-api, pisowifi-gateway, pisowifi-web-staging, pisowifi-worker, zerotier-one: all active |

Selected non-secret n8n settings (only these names were read):

| Setting | Value | Staging impact |
|---|---|---|
| `DB_TYPE` | `sqlite` | The version guard and claim rely on single-statement UPDATE atomicity, which SQLite serializes. **Compatible.** |
| `N8N_BLOCK_ENV_ACCESS_IN_NODE` | `true` | Stays as is. The design needs no env access. |
| `NODES_EXCLUDE` | executeCommand, localFileTrigger, readWriteFile, read/writeBinaryFile(s), ssh, ftp, git | **None of the workflows' 10 node types is excluded.** |
| `NODE_FUNCTION_ALLOW_BUILTIN` | (unset) | Code nodes cannot load `crypto`. HMAC uses the Crypto node, so this is **compatible**. |
| `N8N_RUNNERS_ENABLED` / `N8N_RUNNERS_MODE` | (unset) / `internal` | JS Code runs in the internal task runner. Plain-JS-only code is compatible; runner globals are still to be confirmed by execution. |
| `EXECUTIONS_MODE` | (unset → regular) | Single process. No queue workers. |
| `EXECUTIONS_DATA_SAVE_ON_SUCCESS` / `_ON_ERROR` / `_MANUAL_EXECUTIONS` | (unset → n8n defaults: save) | **Instance defaults save execution data.** The workflows override this to `none`/`false`. Import must preserve those settings; verify after import. |
| `EXECUTIONS_DATA_SAVE_ON_PROGRESS` | `false` | Good |
| `N8N_DEFAULT_BINARY_DATA_MODE` | `filesystem` | The raw body goes through binary storage; Extract From File reads it with `getBinaryDataBuffer`. To verify by execution. |
| `N8N_PAYLOAD_SIZE_MAX` | `4` (MiB) | Above the 16 KiB contract limit, which the workflow enforces itself. |
| `GENERIC_TIMEZONE` / `TZ` | `Asia/Manila` | Matches the workflow timezone |
| `N8N_PROTOCOL` / `N8N_HOST` / `N8N_PORT` / `WEBHOOK_URL` | `http` / `localhost` / `5678` / (unset) | The test URL appears as `http://localhost:5678/webhook-test/brbi-cims/reminder-events`. It is reachable only through an IAP port-forward. |
| `N8N_PROXY_HOPS` | `0` | — |
| `N8N_PUBLIC_API_DISABLED` | `true` | **No n8n public API.** Data Tables, the credential and the import must be done in the editor UI (or with the n8n CLI inside the container). |

Not inspected (outside the Stage 1 scope): the n8n database contents (existing workflows,
projects, license tier), `/etc/n8n/n8n.container.env` beyond the named variables, and the egress
proxy allowlist.

### Original preflight notes

Local gcloud configuration, read without any API call:

- Active account: `crisbasco04@gmail.com`. **Needs confirmation** that this is the intended
  execution identity.
- Project: **not set**. Zone: **not set**.
- The project ID, VM name and zone are not recorded in this repository. They are needed from
  the operator; they are not inferred by listing instances.

### Proposed access stages (each needs explicit approval)

**Stage 1 — read-only preflight.** Placeholders: `<PROJECT>`, `<VM>`, `<ZONE>`.

```bash
gcloud compute instances describe <VM> --project <PROJECT> --zone <ZONE> --format="value(name,status,zone)"
gcloud compute ssh <VM> --project <PROJECT> --zone <ZONE> --tunnel-through-iap --command '
  systemctl --user list-units --type=service --no-pager | grep -iE "n8n|caddy|piso" ;
  podman ps --format "{{.Names}} {{.Image}} {{.Status}}" ;
  podman exec <n8n-container> n8n --version ;
  podman exec <n8n-container> printenv DB_TYPE N8N_BLOCK_ENV_ACCESS_IN_NODE NODES_EXCLUDE N8N_RUNNERS_ENABLED EXECUTIONS_MODE N8N_DEFAULT_BINARY_DATA_MODE EXECUTIONS_DATA_SAVE_ON_SUCCESS EXECUTIONS_DATA_SAVE_ON_ERROR'
```

Only these named non-secret variables are printed. The full environment and files are never
dumped, and no service is restarted.

**Stage 2 — restricted editor access, with no public exposure.** An IAP SSH port-forward to the
n8n container port, used only from this workstation:

```bash
gcloud compute ssh <VM> --project <PROJECT> --zone <ZONE> --tunnel-through-iap -- -N -L 5678:localhost:<n8n-port>
```

No Caddy, firewall or DNS change is needed. The webhook **test URL** is then reached at
`http://localhost:5678/webhook-test/brbi-cims/reminder-events` through the tunnel.

**Stage 3 — staging modifications inside n8n**, done in the n8n editor through the tunnel:

1. Project: n8n **Community Edition only has personal projects** (team projects need a
   license). Use the owner's personal project unless a licensed staging project exists, and
   record this isolation limitation.
2. Create Data Tables `cims_reminder_schedules`, `cims_reminder_events` and
   `cims_reminder_delivery_logs` with exactly the columns in
   `docs/n8n/phase-2a-7/data-tables.schema.json`.
3. Create one Crypto credential, "CIMS Reminder HMAC (staging)", holding a synthetic 48-character
   secret. It is generated locally into a file in the session scratchpad (never printed) and
   pasted by the operator into the credential form.
4. Import both JSON files. Confirm they are unpublished/inactive. Assign the credential to the 3
   HMAC nodes, and set `ALLOWED_ACTIVITIES_ORIGIN` to a synthetic origin such as
   `https://cims.staging.invalid`.
5. Gmail stays disabled with no credential. The gate stays closed.

**Stage 4 — tests.** Signed synthetic requests are sent from this workstation through the
tunnel, one per "Listen for test event". Data Table rows are inspected in the editor. Workflow B
is run manually after seeding due rows.

### Limitation that needs a decision

The webhook **test URL accepts one request per "Listen for test event"**. Sequential cases
(valid/invalid HMAC, expired timestamp, malformed body, duplicates, reschedule, cancel,
complete, out-of-order, Data Table failure, response codes) work this way. **True concurrency
cannot be tested through the test URL.** Options:

- (a) Mark concurrency NOT TESTED on real n8n, relying on the local model plus source review.
- (b) Separately approve **temporarily activating a staging copy** of Workflow A, reachable only
  through the localhost tunnel (no public route), and deactivating it immediately afterwards.

## 3–8. Results

Original table (before Stages 2–3), kept for the record:

| Area | Status |
|---|---|
| Workflow import | NOT TESTED |
| Data Table creation | NOT TESTED |
| HMAC verification (valid accepted / invalid rejected) | NOT TESTED |
| Scheduling, rescheduling, cancellation, completion | NOT TESTED |
| Duplicate / out-of-order / concurrency | NOT TESTED |
| Data Table failure → 503 | NOT TESTED |
| Workflow B due selection, Manila time, creator routing, suppression | NOT TESTED |
| Gmail remains disabled; no sends | NOT TESTED (no send is possible: node disabled, no credential, gate closed) |
| Existing n8n workflows / PisoWiFi services unaffected | NOT TESTED |

Current status (after Stage 3, 2026-10-26):

| Area | Status |
|---|---|
| Workflow import (G1) | VERIFIED: A `Moj7uJTsrdtcY9H9` 16 nodes, B `tiYIF6ccGU2LZVLb` 13 nodes, both inactive and unpublished |
| Credentials (G2) | VERIFIED: the 3 HMAC nodes use "CIMS Reminder HMAC (staging)". **Finding:** the Gmail node has a credential "Gmail account" attached (see section 12) |
| Data Tables (G3) | VERIFIED: the 3 tables exist and resolve by name |
| Valid signature → 200 applied (G4) | PASSED (v1 create, execution 38; v2, execution 41) |
| Invalid signature (G5) | PASSED for the one-byte tampered body → 401 `signature_invalid` (see section 12), PASSED for the expired timestamp (360 s old, correctly signed) → 401 `timestamp_outside_tolerance`, and PASSED for the wrong secret → 401 `signature_invalid` (see section 13). **All three G5 variants PASSED** |
| Schema rejects (G6) | PASSED for a missing `recipient.email` → 422 `recipient_unexpected_fields` (see section 13). Other schema variants are NOT TESTED |
| Duplicate (G7) | PASSED (v3 first send `applied`, then an identical replay `duplicate`; see section 12) |
| Reschedule (G8) | PASSED for v1 → v2. The "repeat to v4" part is NOT TESTED |
| Out-of-order (G9) | PASSED (v2 sent while v3 stored → `stale`, row unchanged; see section 12) |
| Cancellation (G10) | PASSED: Step 1 (create `ci_activity:920002` v1, the insert path) and Step 2 (`schedule.cancelled` v2 `completed` → row cancelled in place, email cleared). Other cancel reasons and cancelling `bank_target`/`asset_target` subjects are NOT TESTED |
| Completion / 503 / concurrency (G10–G12) | NOT TESTED |
| Workflow B (G13–G15) | NOT TESTED. Workflow B has 0 executions |
| Gmail remains disabled; no sends | VERIFIED on the n8n side: node disabled, gate closed, B never executed, 0 delivery logs. The Gmail mailbox itself was not inspected |
| Existing n8n workflows / PisoWiFi services unaffected | NOT TESTED (not re-inspected after Stage 3) |

## 9. Security findings (pre-access)

- No secret exists in either workflow JSON. The signing secret will live only in an n8n Crypto
  credential (encrypted by n8n) and a scratchpad file deleted after use.
- On Community Edition, Data Tables and the Crypto credential sit in the owner's personal
  project. Anyone with owner/admin access to that instance can read the tables and use the
  credential, and so could forge signed events. The isolation is weaker than a dedicated project.
- Environment-variable access restrictions stay unchanged. Nothing in this plan needs them
  relaxed.

## 10. Remaining blockers

1. Separate explicit approval for Stages 2–4. The container is now known as `n8n` and the port
   as `127.0.0.1:5678` on the VM.
2. A decision on concurrency testing (option a or b in section 2).
3. Production ingestion stays blocked: there is no public webhook route (Caddy), and n8n's
   egress allowlist covers only Google. Neither is needed for staging through the tunnel.

Status update (2026-10-26): blocker 1 is resolved for Stages 2–3. Blockers 2 and 3 still stand.

## 11. Phase 2B readiness — BLOCKED

Originally: no real-n8n evidence existed. Now Workflow A has real-n8n evidence for create (v1) and
reschedule (v2) only. Workflow B and Gmail are untested, so Phase 2B remains BLOCKED.

## 12. Stage 2 and Stage 3 results (2026-10-25/26)

Sources: Stage 2/3 setup and the v1 test were performed in an earlier Codex session (as handed
over). Everything below was **re-verified read-only** in the n8n SQLite database
(`/var/lib/n8n/data/database.sqlite`, opened with `mode=ro` through host `python3` as user
`n8n`; no `sqlite3` CLI exists on the host or in the container). Credential tables were not read.

### Stage 2 — access

- Tunnel: `gcloud compute ssh crisbasco04@n8n-zerotier-pisowifi-monitoring … --tunnel-through-iap
  -- -N -L 5678:127.0.0.1:5678`, localhost only. Run it from Git Bash: Windows PowerShell 5.1
  drops the `--` separator ("unrecognized arguments: -N -L").
- `GET http://127.0.0.1:5678/healthz` → `{"status":"ok"}`. No Caddy, firewall, DNS or service change.

### Stage 3 — staging configuration

| Item | Verified value |
|---|---|
| Workflow A | `Moj7uJTsrdtcY9H9` "BRBI CIMS — Schedule Receiver", `active=0`, `activeVersionId=null`, 0 published versions, 16 nodes |
| Workflow B | `tiYIF6ccGU2LZVLb` "BRBI CIMS — Email Reminder", `active=0`, `activeVersionId=null`, 0 published versions, 13 nodes |
| Data Tables | `cims_reminder_schedules` (`R32h65VcoYjFQOFa`), `cims_reminder_events` (`Ye1trn5NNkvDPe4D`), `cims_reminder_delivery_logs` (`mYmcI18q0eKz9g5V`) |
| HMAC | The 3 Crypto nodes use "CIMS Reminder HMAC (staging)" |
| Gmail | `Send Reminder Email` `disabled=true`. Gate `Live Sending Approved?` compares `NOT_APPROVED` with `APPROVED_FOR_PHASE_2B` (closed) |
| Mapping fix | `trigger_at_manila` was missing from the v1 write and was then added to `Guarded Schedule Write` and `Insert Schedule Row` (Workflow A `updatedAt` 2026-10-25 13:50:50). The v1 row therefore had `trigger_at_manila=null`; v2 proves the fix |

**Finding (open):** `Send Reminder Email` has a credential **"Gmail account"** attached. The
Phase 2A-7 artifact had none. The node is disabled, the gate is closed and B is inactive, so no
send is possible, but the operator should decide before Phase 2B whether it stays attached. It
was not changed (credential changes are out of scope).

### Test G4 — v1 create (Codex session; state re-verified)

`ci_activity:920001`, `schedule.created` v1, synthetic creator `990001` /
`stage3.creator@example.test`. Execution 38 succeeded. The schedule row and the `applied` v1
receipt are present.

### Test G8 — v2 reschedule — PASSED (2026-10-26)

One signed request to the test webhook, with the operator running Workflow A's Test Workflow.

| Item | Result |
|---|---|
| Request | `schedule.changed` v2, event `153dbd75-16d2-46f0-8933-e278442801b9`, idempotency key `ci_activity:920001:v2`, trigger `2026-11-03T06:30:00Z` |
| Response | HTTP **200** `{"result":"applied","event_id":"153dbd75-16d2-46f0-8933-e278442801b9"}` |
| Execution | Workflow A #41, manual, success, 0.42 s |
| Schedule rows | Still **1**. Row `id=1` was updated in place: `schedule_version` 1 → **2**, `trigger_at_utc` `2026-11-03 06:30:00`, **`trigger_at_manila` `2026-11-03T14:30:00+08:00`**, `last_event_id` = the v2 event, `last_execution_id` 41, recipient unchanged, status `scheduled`, delivery `pending` |
| Receipts | 2 rows: v1 `applied` and v2 `schedule.changed` **`applied`** |
| Workflow B / Gmail | 0 executions, 0 delivery logs. Both workflows still inactive and unpublished |

Note: execution IDs 39 and 40 do not exist in `execution_entity`. They do not belong to A or B.
They are most likely the listener sessions that ended without a request (not verified).

### Test G7 — duplicate replay — PASSED (2026-10-26)

The stored state was v2, so the next valid version, **v3**, was used. One fixed body file (722
bytes, SHA-256 `00d1735662bef100894dba64bc0af373ff58cf7ba37b3f07704ddbc4444b73ed`): event
`2136914a-6c4e-4175-b631-e0fb23e808b3`, key `ci_activity:920001:v3`, `occurred_at`
`2026-10-26T03:30:06Z`, trigger `2026-11-04T02:00:00Z` / `2026-11-04T10:00:00+08:00`. Both
requests sent those exact bytes, each signed with a fresh timestamp. They were sequential, and the
operator started Test Workflow before each one.

Pre-check: the deployed `Decide Outcome` code is identical to the repo artifact (hash
`f03d47ac17ccd1d7`). The only difference in `Verify Signature and Validate` is the documented
staging setting `ALLOWED_ACTIVITIES_ORIGIN = 'https://cims.staging.invalid'`.

| Step | HTTP / body | Execution | Schedule row `id=1` | Receipts |
|---|---|---|---|---|
| Before | — | — | v2, `updatedAt 03:25:30.348`, exec 41 | 2 |
| First send | **200** `{"result":"applied",…}` | #42 success | **v3**, `trigger_at_manila 2026-11-04T10:00:00+08:00`, `updatedAt 03:32:18.036`, `last_execution_id 42` | 3 (v3 `applied`) |
| Identical replay | **200** `{"result":"duplicate",…}` | #43 success | **Unchanged: byte-identical** to the snapshot after the first send (`updatedAt 03:32:18.036`, `last_execution_id 42`) | 4 (4th = v3 **`duplicate`**) |

Still exactly 1 schedule row. Workflow B: 0 executions. Delivery logs: 0. Both workflows remain
inactive and unpublished, with unchanged `updatedAt`. The 4th receipt is by design (`Record
Event` is an append-only insert). It is the documented idempotent no-op, not a schedule change.

### Test G9 — out-of-order (stale) event — PASSED (2026-10-26)

While v3 was stored, one `schedule.changed` **v2** event was sent: new event
`42d1de91-2fcb-439a-9248-865112ec299a`, key `ci_activity:920001:v2`, a different trigger
(`2026-11-05T08:30:00+08:00`), the same creator. 722 bytes, SHA-256
`c8a8763cdd5e7057667bddebb5057477be79187ae17ea4dad1278fb865ff42fe`.

Pre-check (read-only): the deployed Workflow A connections are identical to the repo artifact
(canonical hash `c4d600ac68ca35bd`). The guard (`subject_key = K AND schedule_version < V`),
the read (by `subject_key`), the `Route Outcome` rules and the `Decide Outcome` code also match;
the only differences are default values n8n omits on save. The 409 `version_identity_conflict`
branch could not trigger, because no row existed at v2.

| Item | Result |
|---|---|
| Response | HTTP **200** `{"result":"stale","event_id":"42d1de91-2fcb-439a-9248-865112ec299a"}` |
| Execution | Workflow A #44, manual, success, 0.42 s |
| Schedule row `id=1` | **Byte-identical** to the pre-test baseline: v3, `trigger_at_manila 2026-11-04T10:00:00+08:00`, `updatedAt 2026-10-26 03:32:18.036`, `last_execution_id 42` |
| Schedule rows | 1 |
| Receipts | 5. Rows 1–4 unchanged. Row 5 = v2 `schedule.changed` **`stale`** (by design: the `done` route always inserts a receipt) |
| Workflow B / Gmail | 0 executions, 0 delivery logs. Both workflows inactive and unpublished, with `updatedAt` unchanged |

### Test G5 — tampered body (one byte) — PASSED (2026-10-26)

A valid outdated v2 event (`4fd5496c-f9ed-47c0-a7e8-5a319560e4d1`, SHA-256 `9bbe0b8f…a8bf9`)
was signed with the staging secret. Then exactly **one byte** was changed (offset 615, `Stage 3` →
`Stage 4` in `subject_label`, SHA-256 `5b0311c2…db79d`) and that body was sent with the original
signature. The tampered body is still schema-valid, and the base event is stale. A broken HMAC
check would therefore have shown as 200 `stale`, and could still not have changed the v3 schedule.

| Item | Result |
|---|---|
| Response | HTTP **401** `{"result":"rejected","code":"signature_invalid"}` |
| Execution | Workflow A #45, manual, success. Path (read from `execution_data`, node names only): `CIMS Schedule Webhook → Read Raw Body → HMAC Expected Signature → Seal Expected Signature → Seal Supplied Signature → Verify Signature and Validate → Event Valid? → Respond Rejected`. **No Data Table node executed** |
| Data Tables | Every schedule and receipt row is byte-identical to the pre-test snapshot. Schedule row v3 unchanged, receipts still **5**, delivery logs 0 |
| Workflow B / Gmail | 0 executions. Gmail node still disabled, gate closed |

Note (G16 hygiene, informational): manual executions store `execution_data`, including the raw
body and headers. The path check printed node names only. The repo artifact's settings
(`saveDataSuccessExecution: "none"`, `saveDataErrorExecution: "none"`,
`saveManualExecutions: false`, `saveExecutionProgress: false`, `timezone: "Asia/Manila"`,
`callerPolicy`) are **not present** on the deployed Workflow A. Its settings are only
`{"executionOrder":"v1","binaryMode":"separate"}`, so instance defaults (save) apply (see
section 2). This is a G16 finding to resolve, with approval, before production use. It was not
changed.

### Signing tool notes

The test sender lives only in the session scratchpad. It reads the secret with PowerShell
`Read-Host -AsSecureString` and passes it to Node on stdin, so the secret is never in an
argument, environment variable, file or log. Two approaches failed on Windows and were replaced:
a clipboard read (the sandboxed shell could not see the desktop clipboard) and a raw-mode Node
prompt (conhost delivers Ctrl+V as the single character `\u0016`). Dummy-secret dry runs
matched PHP `hash_hmac` byte for byte.

## 13. G16 finding — execution-data retention and the Gmail credential (2026-10-26, read-only)

Status: **findings VERIFIED (read-only). Settings fix APPLIED by the operator in the editor
(2026-10-26) and VERIFIED read-only, with one deviation (`timezone` not stored; see "Applied
fix"). Retention for new manual runs VERIFIED on real n8n** (executions #46 and #47; see
"Expired-timestamp check and retention result").

### Expired-timestamp check and retention result — PASSED (2026-10-26)

| Item | Result |
|---|---|
| Request | Event `620b53cc-5a2f-4d52-860d-15fb59fc6118` (v2, 722 bytes, SHA-256 `321f264b…aad8ef7`), correctly signed, `X-CIMS-Timestamp` 360 s old. PC↔VM clock difference < 7.1 s (bounded read-only) |
| Response | HTTP **401** `{"result":"rejected","code":"timestamp_outside_tolerance"}` at 04:19:15Z. The operator saw `Respond Rejected` in the editor |
| Workflow A ran | `manual_success` 10 → **11** (latestEvent `04:19:15`); `sqlite_sequence` execution id 46 → **47** |
| Data Tables | Byte-identical: schedule v3 row unchanged, receipts **5**, delivery logs **0** |
| Workflow B | No new run (`manual_success` still 1, from the 04:12:23 incident) |
| Retention of #47 | At 04:20:43Z (88 s after the send), **no** `execution_entity`, `execution_data`, `execution_metadata` or annotation row existed for #47, and there were no orphan data rows. The intermediate soft-deleted state (`deletedAt` set) was **not observed directly**: the 1-minute hard-delete pass had already removed it. Only the end state (fully purged) is evidenced |
| History | Executions #5 and #34–#45 present, `deletedAt` null, `execution_data` fingerprints identical |
| Everything else | Workflow settings, node/connection hashes, inactive/unpublished, Gmail disabled, gate closed, other workflows and credential metadata: identical to before the send |

### Test G6 — missing `recipient.email` — PASSED (2026-10-26)

Laravel never emits an active event without the creator's email (`recipientReachable()`). A
creator without email yields no event, or `schedule.cancelled` / `recipient_unavailable` with
`recipient: {user_id}`. So this tests n8n's defence in depth. The deployed validator (hash
`4cd19494049a13b0`) checks the exact `recipient` key set before the email format, so the
expected code is `recipient_unexpected_fields`. This was confirmed locally with the deployed code:
adding back only the email made the body valid.

| Item | Result |
|---|---|
| Request | Fresh v2 event `4e1e17f5-dd6e-48fb-a4c2-89fd13938e2e`, `recipient: {"user_id":990001}`, current timestamp, correct HMAC. 684 bytes, SHA-256 `9bbd9533…3eeba0` |
| Response | HTTP **422** `{"result":"rejected","code":"recipient_unexpected_fields"}` at 04:24:59Z |
| Workflow A ran | `manual_success` 11 → **12** (latestEvent `04:24:59`); execution id counter 47 → **48** |
| Data Tables | Byte-identical: schedule v3, receipts **5**, delivery logs **0** |
| Workflow B | `manual_success` still 1 (no new run) |
| Retention of #48 | At 04:27:11Z, no entity, data, metadata or annotation row, and no orphans. Fully purged |
| Everything else | History (#5, #34–#45 with data fingerprints), CIMS workflow hashes and settings, inactive/unpublished, Gmail disabled, gate closed, PisoWiFi/N1G workflows and credential metadata: identical to the pre-send baseline |

### Test G5 — wrong HMAC secret — PASSED (2026-10-26)

The sender never used the staging secret. It generated a random 48-character synthetic secret in
memory, signed with it and discarded it (never printed or stored). Local check with the deployed
validator: the same body signed with the configured key is accepted, and signed with a random key
it gives 401 `signature_invalid`. So only the HMAC check can fail.

| Item | Result |
|---|---|
| Request | Fresh, schema-valid v2 event `91c6a79b-78b8-4615-ad2b-b51838fc9d55`, current timestamp, signed with the random key. 722 bytes, SHA-256 `eb66cab9…a4e652` |
| Response | HTTP **401** `{"result":"rejected","code":"signature_invalid"}` at 04:33:29Z |
| Workflow A ran (exactly once) | `manual_success` 12 → **13** (latestEvent `04:33:29`); execution id counter 48 → **49** |
| Second sender run | The operator's later re-run was **refused by the sender's own lock** before any network call (no request, no execution) |
| Data Tables | Byte-identical: schedule v3, receipts **5**, delivery logs **0** |
| Workflow B | `manual_success` still 1 |
| Retention of #49 | At 04:35:05Z, no entity, data, metadata or annotation row, and no orphans. Fully purged |
| Everything else | History, CIMS hashes and settings, inactive/unpublished, Gmail disabled, gate closed, PisoWiFi/N1G workflows and credential metadata: identical to the pre-send baseline |

### Test G10 Step 1 — create a new subject `ci_activity:920002` v1 — PASSED (2026-10-26)

A new synthetic subject keeps the G4–G9 evidence on `920001` intact. Shape: Laravel
`ReminderScheduleOutbox::payload()` for a specific-time schedule. This is the first real run of
the **insert** path since the `trigger_at_manila` mapping fix. The deployed `Guarded Schedule
Write` and `Insert Schedule Row` map all 24 columns, `alwaysOutputData=true`.

| Item | Result |
|---|---|
| Request | `schedule.created` v1 `73f4c866-aecd-484b-adf1-12555d81b2e7`, key `ci_activity:920002:v1`, schedule 2026-11-10 15:30 Asia/Manila, synthetic creator `990002` / `g10.creator@example.test`. 715 bytes, SHA-256 `f9703bd1…5612df7` |
| Response | HTTP **200** `{"result":"applied","event_id":"73f4c866-…"}` at 04:40:49Z |
| New row | Exactly **1** `920002` row (`id=2`): v1, `scheduled`, `trigger_at_utc 2026-11-10 07:30:00`, **`trigger_at_manila 2026-11-10T15:30:00+08:00`**, `scheduled_at_manila` same, recipient 990002 / `g10.creator@example.test`, delivery `pending`, `last_execution_id 50` |
| Schedule rows / receipts | 1 → **2** / 5 → **6** (receipt 6 = `920002` v1 `schedule.created` `applied`) |
| `920001` | Schedule row **byte-identical** (v3). Receipts 1–5 unchanged |
| Delivery logs / Workflow B | 0 / `manual_success` still 1 |
| Execution #50 | Run counter 13 → 14. Fully purged (no entity, data, metadata or annotation row, no orphans) |
| Security settings, history, other workflows, credentials | Unchanged |

**Observation: Workflow A layout edit (not a logic change).** Between 04:40:06 and 04:40:17Z the
editor saved 4 versions of Workflow A (`workflow_history`, author "cris basco"). The canonical
node hash changed from `65b8d35b6ad65aff` to `8f1f3d12f49d0e3c`. A read-only path diff against
the 2026-10-25 snapshot shows that **only `position` changed, on 9 nodes**: no parameter,
credential, code, filter, mapping or connection change, and no node added or removed.
Connections (`c4d600ac68ca35bd`) and code hashes are unchanged. **New logic baseline:** node hash
`8f1f3d12f49d0e3c`; future integrity checks should compare nodes **excluding `position`**.

### Test G10 Step 2 — cancellation (`completed`) — PASSED (2026-10-26)

Shape: Laravel `payload()` with `$desired === null` (`REASON_COMPLETED`): schedule fields null,
`recipient: {user_id}`, `content: null`. The deployed validator accepts it; adding an email to a
cancel gives 422 `recipient_unexpected_fields`. This was also the first real-n8n write of `null`
into the Data Table date columns through an update: **it worked** (no 503).

| Item | Result |
|---|---|
| Request | `schedule.cancelled` v2 `bab273d1-c0df-4397-9c2f-81e5eba61678`, key `ci_activity:920002:v2`, `cancel_reason: "completed"`, `recipient {"user_id":990002}`, `content null`. 508 bytes, SHA-256 `461cac6c…b6f2a1` |
| Response | HTTP **200** `{"result":"applied","event_id":"bab273d1-…"}` at 04:49:35Z |
| Row `id=2` (updated **in place**; `createdAt` unchanged) | v1 → **v2**, `status cancelled`, `cancel_reason completed`, **`recipient_email ""`**, `scheduled_at_utc null`, `trigger_at_utc null`, `scheduled_at_manila ""`, `trigger_at_manila ""`, `has_specific_time 0`, `subject_label ""`, `activities_url ""`, **`delivery_status cancelled`**, `last_execution_id 51`. `recipient_user_id` 990002 retained |
| Schedule rows / receipts | Still **2** (one `920002` row) / 6 → **7** (receipt 7 = v2 `schedule.cancelled` `applied`) |
| `920001` | Row byte-identical (v3); receipts 1–6 unchanged |
| Delivery logs / Workflow B / Gmail | 0 / `manual_success` still 1 / node disabled, gate closed |
| Execution #51 | Run counter 14 → 15. Fully purged (no entity, data, metadata or annotation row, no orphans) |
| Workflows | Logic hashes (positions ignored) A `8bf269314706ca46`, B `48aae677b41942b2` **match the import snapshots**; connections unchanged; inactive, unpublished; G16 settings unchanged. Further canvas-only saves: A at 04:47:51Z, B until 04:47:18Z |
| History, other workflows, credentials | Unchanged |

Residual limits (unchanged): SQLite free pages and WAL until `VACUUM`; in-flight data during a
run; no redaction on Community Edition. Not verified: production-mode (`webhook`/`trigger`)
deletion, which needs an active workflow.

### Applied fix — verification (2026-10-26)

Stored settings, identical on Workflow A and Workflow B:
`{"executionOrder":"v1","binaryMode":"separate","timeSavedMode":"fixed","saveDataErrorExecution":"none","saveDataSuccessExecution":"none","saveExecutionProgress":false,"saveManualExecutions":false,"callerPolicy":"workflowsFromSameOwner","availableInMCP":false}`

| Check | Result |
|---|---|
| The 4 save settings + `callerPolicy` | VERIFIED as approved |
| `timezone: Asia/Manila` | **Not stored.** The editor appears not to persist a timezone equal to the instance default. Runtime is unchanged: `GENERIC_TIMEZONE=Asia/Manila` applies |
| Extra keys written by the editor | `timeSavedMode:"fixed"` and `availableInMCP:false` (UI defaults; the second keeps the workflows unexposed to MCP) |
| Node / connection hashes | Unchanged (A `65b8d35b6ad65aff` / `c4d600ac68ca35bd`, B `db4f8d6a543564dd` / `47534de27abbf5c9`) |
| Active / published | Both `active=0`, `activeVersionId=null`, 0 published versions |
| Gmail / gate | `Send Reminder Email` disabled; gate `NOT_APPROVED` ≠ `APPROVED_FOR_PHASE_2B` |
| Execution history | All 11 executions (#5 other, #34–#45 CIMS A) present, `deletedAt` null, `execution_data` fingerprints identical |
| Other workflows / credentials | PisoWiFi status notifications (active, published) and N1G test: nodes, connections, settings, versionId and updatedAt identical. All 3 credentials: metadata (id, name, type, updatedAt) identical. Secret columns were not read |

### Incident during the expired-timestamp check: 404 and an accidental Workflow B manual run

- At 04:12:23Z the operator started **Test Workflow on Workflow B** by mistake. The workflow
  statistic `manual_success` for B = 1 (latestEvent `2026-10-26 04:12:23`), and `sqlite_sequence`
  for `execution_entity` = 46. The execution (#46) was **soft- and hard-deleted under the new
  settings**: no `execution_entity`, `execution_data`, metadata or annotation rows exist for it,
  and there are no orphan data rows. This is incidental real-n8n evidence that
  `saveManualExecutions=false` works (for B). Its node path cannot be inspected, by design. B
  changed nothing: delivery logs 0, schedule and receipts byte-identical, gate closed, Gmail
  disabled. With the only row due on 2026-11-04, no row was due.
- At 04:12:37Z the original g5ts request (event `6815e585…`) got **HTTP 404 "The requested
  webhook "brbi-cims/reminder-events" is not registered."** Workflow A was not listening, so
  n8n's test-webhook registry rejected it **before any execution**. Workflow A `manual_success`
  is still 10 (latest 03:46:37), and no A execution after #45 exists.
- The original sender's lock and 404 result are kept as evidence in `scratchpad/g5ts/`. The retry
  uses a separate sender (`scratchpad/g5ts-retry/`, new event `620b53cc…`, its own lock).

### How n8n 2.43.8 decides what is stored (from the container's `dist/` source)

| Mechanism | Behaviour |
|---|---|
| `execution-lifecycle/to-save-settings.js` | Per-workflow `saveDataErrorExecution` / `saveDataSuccessExecution` (`'all'`/`'none'`/`'DEFAULT'`), `saveManualExecutions` (boolean/`'DEFAULT'`), `saveExecutionProgress`. A missing key falls back to the instance env. Here `EXECUTIONS_DATA_SAVE_ON_SUCCESS`, `_ON_ERROR` and `_MANUAL_EXECUTIONS` are unset, so n8n's defaults apply: **save** |
| `execution-lifecycle-hooks.js` (after execution) | **Manual / test-webhook runs** (`mode = manual`) with `saveManualExecutions=false` are **soft-deleted** (`deletedAt` set). **Production runs** with `"none"` for their status go through `deleteInFlightExecution` |
| Pruning (`executions-pruning.service.js`, `execution.repository.js`) | A hard delete removes the execution row and calls `binaryDataService.deleteMany`. Instance env: `EXECUTIONS_DATA_PRUNE=true`, `MAX_AGE=336` h, `PRUNE_MAX_COUNT=5000`, `HARD_DELETE_BUFFER=0`, `PRUNE_HARD_DELETE_INTERVAL=1` (min), so soft-deleted runs are hard-deleted within about 1–2 min |
| Execution redaction (`modules/redaction/`, workflow setting `redactionPolicy`) | **License-gated.** `resolvePolicy()` returns `'none'` unless `isDataRedactionLicensed()`. It also redacts only when executions are **read** (UI/API); it does not change stored data. **Not usable on this Community Edition instance** |

### Current state

| Item | Value |
|---|---|
| Workflow A settings | `{"executionOrder":"v1","binaryMode":"separate"}`. The artifact's `saveDataSuccessExecution:"none"`, `saveDataErrorExecution:"none"`, `saveManualExecutions:false`, `saveExecutionProgress:false`, `timezone:"Asia/Manila"` and `callerPolicy:"workflowsFromSameOwner"` were **dropped at import** |
| Workflow B settings | The same: `{"executionOrder":"v1","binaryMode":"separate"}` |
| Stored A executions | **10**, all `manual`, each with an `execution_data` row (2026-10-25 12:39 → 2026-10-26 03:46), none soft-deleted. They contain synthetic bodies, headers with signatures and HMAC node outputs. The signatures are bound to their timestamps (±300 s), so they cannot be replayed now. They will be age-pruned at 336 h (≈ 2026-11-08/09) unless deleted earlier (deleting them needs separate authorization) |
| Stored B executions | 0 |
| Baseline hashes before any fix | A nodes `65b8d35b6ad65aff`, connections `c4d600ac68ca35bd`. B nodes `db4f8d6a543564dd`, connections `47534de27abbf5c9`. Both `active=0`, 0 published versions |

### Limitations not addressable by per-workflow settings

- While a run is in progress, its data exists in the DB (the execution is created at start) and is
  pushed to the open editor tab. `saveManualExecutions=false` removes it only at the end (soft
  delete, then hard delete about 1 min later).
- SQLite: deleted rows can remain in free pages or the WAL until `VACUUM`
  (`DB_SQLITE_VACUUM_ON_STARTUP=false`). Changing that is instance configuration (out of scope).
- No redaction on Community Edition (see above).
- After this change, real-n8n evidence for later tests comes from the HTTP response, Data Table
  diffs and the operator's live editor view. The stored execution path will no longer be available
  (except briefly before the hard delete).
- Instance-wide defaults (`EXECUTIONS_DATA_SAVE_*` env) would protect every workflow, but they are
  system configuration and need a service restart. Not proposed.

### Gmail credential (Phase 2B blocker)

`Send Reminder Email` (Workflow B) references credential `gmailOAuth2` **"Gmail account"**
(id `eG8qq0MU27sbrya2`). The same credential is referenced by **"PisoWiFi status notifications
(simple)"** and **"N1G Gmail one-recipient delivery test - 20261022"**. CIMS reminders would
therefore send from the **PisoWiFi mailbox**. Before Phase 2B, the operator must verify which
account it authorizes and decide on a dedicated, BRBI-authorized sender credential. The
credential and those workflows were not opened or changed.

## 14. Fast-track readiness review (2026-10-26)

### Workflow B — deployed configuration (read-only) and validation status

Deployed Workflow B matches the repo artifact. The code hashes are identical (`Select Deliverable`
`71e66dac635b5726`, `Confirm Claims` `e9898f4cf43c00f6`, `Interpret Gmail Outcome`
`f1f8a18ee348f0ba`). The logic hash (positions ignored) matches the import snapshot. Wiring:
`Every 5 Minutes → Find Due Reminders → Read Subject Rows → Select Deliverable → Claim Action?`;
`claim → Live Sending Approved? (closed) → Claim Reminder → Read Claimed Rows → Confirm Claims →
Send Reminder Email (disabled) → Interpret Gmail Outcome → Record Delivery Outcome + Write
Delivery Log`; `record` (stale claim) → `Record Delivery Outcome + Write Delivery Log`.

| Requirement | Evidence | Status |
|---|---|---|
| Cancelled/completed excluded | `Find Due Reminders` = `status = scheduled AND trigger_at_utc <= $now (UTC)`. `920002` is `cancelled` with `trigger_at_utc null`. `Select Deliverable` also skips any subject whose highest version is not `scheduled` | VERIFIED (deployed config + G10 state) |
| Creator, not the assigned CI | Laravel `ReminderScheduleOutbox` uses `creator_id`; `test_event_goes_to_the_activity_creator_never_the_assigned_ci_or_the_editor` passes (48/48 integration tests). n8n uses only the event's `recipient.email` (G4/G10 rows) | VERIFIED (Laravel tests + real rows) |
| Asia/Manila | Laravel `trigger_at_local` from `cims.display_timezone`. The receiver re-validates the offset. `trigger_at_manila` stored correctly (G8, G10). Instance `GENERIC_TIMEZONE=Asia/Manila`. The email text uses `trigger_at_manila` | VERIFIED on the receiver; the email text is reference model only |
| 5-minute polling | Stored rule `{"field":"minutes"}`. The n8n Schedule Trigger source default `minutesInterval = 5`. Runs only while B is **active** | VERIFIED (config); behaviour NOT TESTED (needs activation) |
| Due selection (G13) | **PASSED on real n8n** (see below) | VERIFIED |
| Duplicate prevention, state transitions, failure handling | Guarded claim (`status=scheduled`, expected `delivery_status`/`attempts`), claim re-read, `sent`/`uncertain` never retried, `failed` ≤ 3 retries, stale `sending` > 30 min → `uncertain`. 34/34 reference-model tests pass | Reference model only; **real n8n is possible only after the gate opens** (Phase 2B) |

### Hosting and delivery blockers (Laravel → n8n)

1. **No public ingress to n8n.** There is no Caddy route; the test webhook is reachable only through the IAP tunnel. Production needs a public HTTPS route limited to `POST /webhook/brbi-cims/reminder-events` and Workflow A **active**. Both are system/workflow changes that need approval.
2. **No delivery runner on InfinityFree Free.** No cron, external pingers prohibited, no SSH/CLI. Only `N8N_REMINDERS_DISPATCH_AFTER_COMMIT=true` works there, and it tries only the saving request's own event, once. A failed or slow delivery stays pending indefinitely, **including cancellations** (a completed activity could still be emailed). `n8n-reminders:deliver` (backoff, `--retry-failed`, `--reconcile`) needs a host with a scheduler.
3. **Outbound HTTPS from InfinityFree to the VM is UNVERIFIED.** The 5 s timeout must be met.
4. **Shared n8n instance and Data Tables.** Staging synthetic rows (`920001` v3 `scheduled`, due 2026-11-04, `@example.test`) live in the tables production would use. They must be removed or cancelled **before Workflow B is activated**, or B would try to email them.
5. **Credentials.** The staging HMAC secret must not become the production secret: create a new one, set it in Laravel `.env` and the n8n credential. The Gmail node still references the PisoWiFi credential.
6. Open findings from section 13: production-mode retention not verified; 10 old executions keep their data until about 2026-11-08/09; `timezone` setting not stored (no runtime effect).

### Dedicated BRBI Gmail sender — requirements (prepared, nothing created)

- **Account:** a BRBI-owned mailbox used only for reminders (preferably Google Workspace, so the OAuth app can be **Internal**). Do not use the PisoWiFi account.
- **Google Cloud OAuth client** (Web application) in a BRBI-controlled project, with the Gmail API enabled and scope `https://www.googleapis.com/auth/gmail.send` only.
  - Authorized redirect URI: `http://localhost:5678/rest/oauth2-credential/callback` (n8n `N8N_HOST=localhost`, reached through the IAP tunnel).
  - If the app is **External** in *Testing* status, refresh tokens **expire after 7 days**, so reminders would silently stop. Use Internal (Workspace) or move to *In production*. `gmail.send` is a sensitive scope, so expect the unverified-app screen unless verified.
- **n8n:** create a **new** credential, e.g. "BRBI CIMS Gmail (reminders)", of type `gmailOAuth2`. Sign in as the BRBI sender through the tunnel. The egress proxy already allows Google OAuth/Gmail (the existing credential uses it). Then **re-point only** `Send Reminder Email` to it. That is a workflow edit and needs approval.
- **Verify before any send:** the credential's authorized account equals the approved BRBI sender, and the PisoWiFi credential/workflows are unchanged (metadata comparison).

### Deployment checklist (in order; each state change needs approval)

1. G13 staging: a due synthetic row → manual B run with the gate closed. `Select Deliverable` should show exactly that row with the creator's email; no writes.
2. Dedicated Gmail credential created and authorized (above); `Send Reminder Email` re-pointed.
3. Phase 2B single real send on staging: an operator-owned test inbox as the synthetic creator, gate opened and Gmail enabled **in a controlled manual run**. Verify one email, `delivery_status sent`, one delivery log, no resend on the next run.
4. Production HMAC secret: new value in the n8n credential and Laravel `.env` (`N8N_REMINDERS_SIGNING_SECRET`).
5. Remove/cancel the staging synthetic rows (approval; historical evidence kept in this README).
6. Public HTTPS route for `POST /webhook/brbi-cims/reminder-events` only (Caddy, approval), then activate Workflow A.
7. Laravel: `N8N_REMINDERS_ENABLED=true`, `N8N_REMINDERS_WEBHOOK_URL=https://…/webhook/brbi-cims/reminder-events`, secret, `N8N_REMINDERS_DISPATCH_AFTER_COMMIT=true`. Verify outbound HTTPS from the host, and decide on the delivery-runner gap (blocker 2).
8. Activate Workflow B (5-minute polling). Confirm production-mode execution retention (`none`).

### Test G13 — Workflow B due selection with the gate closed — PASSED (2026-10-26)

| Step | Evidence |
|---|---|
| A: create `ci_activity:920003` v1 | HTTP **200** `applied` (event `b620f906-53fd-44d9-910c-2827c1a2441f`, 05:07:32Z). Row `id=3`: v1, `scheduled`, `pending`, recipient `990003` / operator-owned test inbox (masked `r***@gmail.com`), **`trigger_at_manila 2026-10-26T13:11:00+08:00`** (`trigger_at_utc 05:11:00`). Receipt 8 `applied`. `920001`/`920002` byte-identical. Execution #52 purged |
| B: one manual run at 05:11:29Z (29 s after the trigger) | Operator canvas: `Find Due Reminders` 1 item → `Read Subject Rows` 1 → `Select Deliverable` 1 → `Claim Action?` true → **`Live Sending Approved?` false**; `Send Reminder Email` not executed. The single due item can only be `920003`: `920001` is due 2026-11-04 and `920002` is `cancelled` with no trigger |
| Database after B | B `manual_success` 1 → **2** (05:11:29). **All 3 schedule rows byte-identical** (`920003` still `pending`, attempts 0, no claim). **8 receipts identical. Delivery logs 0.** Execution #53 purged, no orphans. Logic hashes, inactive/unpublished, G16 settings, Gmail disabled, gate closed, PisoWiFi workflows, credential metadata: unchanged |

Limit: the stored execution path is not available (retention `none`). The selected item's
identity is inferred from the data (the only eligible row) plus the operator's view.
Observation: the PisoWiFi "Gmail account" credential `updatedAt` changed to 2026-10-26
05:02:38Z, with no workflow run at that time (PisoWiFi latest success 03:05:37). This is
consistent with the credential being opened or tested in the editor; the operator was asked to
confirm. CIMS did not use it.

### Phase 2B prep — PisoWiFi credential incident and dedicated BRBI Gmail credential (2026-10-26)

- **Incident (operator-side, resolved by the operator):** between 13:02 and 13:38 Manila the
  shared PisoWiFi credential **"Gmail account"** (`eG8qq0MU27sbrya2`) was edited 13 times (n8n
  audit `credentials.updated`). This was an attempt to connect the BRBI mailbox, and it happened
  while the **active** "PisoWiFi status notifications" workflow depended on that credential. The
  operator restored it at 14:08–14:09 Manila and confirmed that the Client ID matches the original
  PisoWiFi OAuth client. **Not yet verified:** the next PisoWiFi notification (none has run since
  11:05 Manila, `production_success` = 30). PisoWiFi services: all active, 0 restarts, 0 error
  journal entries.
- **Dedicated credential:** "BRBI CIMS Gmail (reminders)" `rD7vDt4KYtPc8iqC` (`gmailOAuth2`),
  created 14:03:11 Manila, separate from `eG8qq0MU27sbrya2`.
- **Attachment (06:17:19Z):** the operator re-pointed only `Send Reminder Email`. Verified
  read-only: `Send Reminder Email.credentials.gmailOAuth2` = `rD7vDt4KYtPc8iqC`, still
  **disabled**; Workflow B logic without that reference = `baca4717b3cf407c` (**unchanged**);
  connections `47534de27abbf5c9` unchanged; gate closed; inactive and unpublished. New B logic
  baseline (with the credential) = `ec039c0a4257a53a`. PisoWiFi and N1G workflows still reference
  `eG8qq0MU27sbrya2` and are identical. "Gmail account" `updatedAt` stays 06:09:20Z. Data Tables
  (3 rows, 8 receipts, 0 logs), run counters and history unchanged.

### Phase 2B — first real Gmail reminder delivery — PASSED (2026-10-26)

- A first attempt (operator-reported, about 06:23Z) **did not start any execution** (execution
  counter unchanged at 54). After the path was closed, a closing mistake (the gate node disabled
  instead of the Gmail node, which bypasses the gate because disabled n8n nodes pass items
  through) was caught by read-only verification and fixed (06:31:27Z, logic back to
  `ec039c0a4257a53a`).
- At 06:33:24–06:33:34Z the operator re-applied the same two approved edits (gate `leftValue` →
  `APPROVED_FOR_PHASE_2B`, Gmail node enabled; field diff vs `8b2e3f1b` = exactly those two). They
  then ran Workflow B manually **once**.

| Item | Evidence |
|---|---|
| Execution | Exactly **one** new execution, **#55** (counter 54 → 55). B `manual_success` 3 → 4 at 06:33:52Z. Purged (retention `none`), no orphans |
| Email | Operator confirmed: received in the **Inbox** of the operator-owned test inbox at about 14:33 Manila, **from `cimsbinhiruralbanking@gmail.com`**, subject `BRBI CIMS reminder: G13 Synthetic CI Activity scheduled 2026-10-26 13:11 (Asia/Manila)` |
| Row `920003` | `delivery_status` **`sent`**, `delivery_attempts` **1**, `delivery_claim` `55:1792996430816:ci_activity:920003` (owned by #55), `delivery_next_attempt_at` null, `status` still `scheduled` v1 |
| Delivery log | **1 row**: `delivery_key ci_activity:920003:v1`, attempt 1, **outcome `sent`**, **`provider_message_id 1a176ea5633f2a07`**, `error_code ""`, event `b620f906-…`, recipient_user_id 990003 |
| Real Gmail response shape | `Interpret Gmail Outcome` recognised the real Gmail v2.1 response (message id extracted), so the result is `sent`, not `uncertain` |
| Duplicates | None: one execution, one log row, attempts 1 |
| Isolation | `920001`/`920002` and all 8 receipts byte-identical. PisoWiFi/N1G workflows and credential references (`eG8qq0MU27sbrya2`) unchanged. Credential metadata unchanged. PisoWiFi services active, 0 restarts, 0 error journal entries, no Gmail/OAuth errors in the n8n log |

State after the send (06:35Z): the path was **open** (gate `APPROVED`, Gmail node enabled, logic
`99525806bfb8ed11`) and had to be closed. Still to verify: no resend on a further run (optional
G15-style check), and the next PisoWiFi notification arriving from the PisoWiFi sender.

Closed again (06:37:43Z), verified read-only: field diff vs the closed baseline `8b2e3f1b` = **none**.
Logic `ec039c0a4257a53a` (closed baseline, with the BRBI credential). Gate enabled with
`NOT_APPROVED`; `Send Reminder Email` disabled with `rD7vDt4KYtPc8iqC`; inactive and unpublished.
Execution counter still 55. `920003` row and its single delivery log are identical to the post-send
state. PisoWiFi workflows, credential references and metadata unchanged.

## 15. Production integration plan (2026-10-26, read-only investigation; nothing applied)

### Findings

| Area | Finding |
|---|---|
| Public ingress | Caddy (auto-TLS) already serves `pesowifi.duckdns.org` and `gw.pesowifi.duckdns.org`. Firewall `default-allow-http/https` (0.0.0.0/0, tags present on the VM, IP `34.158.53.160`). n8n listens only on `127.0.0.1:5678`. **No firewall or DNS change is needed**; a new Caddy site block is enough. `WEBHOOK_URL` unset (affects only URLs displayed in the editor) |
| Laravel → n8n | Dispatcher: HMAC-signed POST, 5 s timeout, **any non-2xx is retried** with exponential backoff up to `max_attempts` (10), then `failed` (re-queue with `--retry-failed`). Config refuses non-`https://` URLs and secrets under 32 characters |
| No-cron retries | **The app already has an approved request-driven fallback:** the bell (`/notifications/ci-activities/feed`, polled every 30 s by logged-in CI users) runs `ProcessDueCiActivityReminders`, throttled by `Cache::add(..., 1 min)`. The outbox has no equivalent; only after-commit dispatch, once |
| Existing schedules | Activities scheduled **before** enabling get no outbox event until saved again. `--reconcile` needs the CLI, which InfinityFree lacks |
| Workflow A origin check | Deployed `ALLOWED_ACTIVITIES_ORIGIN = 'https://cims.staging.invalid'`. **Production events would get 422 `activities_url_origin_not_allowed`** until it is set to the production `APP_URL` origin |
| Synthetic rows | `920001` is still **`scheduled`** (2026-11-04, `@example.test`) and would be emailed once B is active. `920002` (cancelled) and `920003` (sent) are inert. 8 receipts and 1 delivery log are inert. Subject IDs 92000x could collide with real IDs only in the far future |

### Recommended architecture

```text
User saves activity (InfinityFree Laravel, MariaDB)
  → same transaction: reminder_schedule_states + reminder_outbox_events (existing)
  → after commit: deliver this event (N8N_REMINDERS_DISPATCH_AFTER_COMMIT=true, existing)
  → retries: bounded outbox pass on the bell feed (NEW, proposed; mirrors the existing reminder fallback)
  → HTTPS POST https://<cims-hook-host>/webhook/brbi-cims/reminder-events
      Caddy: exact path + POST only, 16 KiB body cap, everything else 404 (NEW site block)
      → 127.0.0.1:5678 → Workflow A (ACTIVE): HMAC, ±300 s, version guard → Data Tables
  → Workflow B (ACTIVE, every 5 min, gate approved, Gmail enabled) → BRBI Gmail → creator
```

Draft Caddy block (not applied; hostname to be chosen; a DuckDNS sub-name resolves to this VM):

```caddy
cims-hooks.pesowifi.duckdns.org {
	request_body {
		max_size 16KB
	}
	@hook {
		method POST
		path /webhook/brbi-cims/reminder-events
	}
	handle @hook {
		reverse_proxy 127.0.0.1:5678 {
			header_up -Forwarded
			header_up -X-Real-IP
			header_up -X-Client-IP
			header_up -True-Client-IP
			header_up -CF-Connecting-IP
		}
	}
	handle {
		respond 404
	}
}
```

The editor, the REST API, `/webhook-test/` and every other path stay unreachable from the Internet.

Proposed Laravel change (needs approval; changes the earlier "after-commit only" decision): in
`CiActivityNotificationFeedController`, after the existing reminder pass, when
`services.n8n_reminders.enabled` is set and `Cache::add('n8n-reminders:outbox-pass', true, 1 min)`
succeeds, call `ReminderOutboxDispatcher::deliverPending(2)` inside `try/catch` (log and continue).
It is bounded (≤ 2 events × 5 s), global (one pass per minute across all users), and safe under
overlap (the dispatcher's claim is already tested for concurrent workers). It gives retries of
failed or late events, **including cancellations**, within about 1 minute whenever any CI user has
the app open. Residual gap: if nobody is logged in, pending events wait until the next login.

### Settings, rotation and cleanup

- **Laravel production `.env`:** `N8N_REMINDERS_ENABLED=true`, `N8N_REMINDERS_WEBHOOK_URL=https://<cims-hook-host>/webhook/brbi-cims/reminder-events`, `N8N_REMINDERS_SIGNING_SECRET=<new ≥ 48 chars>`, `N8N_REMINDERS_DISPATCH_AFTER_COMMIT=true`. Keep `N8N_REMINDER_AUTHORIZATION_ENABLED=false`. Run the two new migrations (`2026_10_25_000100`, `_000200`) on production.
- **HMAC rotation:** create a new n8n Crypto credential "CIMS Reminder HMAC (production)", re-point the 3 HMAC nodes, set the same value in Laravel `.env`. During the switch, events get 401 and are retried by backoff; nothing is lost. Never reuse the staging secret.
- **OAuth longevity:** the BRBI OAuth consent screen must be **Internal** or **In production** (External/Testing tokens expire after 7 days). Scope `gmail.send` only. Consumer Gmail send limit is about 500/day.
- **Synthetic cleanup (approval):** neutralise `920001` **before** B is activated. Least invasive: one signed `schedule.cancelled` v4 (`deleted`) through Workflow A (no deletion; evidence kept). Optionally delete all 92000x rows, receipts and the delivery log afterwards.
- **Retention:** A and B are `none` for production modes (verify after activation). Old executions #34–#45 age out around 2026-11-08/09.

## 16. Ingress without Caddy changes (operator decision 2026-10-26: no Caddy, firewall or new VM/n8n)

Read-only findings:
- Public listeners: only Caddy on 80/443 (plus `otelopscol`:20201, `fluent-bit`:20202 and
  ZeroTier:9993, none of them HTTP ingress, and the firewall allows only 80/443/ICMP/IAP-22).
- Caddy routes: `pesowifi.duckdns.org` → PisoWiFi web `127.0.0.1:3000` and API `:4000`;
  `gw.pesowifi.duckdns.org` → the gateway socket. **No route reaches n8n** (`5678` appears 0
  times). `https://pesowifi.duckdns.org/webhook/brbi-cims/reminder-events` → **404** (PisoWiFi
  web). Reusing these hosts would require changing PisoWiFi code: not acceptable.
- n8n egress: container `--network=none`; proxy allowlist **`oauth2.googleapis.com:443`,
  `www.googleapis.com:443`** only.
- **Conclusion: a public HTTPS endpoint into n8n is not possible without changing Caddy (or adding
  a tunnel/listener).** A pull design avoids inbound entirely.

Recommended: **Google Cloud Storage relay (pull)**. Laravel writes each signed event (exact raw
body, `X-CIMS-*` values as object metadata) to a private bucket over HTTPS. n8n polls it through
the **already-allowlisted** `www.googleapis.com/storage/v1` JSON API (HTTP Request node +
Google service-account credential, token via `oauth2.googleapis.com`), feeds each object into the
existing verify → guard → record chain, then deletes it. HMAC, schema, 16 KiB limit, version guard
and receipts are unchanged. The ±300 s freshness must become a relay-age bound (e.g. object
created < 24 h), with the version guard plus receipts as replay protection. Costs: a new bucket,
two least-privilege service accounts (Laravel: objectCreator on that bucket only; n8n: viewer +
delete), new Laravel transport code, and an n8n polling workflow (or a trigger swap on A). All of
these need approval. Latency is the poll interval (e.g. 1 min). InfinityFree → `storage.googleapis.com:443`
is **UNVERIFIED** (provider docs allow outbound 443).

Alternatives: (b) outbound tunnel (Cloudflare Tunnel / Tailscale Funnel) gives public HTTPS
without Caddy, but adds a new daemon and a third party on the PisoWiFi VM. (c) Reconsider one
exact-path Caddy block (section 15): the least code, but excluded by the operator.
