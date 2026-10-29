# BRBI CIMS Phase 2A-3 — n8n Data Tables integration assessment

Status: **BLOCKED — design and local verification completed; ingestion workflow not created,
imported, published, or activated.**

Phase 2A-3 approves n8n Data Tables as the intended receiver reminder store and does not authorize
PostgreSQL, MySQL 8, another MariaDB database, or direct n8n access to the BRBI CIMS application
database. The existing MySQL/PostgreSQL schemas in `../phase-2a/` are retained only as superseded
reference alternatives.

## Decision summary

The HMAC portion is technically feasible in n8n 2.43.8, subject to a staging proof on the actual
VM. The Data Tables portion cannot currently provide the required monotonic-version and identity
guarantees. No workflow JSON is supplied because an importable workflow would invite ingestion
before the mandatory concurrency gate has passed.

| Area | Status | Finding |
|---|---|---|
| Laravel transactional outbox and event contract | VERIFIED locally | Existing implementation and 27 Laravel tests pass. |
| Exact-byte HMAC reference validator | VERIFIED locally | Existing Node validator and 15 tests pass. |
| n8n Webhook raw-body capability | PARTIAL | The Webhook node has Raw Body mode and emits binary data; the Code node helper returns a `Buffer`. This still needs a byte-for-byte test on the target 2.43.8 VM. |
| Node `crypto` access | PARTIAL | Self-hosted n8n supports `NODE_FUNCTION_ALLOW_BUILTIN=crypto`; with task runners it must be set on the runner. Target configuration is unverified. |
| Secret access | PARTIAL | A self-hosted Code node may read environment variables only when `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`. The dedicated secret must be injected outside the workflow JSON. Target configuration and editor access controls are unverified. |
| Data Table schema | IMPLEMENTED as design only | Three logical schemas use only `string`, `number`, `boolean`, and `date`. No tables were provisioned. |
| Atomic version/replay protection | BLOCKED | Data Tables expose filter-based CRUD/upsert, not a unique-key declaration or atomic compare-and-set/version update contract. |
| Workflow JSON | BLOCKED by required gate | Intentionally absent. |
| Email delivery | OUT OF SCOPE | No Gmail node, delivery workflow, or live email exists. |

## Evidence and compatibility limits

Official n8n documentation and source for the reviewed version line establish the following:

- Data Tables support row insert, get, update, delete, existence checks, and upsert. Upsert means
  “update matching rows, otherwise insert”; it does not declare a database unique key.
- Supported user column types are exactly `string`, `number`, `boolean`, and `date`. n8n supplies
  the system columns `id`, `createdAt`, and `updatedAt`.
- Data Tables are light-to-moderate storage with a default instance-wide 200 MiB limit. They are
  project-scoped and directly visible to project members.
- The documented read path is not a stable snapshot while rows are changing: a paged read may
  miss or duplicate records.
- Direct programmatic Data Table access from a Code node is unsupported, so custom transaction or
  compare-and-set logic cannot be wrapped around the Data Table node.
- Regular-mode production concurrency is unlimited by default. A global
  `N8N_CONCURRENCY_PRODUCTION_LIMIT` can queue production trigger/webhook executions, but does not
  apply to manual, sub-workflow, error, or CLI executions. In queue mode, workers execute jobs and
  concurrency is worker-oriented; it is not a per-table single-writer lock.
- On restart, n8n resumes queued executions, but restart recovery does not make a multi-node
  Get → compare → Upsert sequence atomic. A retry after an uncertain write also needs durable
  uniqueness to distinguish a replay from a new event.

Primary references:

