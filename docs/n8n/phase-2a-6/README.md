# BRBI CIMS — Phase 2A-6: One-way webhook + n8n Data Tables

Status: **IMPLEMENTED and VERIFIED locally (Laravel tests + reference-model execution of the
workflow JSON). Real n8n execution is NOT verified. Actual ingestion is BLOCKED until a
separately approved public HTTPS route and staging test exist on the n8n VM.**

Nothing was deployed, imported, activated or sent. No Google Cloud VM, InfinityFree or n8n
instance was accessed. No persistent database was migrated. No email was sent.

## 1. Final architecture (one-way)

```text
browser save (InfinityFree, Laravel + MariaDB)
  -> schedule change + reminder_schedule_states + reminder_outbox_events   (ONE transaction)
  -> after commit (opt-in) or `php artisan n8n-reminders:deliver`
  -> signed HTTPS POST  (HMAC-SHA256 over "{timestamp}.{raw body}")
  -> n8n Workflow A "BRBI CIMS — Reminder Event Ingestion"
       verify HMAC (Crypto nodes + Crypto credential) -> strict schema -> replay check
       -> version-guarded Data Table write -> re-read -> ledger -> HTTP 200
  -> n8n Data Tables (cims_reminder_schedules / _events / _delivery_logs)
  -> n8n Workflow B "BRBI CIMS — Due Reminder Processing" (Schedule Trigger, every 5 min)
       due rows -> newest version per subject -> guarded claim -> [Phase 2B gate] -> Gmail
```

Laravel only ever initiates requests. n8n never calls Laravel. The Phase 2A-5 claim API
(`POST /api/integrations/n8n/reminder-authorizations/claim`) is **not used** by this
architecture. It stays implemented, disabled (`N8N_REMINDER_AUTHORIZATION_ENABLED=false`, so it
returns 404) and covered by its existing tests. Its migration and code were not removed.

Recipient: always the CI Activity's original creator (`creator_id`), resolved by Laravel when the
event is recorded. `assigned_ci_id` is never used. The existing Laravel in-app (database)
notifications are unchanged.

## 2. Laravel event delivery

Preserved unchanged: transactional outbox, `brbi.cims.reminder.v1` payload, per-subject
monotonic versions, `schedule.created/changed/cancelled`, cancellation on completion, unscheduling,
deletion, cascades and an unreachable creator, HMAC headers `X-CIMS-Signature`,
`X-CIMS-Timestamp`, `X-CIMS-Event-Id`, `Idempotency-Key`, no redirects, 2xx-only acknowledgement,
bounded exponential backoff, and superseded-version suppression.

Changed in Phase 2A-6 (`app/Services/Integrations/N8n/ReminderOutboxDispatcher.php`):

- **Per-subject in-flight serialization.** A worker locks the subject's
  `reminder_schedule_states` row and then the event row by primary key. That is the same order
  `ReminderScheduleOutbox` uses when it records a version, so a user save and a worker cannot
  deadlock. While another live claim exists for the same subject, that subject is skipped and no
  attempt is counted. A newer version is therefore not sent while an older request for that
  subject is still in flight. Other subjects are never blocked.
- **Failure logging** (`Log::warning`) carries only the event id, idempotency key, attempt,
  HTTP status, error class and outcome. It never includes the payload (recipient email), the
  webhook URL, the response body or the secret.

Ordering status: **PARTIAL — not guaranteed.** Serialization holds only while a claim is live
(5 minutes). If a response is lost (timeout), n8n may still be processing that request after
the claim expires, so a newer version can overtake it. Correctness therefore still rests on the
receiver's version guard (section 4), which is order-independent for existing rows.

## 3. HMAC verification without Code-node crypto or environment access

Audited constraints, respected as-is: Code-node `crypto` is restricted, Code-node environment
access is blocked, and Raw Body is available. Nothing in this design asks to relax them.

