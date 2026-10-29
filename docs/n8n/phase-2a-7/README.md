# BRBI CIMS — Phase 2A-7: Simplified workflows and verification gate

Status: **IMPLEMENTED and VERIFIED in the local reference model. NOT verified on n8n.**
Neither workflow may be called *verified working* until the real-n8n gate in section 7 passes.

Nothing was imported, published or activated. No Data Table or credential was created and no
email was sent. No Google Cloud VM, n8n instance or InfinityFree host was accessed, and no
Laravel code changed in this phase.

## 1. Result

| Workflow | Phase 2A-6 | Phase 2A-7 | File |
|---|---:|---:|---|
| A — BRBI CIMS — Schedule Receiver | 31 nodes | **16** | `workflows/brbi-cims-schedule-receiver.json` |
| B — BRBI CIMS — Email Reminder | 16 nodes | **13** | `workflows/brbi-cims-email-reminder.json` |

Backup: the Phase 2A-6 artifacts, generator, code and tests are unchanged in
`docs/n8n/phase-2a-6/` and `scripts/n8n-phase2a/phase-2a-6/`, and their 31 tests still pass.

Generator: `scripts/n8n-phase2a/phase-2a-7/build-workflows.cjs`. Code-node sources:
`scripts/n8n-phase2a/phase-2a-7/code/`.

## 2. What was simplified, and why it is safe

### Workflow A (31 → 16)

| Removed / merged | Why the behaviour is preserved |
|---|---|
| `Precheck Request` + `Precheck Passed?` merged into `Verify Signature and Validate` | The HMAC input is built with an expression (`timestamp + "." + raw_body`), using null-safe header access. Size, headers, timestamp and signature format are all still checked before acceptance. |
| `Fingerprint Body` (SHA-256) | It only detected "same id, different bytes". The body is HMAC-authenticated, so only Laravel can produce it, and Laravel enforces unique `event_uuid`/`idempotency_key` with immutable payloads. A different event at the same version still returns **409** (`version_identity_conflict`). |
| Ledger lookup + `Classify Identity` + 3 IFs + ledger insert/update + `Accepted Result` (8 nodes) | Replays are handled by the **version guard** itself: a write only matches `schedule_version < V`, so a replay changes nothing. The re-read classifies it as `duplicate` using `last_execution_id`. The events table is now one append-only receipt row, written after the decision. |
| `Converge Schedule Rows`, `Final Read Schedule Rows`, `Decide After Insert` | Readers always use only the highest version per subject. When no row at or above V exists, inserting V is always correct, even while a concurrent execution inserts another version. The 2A-6 "503 and retry" race path is no longer needed. |
| 3 IFs replaced by one Switch (`Route Outcome`: insert / done / reject) | Same routing, one node. |
| `Storage Failure` (Code) | Replaced by `Respond Storage Failure`, a fixed 503. |

**Kept:** the exact-raw-body HMAC through the Crypto credential, the double-HMAC comparison,
the strict schema, the version guard, the fresh re-read, 503 on any storage error, and 200 only
after persistence.

### Workflow B (16 → 13)

| Removed / merged | Why the behaviour is preserved |
|---|---|
| `Collect Subjects` | `Read Subject Rows` runs once per due row, and `Select Deliverable` de-duplicates. |
| `Release Claims` | The Phase 2B gate now sits **before** the claim. While closed, B only reads, so there is nothing to release. |
| `Interpret Gmail Result` + `Classify Gmail Error` merged into `Interpret Gmail Outcome` | Both Gmail outputs feed one per-item node. Error items carry `error`. |

**Kept:** newest-version-wins per subject, skipping cancelled/sent/uncertain/skipped, the
guarded claim and its re-read confirmation, bounded 4xx retries, uncertain-never-retried,
stale-claim → uncertain, and the append-only delivery log.

## 3. Workflow A — Schedule Receiver

