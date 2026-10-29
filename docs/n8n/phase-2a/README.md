# BRBI CIMS Phase 2A-2 — MySQL + Secure n8n Webhook Ingestion (superseded alternative)

> **Superseded:** Phase 2A-3 selects n8n Data Tables as the approved receiver storage. This
> MySQL 8 design is preserved as a reviewed alternative only. It is not approved for deployment,
> and Phase 2A-3 does not authorize creating a separate MySQL or MariaDB database. See
> [`../phase-2a-3/README.md`](../phase-2a-3/README.md).

Status: **implementation artifacts prepared; n8n workflow intentionally not created or activated**.

No MySQL/MariaDB client or server executable is discoverable on `PATH`. Laravel's configured
`mysql` PDO connection is reachable, but the server reports MariaDB 10.4.32 and is the application
database, not a dedicated receiver staging database. It was not modified and cannot prove MySQL 8
behavior.
There is also no accessible n8n executable, container, listener, configured endpoint, or detectable
n8n version. Raw-body representation, Code-node crypto access, MySQL-node parameter binding,
execution-data settings, and the MySQL routine's runtime/concurrency behavior therefore remain
unverified here.
Generating a workflow JSON against an unknown node schema would create a false sense of security.

Phase 2A must remain inactive until the compatibility gate below passes in a disposable staging n8n
instance. Never replace exact raw-body HMAC verification with a signature over re-serialized JSON.

## Verified Laravel request contract

Laravel sends an HTTPS `POST` with `Content-Type: application/json` and the exact JSON stored in its
outbox as the request body. Redirects are disabled. The request headers are:

| Header | Contract |
|---|---|
| `X-CIMS-Signature` | `sha256=` plus lowercase HMAC-SHA256 hex |
| `X-CIMS-Timestamp` | Unix epoch seconds |
| `X-CIMS-Event-Id` | Same UUID as body `event_id` |
| `Idempotency-Key` | Same value as body `idempotency_key` |

The signed bytes are exactly:

```text
{X-CIMS-Timestamp}.{unmodified raw request body bytes}
```

The default acceptance window is plus or minus 300 seconds. The secret is a dedicated value of at
least 32 bytes and is never transmitted.

Top-level payload fields are exactly `schema`, `event_id`, `idempotency_key`, `event_type`,
`occurred_at`, `reminder`, `recipient`, and `content`. The schema is
`brbi.cims.reminder.v1`. Event types are `schedule.created`, `schedule.changed`, and
`schedule.cancelled`.

`reminder` contains:

- `key`, `subject_type`, `subject_id`, `ci_activity_id`, and monotonic `version`;
- `scheduled_at` and `trigger_at` in UTC;
- `trigger_at_local` with `+08:00` offset and `timezone` equal to `Asia/Manila`;
- `has_specific_time`; and
- `cancel_reason` for cancellations.

For active schedules, `recipient` contains the original activity creator's `user_id` and `email`.
It never uses `assigned_ci_id` or the editor. `content` contains only a generic `subject_label` and
the activities URL. Cancellation payloads omit the email, set schedule/content fields to `null`,
and retain the prior creator user ID where available.

## Prepared implementation artifacts

- `scripts/n8n-phase2a/contract-validator.cjs` validates headers, timestamp tolerance, exact-byte
  HMAC, schema, strict key allowlists, field types, origin, and the 16 KiB body limit. It requires a
  `Buffer`; passing parsed JSON or a string is deliberately rejected.
- `scripts/n8n-phase2a/reference-store.cjs` is an executable reference model for deduplication,
  conflicts, monotonic versions, cancellation precedence, and serialized concurrent ingestion. It
  is test support, not production storage.
- `scripts/n8n-phase2a/contract-validator.test.cjs` covers the Phase 2A acceptance scenarios without
  network access or dependencies.
- `docs/n8n/phase-2a/mysql-schema.sql` provides MySQL 8.0.16+ InnoDB state, event identity, and
  metadata-only audit tables plus one transactional ingestion procedure. It is the Phase 2A-2
  receiver design. The older `postgresql-schema.sql` is retained as Phase 2A history only.
- `scripts/n8n-phase2a/mysql-ingestion.integration.php` is an opt-in integration suite for a
  dedicated database whose name ends in `_staging` or `_test`. It includes two concurrent calls and
  removes only its randomly namespaced synthetic rows.

## Required n8n compatibility gate

Record the exact n8n version and verify all items before creating/importing the workflow:

1. A Webhook node configured for `POST` can expose the untouched request bytes as a binary
   `Buffer`. Prove this using a Laravel-signed body containing whitespace and non-ASCII text. The
   signature must fail if n8n parses and re-serializes that body.
2. The Code node can use Node's `createHmac` and `timingSafeEqual` APIs under the deployment's module
   allowlist. If not, place a small audited signature-verification gateway in front of n8n; do not
   compare signatures with ordinary string equality.
3. The MySQL node supports bound parameters for every value and can execute
   `CALL ingest_reminder_event(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)` as one database
   operation. Prove that nullable values remain SQL `NULL`, not the strings `"null"` or `""`.
4. Workflow settings can disable saved successful, failed, and manual execution payloads. Confirm
   the n8n instance and its reverse proxy also avoid logging request headers/bodies.