| Step | Node | Notes |
|---|---|---|
| Raw bytes | Webhook v2.1, `options.rawBody: true` | Emits the exact request bytes as binary `data`. |
| Text | Extract From File v1.1 `text`, UTF-8, **Strip BOM off** | Laravel's `json_encode` escapes all non-ASCII, so the body is ASCII and round-trips byte-exactly. Invalid UTF-8 can only make the HMAC fail (fail closed). |
| Pre-check | Code (plain JS) | 16 KiB max, the 4 headers present and single-valued, timestamp ±300 s, `sha256=<64 hex>` format. |
| HMAC | **Crypto v2 `hmac` SHA256 hex** | The secret comes from an n8n **Crypto credential** (`hmacSecret`), stored encrypted by n8n. It never appears in workflow JSON, Code nodes or environment variables. |
| Compare | 2 more Crypto `hmac` nodes + Code | "Double HMAC": HMAC both the expected and the supplied signature, then compare those digests. This is timing-safe without `crypto.timingSafeEqual`. |
| Fingerprint | Crypto `hash` SHA256 of the raw body | Used for replay/conflict detection. |

Verified against the pinned `n8n@2.43.8` sources: Crypto node v2 reads `credentials.hmacSecret`
for `hmac`, the Webhook raw-body output, and the Extract From File `text` operation. The workflow
also reproduces Laravel's cross-runtime fixture signature (test
`Laravel cross-runtime fixture …`).

Status: **IMPLEMENTED; VERIFIED in the reference model; real-instance UNVERIFIED.** Staging must
confirm that the Crypto node is not excluded (`NODES_EXCLUDE`), that the Code node is enabled
(plain JS only), and that the instance computes the same digest.

## 4. Workflow design

Artifacts (generated by `scripts/n8n-phase2a/phase-2a-6/build-workflows.cjs` from the reviewed
Code sources in `scripts/n8n-phase2a/phase-2a-6/code/`):

- `workflows/brbi-cims-reminder-event-ingestion.json`: Workflow A, 31 nodes
- `workflows/brbi-cims-due-reminder-processing.json`: Workflow B, 16 nodes

Both are `active: false`, have no credential ids, no pinned data, use the execution-data
settings below and timezone `Asia/Manila`, and use only node types/versions present in
`n8n@2.43.8`. Data Tables are referenced **by name** (`mode: name`), so no instance-specific IDs
are embedded.

### Workflow A — Reminder Event Ingestion

Webhook `POST /webhook/brbi-cims/reminder-events`, response via Respond to Webhook.

1. Pre-check → HMAC → double-HMAC compare → strict schema (exact keys, UUID, idempotency key
   = `{subject_key}:v{version}`, header/body identity match, date-only = 08:00 Manila,
   specific time = scheduled instant, creator email present and valid for active events,
   cancellation shape).
2. Ledger lookup by `event_id` OR `idempotency_key`:
   - same identity + same fingerprint + terminal status → **200 `duplicate`**, no writes;
   - same id or key with different bytes → **409 `event_identity_conflict`**;
   - same identity still `received` (a previous attempt died mid-way) → resume;
   - none → insert a `received` ledger row.
3. **Version-guarded write**: one Data Table `update` with
   `subject_key = K AND schedule_version < V` that sets the whole row. n8n executes this as a
   single `UPDATE … WHERE` statement, so it is a compare-and-set: an older version can never
   overwrite a newer one, whatever the arrival order.
4. Fresh re-read of all rows for `K`. The update's returned rows are not trusted, because on
   SQLite n8n selects them before the UPDATE runs.
   - no rows → insert, run the guarded write again (converges any concurrently inserted
     lower-version duplicate), then re-read;
   - newest stored version ≥ V → **settled**: `applied` if a row at V carries this event id,
     otherwise `stale`;
   - only older rows (a concurrent insert raced the write) → **503 `concurrent_write_retry`**.
     Laravel retries the same bytes and the guarded write converges.
5. Ledger updated to `applied`/`stale` → **200** `{result, event_id}`.

Responses: 200 (`applied`, `stale`, `duplicate`), 400 (unreadable/non-JSON body),
401 (headers, timestamp, signature), 409 (identity conflict), 413 (size), 422 (schema),
503 (any Data Table failure or a concurrent-write retry), 500 (unexpected workflow error, e.g. a
missing credential). Every Data Table node routes its error output to `Storage Failure` → 503.
`Respond Accepted` is reachable only from `Record Event Outcome`. A **stale** event is
acknowledged with 200 so Laravel stops retrying something that changes nothing.

### Workflow B — Due Reminder Processing

Schedule Trigger every 5 minutes:

1. Rows with `status = scheduled AND trigger_at_utc <= now` (≤ 200, oldest first) → distinct
   subjects (≤ 25 per run) → **all** rows of each subject.