- [Data Tables overview and limitations](https://docs.n8n.io/build/work-with-data/data-tables/)
- [Data Table row operations](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.datatable/rows/)
- [Data Table type definitions](https://github.com/n8n-io/n8n/blob/master/packages/workflow/src/data-table.types.ts)
- [Self-hosted concurrency control](https://docs.n8n.io/deploy/host-n8n/configure-n8n/scaling/control-concurrency/)
- [Queue mode](https://docs.n8n.io/deploy/host-n8n/configure-n8n/scaling/enable-queue-mode/)
- [Webhook node Raw Body option](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook/)
- [Code node binary `Buffer` helper](https://docs.n8n.io/build/code-in-n8n/cookbook/code-node/get-the-binary-data-buffer/)
- [Allowing Node built-ins in Code nodes](https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/configuration-examples/enable-modules-in-code-node/)
- [Environment-variable access security](https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/use-environment-variables/security/)
- [Execution data retention](https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/use-environment-variables/executions/)

## Why the version guarantee is blocked

The proposed state write needs one indivisible operation equivalent to:

```text
if event_id and idempotency_key are unused
and incoming.schedule_version > stored.schedule_version:
    register both identities and replace state
else:
    classify duplicate, conflict, or stale without changing state
```

A Data Table workflow cannot express that as one atomic operation. These interleavings remain:

1. Two executions both Get no matching `event_id`, then both Insert it. Duplicate rows result.
2. Version 2 and version 3 both read stored version 1 and pass an IF check. Version 3 writes first;
   delayed version 2 then upserts the same subject and overwrites it.
3. Adding `schedule_version < incoming` to the upsert filter does not solve the problem. If a newer
   subject row exists, it does not match, so upsert may insert a second subject row instead.
4. An append-only ledger avoids physical overwrite, but duplicate/conflicting identities can still
   produce more than one authoritative candidate for the same subject/version. It also moves the
   unresolved race into reminder selection and delivery.
5. Setting global production concurrency to one narrows the race but is not the requested durable
   single-writer guarantee: excluded execution modes and direct/manual table edits remain, and a
   multi-worker setup does not supply a per-table lock.

`scripts/n8n-phase2a/data-table-compatibility.test.cjs` is a local counterexample model for cases 1,
2, and 4. It is not an n8n integration test and does not claim to reproduce n8n internals.

### Alternatives evaluated in the required order

**A. Serialized ingestion:** BLOCKED. The documented global production limit is broader than this
workflow and excludes several execution modes. It is not a durable, per-workflow/per-table mutex
with a fencing token across restarts, retries, and multiple workers.

**B. Existing n8n durable storage:** BLOCKED. Data Tables have no exposed conditional compare-and-
set or unique domain constraint. Workflow static data is not an atomic concurrency primitive, and
direct Data Table access from Code is unsupported. The internal n8n database must not be queried
directly as an undocumented workaround.

**C. Compatibility decision:** REQUIRED. Obtain separate approval for one of:

1. a future n8n/Data Table feature with documented atomic unique and conditional update semantics;
2. a separately approved durable transactional receiver store; or
3. a separately approved, audited single-writer gateway with durable fencing and recovery.

No database server or gateway is approved by this document.

## Logical Data Table design

The machine-readable design is in `data-tables.schema.json`. It deliberately does not assume
constraints or provision tables.

### `cims_reminder_schedules`

One intended logical current-state row per `subject_key`. IDs are numeric because Laravel domain
IDs are positive integers; identity keys and enum-like values remain strings. UTC instants use
`date`; explicit Manila representations use `string` to preserve `+08:00`.

Fields: `subject_key`, `subject_type`, `subject_id`, `ci_activity_id`, `schedule_version`,
`recipient_user_id`, `recipient_email`, `scheduled_at_utc`, `scheduled_at_manila`,
`trigger_at_utc`, `trigger_at_manila`, `has_specific_time`, `status`, `cancel_reason`,
`last_event_id`, and `updated_at`.

### `cims_reminder_events`

Metadata-only replay/idempotency ledger. It stores no raw body, signature, secret, borrower name,
or financial information.

Fields: `event_id`, `idempotency_key`, `subject_key`, `schedule_version`, `event_type`,
`payload_fingerprint`, `processing_status`, `failure_code`, and `received_at`.

### `cims_reminder_delivery_logs`

Reserved for Phase 2B. The schema records metadata for future delivery auditing, but Phase 2A-3
must not create a Gmail node, send mail, or write delivery rows.

Fields: `delivery_key`, `subject_key`, `schedule_version`, `event_id`, `recipient_user_id`,
`attempt_number`, `outcome`, `provider_message_id`, `response_status`, `error_code`, and
`attempted_at`.

## Preserved webhook contract and HMAC gate

The receiver must continue to require HTTPS `POST` with `X-CIMS-Signature`, `X-CIMS-Timestamp`,
`X-CIMS-Event-Id`, and `Idempotency-Key`. It must reject bodies over 16 KiB, timestamps outside
±300 seconds, malformed/extra payload fields, and non-creator recipient shapes.

The canonical bytes are:

```text
unix_timestamp + "." + exact_raw_request_body
```

The n8n Webhook node must have **Raw Body** enabled. The validator must obtain the bytes through
`this.helpers.getBinaryDataBuffer(0, 'data')`, use Node `crypto.createHmac('sha256', secret)`, and
compare equal-length buffers with `crypto.timingSafeEqual`. Parsed/re-serialized JSON is forbidden.
Set only `NODE_FUNCTION_ALLOW_BUILTIN=crypto` (on task runners when used). Inject a dedicated
32-byte-or-longer secret outside the workflow JSON and verify that the actual deployment permits
the Code node to read it. Never use Header Authentication as a substitute.

These are capability findings, not target-VM verification. The exact 2.43.8 VM, proxy, task runner,
and secret configuration were not accessed or changed.

## Local test matrix

All tests are local reference or Laravel tests. None is an actual n8n webhook/Data Table test.

| # | Scenario | Local evidence | Result |
|---:|---|---|---|
| 1 | Valid schedule event | Node A/B test; Laravel signed-delivery test | VERIFIED locally |
| 2 | Invalid HMAC | Node I test | VERIFIED locally |
| 3 | Expired timestamp | Node I test | VERIFIED locally |
| 4 | Malformed payload | Node raw/malformed/unexpected test | VERIFIED locally |
| 5 | Duplicate event ID | Node A/B and F tests | VERIFIED in reference store; BLOCKED in Data Tables |
| 6 | Duplicate idempotency key | Node G test | VERIFIED in reference store; BLOCKED in Data Tables |
| 7 | Version 3 before version 2 | Node C test | VERIFIED in reference store; BLOCKED in Data Tables |
| 8 | Cancellation version 4 | Node D/E test | VERIFIED in reference store; BLOCKED in Data Tables |
| 9 | Delayed v3 after cancellation | Node D/E test | VERIFIED in reference store; BLOCKED in Data Tables |
| 10 | Concurrent schedule events | Node H plus negative race model | Reference semantics VERIFIED; Data Tables BLOCKED |
| 11 | Missing recipient email | Laravel legacy creator/lost email test; Node invalid recipient test | VERIFIED locally |
| 12 | Date-only 08:00 Manila | Laravel and Node date-only tests | VERIFIED locally |
| 13 | Exact selected time | Laravel and Node specific-time tests | VERIFIED locally |
| 14 | Parent CI Activity | Laravel schedule/completion/delete tests | VERIFIED locally |
| 15 | Bank/Cooperative target | Laravel bank-target test | VERIFIED locally |
| 16 | Asset Check target | Laravel asset-target test | VERIFIED locally |
| 17 | No actual emails sent | Laravel HTTP fakes; no Gmail node/workflow | VERIFIED locally |
| 18 | No production requests | Node tests are in-memory; Laravel prevents stray HTTP | VERIFIED locally |

Commands:

```powershell
node --test scripts/n8n-phase2a/contract-validator.test.cjs
node --test scripts/n8n-phase2a/data-table-compatibility.test.cjs
php artisan test tests/Feature/Integrations/N8nReminderOutboxTest.php
```

## Exact manual n8n staging procedure (after blocker approval only)

These steps are a checklist, not authorization to change the current VM.

1. Create an isolated n8n project for synthetic staging data. Record n8n `2.43.8`, execution mode,
   worker count, task-runner mode, n8n database type, and reverse-proxy configuration.
2. Resolve the compatibility blocker through an approved option in section C. Do not continue on
   the assumption that Get → IF → Upsert is safe.
3. Create the three tables from `data-tables.schema.json`; do not add custom `id`, `createdAt`, or
   `updatedAt` columns. Keep the delivery table empty and unused.
4. Inject a dedicated synthetic signing secret outside workflow JSON. Permit only the Node built-in
   `crypto`; apply that setting to the task runner if present. Confirm Code-node environment access
   and restrict workflow/project editors accordingly.
5. Import the reviewed workflow JSON produced only after the blocker is resolved. Confirm it is
   unpublished/inactive and contains no Gmail node, credentials, pinned production payloads, or
   plaintext secret.
6. Configure Webhook: `POST`, Raw Body enabled, Respond using Respond to Webhook, and a staging-only
   path. Use the test URL/listen mode only; never publish the workflow.
7. Configure the workflow to save neither successful, failed, nor manual execution data. At the
   instance level set `EXECUTIONS_DATA_SAVE_ON_SUCCESS=none`, `EXECUTIONS_DATA_SAVE_ON_ERROR=none`,
   `EXECUTIONS_DATA_SAVE_MANUAL_EXECUTIONS=false`, and keep progress saving off, subject to operator
   review. Confirm the proxy does not log bodies or the four authentication headers.
8. Enforce authenticated TLS and a 16 KiB body limit at the reverse proxy and inside validation.
   n8n's default webhook limit is much larger, so the proxy limit must be explicit.
9. Send only synthetic requests to the test webhook. Prove exact-byte handling with whitespace and
   non-ASCII JSON, then alter one byte without re-signing and confirm rejection with no table row.
10. Execute all 18 matrix scenarios, including truly concurrent HTTP requests and a forced restart
    at each persistence boundary. Test with every configured worker and retry path.
11. For each case, inspect all three tables and verify the approved atomic mechanism: no duplicate
    identity, no older overwrite, cancellation precedence, and deterministic retry outcome.
12. Inspect execution records, application logs, proxy logs, browser history, and exported workflow
    JSON for raw bodies, emails, signatures, and secrets. Correct any exposure and repeat testing.
13. Leave the workflow unpublished/inactive, remove synthetic rows through a separately reviewed
    staging cleanup, and record evidence. Do not connect Laravel production.

## Phase 2B readiness

**BLOCKED.** Phase 2B may begin only after the Phase 2A-3 atomic storage compatibility decision is
approved and the complete staging procedure passes on the real n8n 2.43.8 topology. Even then,
Phase 2B needs separate approval for reminder scheduling, delivery idempotency, Gmail credentials,
and delivery-log writes. InfinityFree's ability to run the Laravel outbox delivery command remains
a separate transport concern.