```text
Webhook (POST brbi-cims/reminder-events, Raw Body, respond via node)
 → Read Raw Body (Extract From File: text, UTF-8, BOM kept)
 → HMAC Expected Signature  = HMAC(cred, timestamp + "." + raw_body)
 → Seal Expected Signature  = HMAC(cred, "sha256=" + expected)
 → Seal Supplied Signature  = HMAC(cred, X-CIMS-Signature)
 → Verify Signature and Validate (size ≤16 KiB, headers, ±300 s, sealed compare, strict schema)
 → Event Valid? ──no──▶ Respond Rejected (400/401/413/422)
 → Guarded Schedule Write (UPDATE … WHERE subject_key = K AND schedule_version < V)
 → Read Schedule Rows → Decide Outcome → Route Outcome
      insert ─▶ Insert Schedule Row ─┐
      done   ───────────────────────┴▶ Record Event ─▶ Respond Accepted (200 applied|duplicate|stale)
      reject ─▶ Respond Rejected (409)
 any Data Table error ─▶ Respond Storage Failure (503)
```

Stale and duplicate events get 200 so Laravel stops retrying deliveries that change nothing. Any
unexpected node error, for example a missing credential, ends the execution, and n8n answers
non-2xx (500).

## 4. Workflow B — Email Reminder

```text
Every 5 Minutes → Find Due Reminders (status = scheduled AND trigger_at_utc ≤ now, ≤ 50)
 → Read Subject Rows (all rows of each due subject) → Select Deliverable
 → Claim Action?
      record (stale "sending" claim > 30 min) ─▶ Record Delivery Outcome + Write Delivery Log
      claim ─▶ Live Sending Approved? (constant NOT_APPROVED ≠ APPROVED_FOR_PHASE_2B → stops)
               ─▶ Claim Reminder (guarded UPDATE) → Read Claimed Rows → Confirm Claims
               ─▶ Send Reminder Email (DISABLED, no credential)
               ─▶ Interpret Gmail Outcome ─▶ Record Delivery Outcome + Write Delivery Log
```

## 5. Business rules preserved

- Recipient: the original CI Activity Creator (`creator_id`), resolved by Laravel. The email in
  the validated event is the only one used. `assigned_ci_id` is never involved.
- Date-only reminders trigger at 08:00 Asia/Manila; an explicit time triggers at that instant.
  Laravel computes both and the receiver re-validates them.
- A rescheduled event supersedes older versions. Cancellation and completion (`cancelled` with a
  reason) invalidate pending reminders.
- Parent CI Activity, Bank/Cooperative target and Asset Check target are independent subjects
  (`ci_activity:N`, `bank_target:N`, `asset_target:N`), as in the existing Laravel contract.
- Payloads carry no borrower data: generic labels only (unchanged Laravel contract). Laravel's
  in-app notifications are unchanged.

## 6. n8n 2.43.8 compatibility (checked against the `n8n@2.43.8` source tag)

| Item | Source-verified | Still needs a real instance |
|---|---|---|
| Webhook v2.1 `options.rawBody` → binary `data` of the exact request bytes; `responseMode: responseNode` | Yes (`Webhook.node.ts`) | Byte-exactness through the reverse proxy |
| Extract From File v1.1 `text` operation, `encoding`, `stripBOM`, `keepSource` | Yes (`moveTo.operation.ts`) | — |
| Crypto v2 `hmac` reads `hmacSecret` from credential type `crypto`; `hash`/`hmac` over a string `value` | Yes (`CryptoV2.node.ts`, `Crypto.credentials.ts`) | Node not in `NODES_EXCLUDE`; same digest as Laravel |
| Data Table v1.1 `get`/`insert`/`update`, `matchType`, `filters.conditions[keyName, condition, keyValue]`, resource mapper `columns`, table by name | Yes (`DataTable` node) | Table-by-name resolution in the project |
| Update = one `UPDATE … WHERE` statement (the version guard and the claim depend on this) | Yes (`data-table-rows.repository.ts`) | Internal DB must be **SQLite or PostgreSQL** |
| Date filter `trigger_at_utc lte now` (the node converts ISO strings to `Date`; parameterized SQL) | Partly | **Must be observed returning due rows** |
| IF v2.2 / Switch v3.2 filter format (`typeValidation: strict`, filter `version: 2`) | Yes | — |
| Respond to Webhook v1.1 `respondWith: json`, `options.responseCode` (number or expression) | Yes | Status code actually returned |
| Code v2, `mode: runOnceForEachItem`, `$('Node').item`, `$execution.id` | Yes (parameters) | Task runner enabled; plain JS only (no `URL`, `Buffer` or modules used) |
| Schedule Trigger v1.2 `rule.interval[{field: minutes, minutesInterval: 5}]` | Yes | — |
| Gmail v2.1 `message`/`send`, `sendTo`, `subject`, `emailType`, `message`, `options.appendAttribution` | Yes | Not exercised: no credential, node disabled |