2. Per subject, only the highest `schedule_version` counts. If it is cancelled, or any of its
   rows is `sent`/`uncertain`/`skipped`, nothing happens. Rows with an invalid recipient are
   never emailed.
3. **Guarded claim**: one `update` requiring the same subject, version, `status = scheduled`,
   the observed `delivery_status` and the observed `delivery_attempts`. It sets `sending`, a
   unique claim token and attempts + 1. Of two overlapping runs, at most one can match. The
   claim is confirmed by re-reading the rows.
4. **Phase 2B gate** `Live Sending Approved?` compares the constant `NOT_APPROVED` with
   `APPROVED_FOR_PHASE_2B`, so it is always false. The false branch releases the claim, leaving
   the row `pending` with its attempt count restored.
5. Gmail node (`disabled: true`, no credential, `retryOnFail: false`, error output enabled):
   - success output → `sent` only if the result is a real Gmail message resource (`id`,
     `threadId`, `labelIds` containing `SENT`). Anything else, including the pass-through item
     a disabled Gmail node emits, becomes `uncertain`;
   - error output: a definite 4xx (not 408) → `failed`, retried after 15 min × attempt, at most
     3 attempts in total. 5xx, 408, timeouts, network and unknown errors → `uncertain`, never
     retried automatically;
   - a `sending` claim with no outcome after 30 min → `uncertain`.
6. Every outcome is written to the schedule row (guarded by the claim token) and appended to
   `cims_reminder_delivery_logs`. Logs never contain the email address or message body.

Email content is generic: the activity label Laravel already restricts to non-identifying text,
the Manila date/time, and the activities URL. There is no client name, institution, remark or
financial data.

## 5. Data Tables configuration

Machine-readable: `data-tables.schema.json`. This is the Phase 2A-3 design plus content and
delivery columns on `cims_reminder_schedules`. Create the tables with exactly these names and
columns, and do not add `id`, `createdAt` or `updatedAt`, since n8n supplies those.

| Table | Columns |
|---|---|
| `cims_reminder_schedules` | `subject_key` s, `subject_type` s, `subject_id` n, `ci_activity_id` n, `schedule_version` n, `recipient_user_id` n, `recipient_email` s, `scheduled_at_utc` d, `scheduled_at_manila` s, `trigger_at_utc` d, `trigger_at_manila` s, `has_specific_time` b, `status` s, `cancel_reason` s, `last_event_id` s, `updated_at` d, **`subject_label` s, `activities_url` s, `delivery_status` s, `delivery_claim` s, `delivery_attempts` n, `delivery_next_attempt_at` d, `delivery_updated_at` d** |
| `cims_reminder_events` | `event_id` s, `idempotency_key` s, `subject_key` s, `schedule_version` n, `event_type` s, `payload_fingerprint` s, `processing_status` s, `failure_code` s, `received_at` d |
| `cims_reminder_delivery_logs` | `delivery_key` s, `subject_key` s, `schedule_version` n, `event_id` s, `recipient_user_id` n, `attempt_number` n, `outcome` s, `provider_message_id` s, `response_status` n, `error_code` s, `attempted_at` d |

(s = string, n = number, b = boolean, d = date.) `recipient_email` is cleared on cancellation.
Data Tables are visible to every member of the owning n8n project, so keep that project
restricted to integration operators.

## 6. Known concurrency limitations (do not over-read the tests)

Data Tables give **per-statement atomicity only**: no unique keys, no multi-statement
transactions, no row locks. What follows from that:

| Concern | Status |
|---|---|
| Older version overwriting a newer existing row | **Prevented** by the single-statement version guard (SQLite serializes writers; PostgreSQL re-checks the WHERE after the row lock). Requires n8n's internal DB to be SQLite or PostgreSQL. Confirm on staging. |
| Two first-time events for a new subject | **Possible duplicate rows.** Harmless to correctness: readers use the highest version only, later guarded writes update every lower row, and a residual lower-version duplicate is ignored. Rows are not auto-deleted. |
| Same event delivered concurrently | Possible duplicate ledger/schedule rows with identical content; one email (claim is per subject + version). |
| Two overlapping Workflow B runs | At most one claim succeeds (guarded update). |
| Reschedule/cancel arriving after a claim | The in-flight email for the old version may still go out. The outcome write misses (version changed) and the new version starts `pending`. |
| Completion not yet delivered to n8n | **n8n can email a reminder for an activity already completed in Laravel** if the cancellation event is still pending in the outbox (see section 8). There is no n8n→Laravel check in this architecture. |
| Ordered delivery from Laravel | Best effort only (section 2). Not guaranteed. |