5. The webhook has a hard request-body limit of 16 KiB at the proxy and workflow layer.

If item 1 or 2 fails, ingestion is blocked. Do not activate a JSON-body-based workaround.

## Staging workflow design

After the gate passes, build an inactive workflow with this order:

1. **Webhook (POST only)** — raw bytes enabled; response handled by a Respond to Webhook node.
2. **Authenticate and validate** — adapt the tested validator to the verified Code-node interface.
   Load `CIMS_N8N_SIGNING_SECRET`, `CIMS_ALLOWED_ACTIVITIES_ORIGIN`, the 300-second tolerance, and
   16 KiB limit from managed secrets/configuration. Emit only normalized fields and SHA-256 hash;
   remove raw body and signature before the next node.
3. **MySQL atomic ingest** — one parameterized `CALL` to `ingest_reminder_event`. Convert validated
   UTC timestamps to `YYYY-MM-DD HH:mm:ss.ffffff` before binding. The database credential receives
   `EXECUTE` on that procedure only and no direct table access.
4. **Outcome switch** — return a minimal response:
   - `200` for `applied`, `duplicate`, or `stale` (Laravel must stop retrying these);
   - `409` for identity/payload conflicts;
   - `400` for schema errors, `401` for authentication/timestamp errors, and `413` for oversized
     bodies.
5. **Respond to Webhook** — return only `{ "accepted": true, "outcome": "..." }`; never echo the
   payload, signature, secret, or recipient email.

Authentication and validation must occur before any persistence node. Invalid signatures and stale
timestamps must not create audit rows because even attacker-supplied identifiers are unauthenticated.

## MySQL compatibility and atomic storage behavior

The receiver schema requires MySQL 8.0.16 or newer because it relies on enforced `CHECK`
constraints. It uses `datetime(6)` for UTC instants (rather than MySQL `timestamp`, avoiding the
2038 range limit), binary ASCII collations for identities/hashes, and InnoDB for row/unique-key
locking. MySQL 8's `VALUES(column)` upsert form is deprecated in newer releases but remains
supported; it is used for compatibility across the MySQL 8 line and must be regression-tested when
the staging server version is known.

The stored procedure performs event registration and conditional state upsert in one transaction:

- `event_uuid` and `idempotency_key` are independently unique.
- The SHA-256 of the exact authenticated raw body detects conflicting reuse.
- Exact replays return `duplicate` without another state row.
- InnoDB unique-key checks serialize concurrent registration. A per-call registration token avoids
  relying on connection-specific affected-row behavior when classifying a duplicate.
- State changes use one `INSERT ... ON DUPLICATE KEY UPDATE`; every assignment is conditional on
  `incoming schedule_version > stored schedule_version`, and the version is assigned last because
  MySQL evaluates single-table update assignments left-to-right. There is no application-level
  check-then-update race.
- Older versions are recorded as `stale` but cannot change current state.
- Cancellation is stored as the newer inactive version, so delayed active events cannot reactivate it.
- Audit rows contain identifiers, version, hash, outcome, and timestamps only—not raw payloads,
  signatures, secrets, or email addresses.
- The procedure is `SQL SECURITY DEFINER`; create it as the staging schema owner and grant n8n only
  `EXECUTE` on that procedure. The schema must be a dedicated n8n-side database, not the Laravel
  application's banking schema. It may be on the same server, but it need not be.

## Safe local verification

```powershell
node --test scripts/n8n-phase2a/contract-validator.test.cjs
php artisan test tests/Feature/Integrations/N8nReminderOutboxTest.php
```

The Node tests use only built-in modules and make no HTTP requests. The Laravel suite prevents stray
HTTP and uses fake mail. The shared fixed HMAC vector detects canonical-string drift between PHP and
Node.

After installing `mysql-schema.sql` in an isolated staging/test database, run the real database
suite with a temporary test-owner credential (never the n8n runtime credential):

```powershell
$env:CIMS_N8N_TEST_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=cims_n8n_staging;charset=utf8mb4'
$env:CIMS_N8N_TEST_USER = '<temporary-test-owner>'
$env:CIMS_N8N_TEST_PASSWORD = '<set-out-of-band>'
php scripts/n8n-phase2a/mysql-ingestion.integration.php
```

The script refuses databases whose names do not end in `_staging` or `_test`, uses synthetic
addresses, performs no HTTP requests, and sends no email.

## Staging tests still required

Use only synthetic creator addresses and records. Run the PHP integration suite against the exact
target MySQL version, then repeat the end-to-end cases through an inactive staging workflow,
including two genuinely concurrent MySQL calls for one subject. Inspect the database after each case
and confirm execution data/logs contain no raw body, email, signature, or secret. No Gmail node may
exist in this workflow.

Before activation, also verify the MySQL account has no privileges beyond `EXECUTE` on the routine;
successful, failed, and manual n8n execution payload retention is disabled; reverse-proxy body/header
logging is disabled; the webhook body limit is 16 KiB; TLS certificate validation is enabled; and
the test workflow remains inactive after import/review.

InfinityFree remains a separate outbound-delivery blocker: it cannot reliably run Laravel's outbox
delivery command. A secure n8n receiver does not solve that transport limitation.