The signature comparison is secure as designed. Both sides are re-HMAC'd with the secret, so a
plain string compare leaks nothing usable. No Crypto feature in use is missing from 2.43.8.

## 7. Real-n8n verification gate (requires separate approval; NOT performed)

A workflow may be called **VERIFIED WORKING** only when every row below passes on the real
n8n 2.43.8 instance, using synthetic data, with the evidence recorded.

| # | Check | Pass evidence | Fail if |
|---|---|---|---|
| G1 | Import | Both JSON files import; both show **inactive**; 16 / 13 nodes; no "unknown node type" | Any import error or missing node |
| G2 | Credentials resolve | Crypto credential assigned to the 3 `hmac` nodes; no credential warnings except the disabled Gmail node | Any HMAC node unassigned or erroring |
| G3 | Data Tables | 3 tables exist with the exact columns from `data-tables.schema.json`; nodes resolve them by name | "Data table does not exist" / unknown column |
| G4 | Valid signature | `php artisan n8n-reminders:deliver` (staging Laravel) or a signed synthetic `curl` → **200** `applied`; row present | Any non-200 |
| G5 | Invalid signature | One-byte tamper, wrong secret, and a timestamp 301 s old → **401**; no new rows | 200, or any row written |
| G6 | Schema | Missing creator email → **422**; no rows | Accepted |
| G7 | Duplicate | Same request again → **200 `duplicate`**; schedule row unchanged (compare `updatedAt`) | Row modified |
| G8 | Reschedule | v2 → row now v2 with new `trigger_at_utc`; repeat to v4 → single current row v4 | Old time remains current |
| G9 | Out-of-order | Send v3 then v2 → v2 answers `stale`; row stays v3 | Row regressed |
| G10 | Cancellation / completion | Cancelled vN → `status=cancelled`, `recipient_email` empty | Still scheduled |
| G11 | Ack matches persistence | Make one table temporarily unavailable on a **copy** → **503**, never 200 | 200 without the row |
| G12 | Concurrency | 20–50 simultaneous mixed-version requests → newest version current for every subject | Any subject's newest row not the highest delivered version |
| G13 | Due detection | Row with `trigger_at_utc` 1 min in the past; run B manually → `Select Deliverable` shows it with the creator email and correct Manila time; future row absent | Missing due row or a future row selected (verifies the date filter) |
| G14 | Superseded / cancelled ignored | Due v1 plus cancelled v2 for the same subject → not selected | Selected |
| G15 | No sends | B with the committed gate: execution stops at `Live Sending Approved?`; Gmail never executes; Data Tables unchanged | Any Gmail execution or claim write |
| G16 | Hygiene | Execution list, proxy logs and an exported workflow contain no secret, signature, email or raw body | Any leak |

Not covered by the gate, because they need Phase 2B: the real Gmail success/error shapes and
the duplicate/uncertain-send behaviour against Gmail.

## 8. Staging import instructions (only after approval)

1. On the approved staging n8n project, record the version (2.43.8), internal DB type,
   execution mode, task-runner status and `NODES_EXCLUDE`.
2. Create the Data Tables `cims_reminder_schedules`, `cims_reminder_events` and
   `cims_reminder_delivery_logs` exactly as in `data-tables.schema.json`. Do not add
   `id`/`createdAt`/`updatedAt`.