Reference-model results (section 9) show these properties under random node-level interleavings
of the real workflow JSON with per-statement atomic storage. They do not prove n8n's engine,
its internal database or its retry behaviour.

## 7. Gmail retry and duplicate risk

- **Exactly-once delivery is not provided.** Gmail and Data Tables share no transaction.
- A crash after Gmail accepted the message but before the outcome write leaves `sending`, which
  becomes `uncertain` after 30 minutes. It is never auto-resent. An operator must check the
  Gmail **Sent** folder and then either leave it, or set the row back to `delivery_status = failed`
  with `delivery_next_attempt_at` cleared to allow one more controlled attempt.
- A definite 4xx is retried at most 3 times, which can repeat a quota (429) rejection. Gmail did
  not send the message in that case.
- Classifying an unknown error as `uncertain` errs towards a **missed** reminder rather than a
  duplicate. The in-app Laravel notification remains the primary reminder.
- n8n-level retries (`retryOnFail`) are off on the Gmail node so n8n cannot silently resend.

## 8. InfinityFree limitations

- No cron or worker exists, and external cron/pinger substitutes are prohibited. **None was
  added.** `n8n-reminders:deliver` can be run only manually or by a separately approved host.
- With `N8N_REMINDERS_DISPATCH_AFTER_COMMIT=true`, a user's save attempts delivery of only its
  own new event, immediately after commit. If that attempt fails, or if the site is idle, the
  event stays pending indefinitely. A missed **cancellation** then means n8n may still email
  (section 6). Delivering other pending events from user requests was deliberately not added;
  it would change an existing design decision about request latency and needs approval.
- Outbound HTTPS to the n8n host from InfinityFree is **UNVERIFIED**. Inbound API calls to
  InfinityFree are blocked by its browser challenge, which is why this design never calls
  Laravel from n8n.

## 9. Test evidence

Reference-model tests execute the committed JSON in `workflow-harness.cjs`: real Code-node
sources in an isolated VM context, real HMAC/SHA-256, an in-memory Data Table with per-statement
atomicity, and a mocked Gmail. **They are not n8n executions.** Laravel tests use SQLite
`:memory:` and faked HTTP.

```powershell
php artisan test tests/Feature/Integrations
node --test scripts/n8n-phase2a/contract-validator.test.cjs scripts/n8n-phase2a/data-table-compatibility.test.cjs scripts/n8n-phase2a/phase-2a-6/workflows.test.cjs
node scripts/n8n-phase2a/phase-2a-6/build-workflows.cjs --check
```

| Scenario | Evidence | Kind |
|---|---|---|
| New schedule | outbox `test_event_goes_to_the_activity_creator…`; model `new schedule…` | Laravel + model |
| Date-only 08:00 Manila | outbox `test_date_only_schedule_triggers_at_8am_manila`; model `date-only schedule…`, `a date-only payload…` | Laravel + model |
| Explicit time | outbox `test_specific_time_schedule…`; model `date-only … explicit time…` | Laravel + model |
| Rescheduling | outbox `test_reschedule_records_a_new_version…`; model `rescheduling replaces…` | Laravel + model |
| Cancellation | outbox `test_completion_and_unscheduling…`, delete/cascade tests; model `cancellation and completion…` | Laravel + model |
| Completion | outbox (as above); model `completion invalidates … after it was claimed` | Laravel + model |
| Out-of-order versions | outbox `test_versions_are_monotonic…`; model `out-of-order versions…`, `a cancellation that arrives before…` | Laravel + model |
| Duplicate event IDs | outbox unique-key assertions; model `duplicate event ids…` | Laravel + model |
| Duplicate Data Table rows | model `duplicate Data Table rows…` | model |
| Concurrent updates | ordered-delivery `test_a_newer_version_waits…`, `…different_subject`, `…expired_claim…`; model 300 ingestion + 200 processing random interleavings | Laravel + model |
| Missing creator email | outbox `test_legacy_creator_without_email…`; model `missing creator email…`, `an invalid stored recipient…` | Laravel + model |
| Invalid HMAC | model `invalid HMAC, tampering…`, `a missing Crypto credential fails closed…` | model |
| Failed webhook request | ordered-delivery `test_only_a_2xx_acknowledgement…`, `test_an_uncertain_timeout…`; model `storage failure at every Data Table step…` | Laravel + model |
| HTTP 200 acknowledgement | model static `HTTP 200 is reachable only after…` + every 200 case | model |
| Reminder already sent | model `reminder already sent…` | model |
| Gmail failure | model `definite Gmail failure (4xx)…` | model (mock) |
| Uncertain Gmail outcome | model `uncertain Gmail outcome…`, `a claim abandoned mid-send…`, `the disabled Gmail node's pass-through…` | model (mock) |
| Failure logs carry no payload | ordered-delivery `test_delivery_failures_are_logged_without…` | Laravel |
| Artifact safety | model static tests (inactive, no secrets/credentials/env, pinned node versions, Gmail disabled, drift check) | static |

Mutation check (run during development): removing the version guard, removing the claim's
delivery-state guard, short-circuiting the signature comparison, or treating older-only rows as
settled each makes the corresponding tests fail.

## 10. Staging verification procedure (requires separate approval; not performed)

1. Approve an isolated staging n8n project on the existing VM, or a separate staging instance,
   **without disturbing existing services**. Record the n8n version (2.43.8), the internal DB
   type (must be SQLite or PostgreSQL), execution mode, task-runner mode and `NODES_EXCLUDE`.
2. Approve and configure a **staging-only** public HTTPS route to `/webhook/brbi-cims/…` with
   TLS, a 16 KiB body limit, no body/header logging, and a rate limit.
3. Create the three Data Tables from `data-tables.schema.json` in that project.
4. Create a Crypto credential holding a dedicated synthetic secret of 32+ random characters.
   Share it with no other project or user, because anyone who can use it can forge signatures.
   Put the same value only in the staging Laravel `.env`.
5. Import both workflows. Confirm they are inactive. Assign the Crypto credential to the three
   `hmac` nodes, then set `ALLOWED_ACTIVITIES_ORIGIN` in `Verify Signature and Validate` to the
   staging CIMS origin. Leave Gmail disabled and without a credential.
6. Use the **test URL** only (do not activate). Send synthetic signed requests: one from
   `php artisan n8n-reminders:deliver` against staging Laravel, plus crafted cases. Expect 200
   for valid events; 401 for a one-byte tamper, wrong secret, or timestamp older than 300 s;
   422 for schema errors; 409 for a reused id/key with different bytes; 200 `duplicate` on replay.
7. Run the full scenario list (new, date-only, explicit time, reschedule, cancel, complete,
   out-of-order, duplicates) and inspect the Data Tables after each step.
8. Concurrency: fire 10–50 simultaneous requests (mixed versions, duplicates) at the test
   webhook. Afterwards, the newest version must be the highest delivered for each subject, and
   there must be no regression and no lower-version row selected by Workflow B.
9. Storage failure: temporarily point one Data Table node at a non-existent table name on a
   copy. Expect 503 and no ledger `applied`.
10. Run Workflow B manually with the gate closed. Expect claims to be released, no Gmail
    execution, and rows still `pending`.
11. Inspect execution history, proxy logs and exported JSON for emails, signatures and secrets.
12. Record evidence, then delete synthetic rows through a reviewed cleanup.

## 11. Production activation prerequisites (none met yet)

1. Approved public HTTPS route for the production webhook and a reverse-proxy body limit.
2. A successful staging run of section 10 on the real topology.
3. Phase 2B approval: Gmail OAuth credential (send scope only), flipping the gate to
   `APPROVED_FOR_PHASE_2B`, enabling the Gmail node, a sender identity, and the
   duplicate/missed-email risk acceptance in section 7.
4. An approved decision on Laravel outbox delivery on InfinityFree (section 8), including whether
   a missed cancellation is acceptable.
5. Production `.env`: `N8N_REMINDERS_ENABLED=true`, an https webhook URL, and a dedicated 32+
   char secret (never the staging one). Keep `N8N_REMINDER_AUTHORIZATION_ENABLED=false`.
6. Run `php artisan n8n-reminders:deliver --reconcile` once after enabling, so already-scheduled
   reminders are emitted.

**Not production-ready.** Phase 2B is blocked on items 1–4.