3. Create one **Crypto** credential with a dedicated synthetic **Hmac Secret** (32+ random
   characters). Share it with no one else, because anyone who can use it can forge events. Put
   the same value only in the staging Laravel `.env` as `N8N_REMINDERS_SIGNING_SECRET`.
4. Import `brbi-cims-schedule-receiver.json` and `brbi-cims-email-reminder.json`. Confirm both
   are inactive. Assign the Crypto credential to `HMAC Expected Signature`,
   `Seal Expected Signature` and `Seal Supplied Signature`.
5. In `Verify Signature and Validate`, set `ALLOWED_ACTIVITIES_ORIGIN` to the staging CIMS
   origin. Leave `Send Reminder Email` disabled with no credential, and leave the gate unchanged.
6. Use the webhook **test URL** ("Listen for test event") only. Point staging Laravel's
   `N8N_REMINDERS_WEBHOOK_URL` at it (HTTPS, through the approved proxy route with a 16 KiB
   body limit).
7. Run G1–G16 and record the evidence. Then delete the synthetic rows. Do not activate.

## 9. Local test evidence (reference model — not n8n)

```powershell
php artisan test tests/Feature/Integrations                              # 48 passed (Laravel)
node --test scripts/n8n-phase2a/phase-2a-7/workflows.test.cjs            # 34 passed (model)
node --test scripts/n8n-phase2a/phase-2a-6/workflows.test.cjs            # 31 passed (backup)
node --test scripts/n8n-phase2a/contract-validator.test.cjs scripts/n8n-phase2a/data-table-compatibility.test.cjs  # 19 passed
node scripts/n8n-phase2a/phase-2a-7/build-workflows.cjs --check           # no drift
```

The 2A-7 tests execute the committed JSON node by node in `phase-2a-6/workflow-harness.cjs`.
They run the real Code-node sources, real HMAC, an in-memory Data Table with per-statement
atomicity, and a mocked Gmail. They cover all 20 required scenarios, including 400 concurrent
receiver interleavings, 200 overlapping reminder runs, and a simulated crash after **every**
node of both workflows.

Mutation check: removing the version guard, removing the claim's delivery-state guard,
bypassing the signature comparison, answering 200 on storage failure, ignoring superseded rows,
or retrying uncertain sends each makes the matching tests fail.

**What the reference model cannot show:** n8n's actual expression engine and parameter
coercion, task-runner globals, the real Data Table SQL (especially date comparison), proxy and
raw-body byte handling, webhook error responses, real restart/queue recovery, and Gmail
responses. Those are G1–G16.

## 10. Known limitations

- Exactly-once email is not possible. A crash after Gmail accepts a message but before the
  result is recorded becomes `uncertain` and is never auto-resent. An operator must check the
  Gmail Sent folder.
- Duplicate schedule rows can appear when first events for a subject arrive concurrently.
  Readers ignore lower versions, and the rows are never auto-deleted.
- **A completed or cancelled activity can still be emailed** if its cancellation event never
  left Laravel. InfinityFree has no cron, and outbound delivery relies on the opt-in
  after-commit attempt.
- The ordering of Laravel deliveries is best effort. The receiver's version guard is the real
  protection.

## 11. Remaining activation blockers and the next approval needed

1. **Approval to act on the existing Google Cloud VM / n8n instance** for staging only. That
   covers: creating a restricted staging project, the 3 Data Tables and the Crypto credential;
   importing both workflows inactive; a staging-only HTTPS route with a 16 KiB limit; and
   running G1–G16 with synthetic data and Gmail disabled. Existing services must not be
   disturbed.
2. Staging Laravel able to reach that route (outbound HTTPS from InfinityFree is unverified).
3. A decision on outbox delivery while the InfinityFree site is idle (no cron; external pingers
   prohibited).
4. Phase 2B approval: Gmail OAuth credential (send scope), enabling the Gmail node, opening the
   gate, and accepting the duplicate/missed-email risks.

Not production-ready.
