# BRBI CIMS — Phase 2A-5 Laravel-controlled reminder authorization

Status: **IMPLEMENTED and VERIFIED locally; BLOCKED for production by InfinityFree inbound API
restrictions and by the absence of an approved n8n staging test.**

This proof of concept keeps Laravel/MariaDB authoritative. n8n Data Tables may contain duplicate,
stale, or out-of-order rows because no row is permission to send. Every future sending path must
obtain a successful one-time claim from Laravel immediately before Gmail.

No workflow was created or activated, no email was sent, no VM or InfinityFree service was
accessed, and no persistent database was migrated. PHPUnit used only the forced SQLite `:memory:`
database guarded by `tests/TestCase.php`.

## Architecture

```text
browser save
  -> Laravel/MariaDB schedule + transactional outbox (same transaction)
  -> signed outbound schedule/cancellation event
  -> n8n Data Tables (discovery cache; may be stale or duplicated)
  -> due-row candidate
  -> POST Laravel claim API over authenticated HTTPS
       -> lock authoritative schedule-state row
       -> verify current activity/target, version, occurrence and creator
       -> insert unique durable claim in the same transaction
  -> only HTTP 201 "authorized" may enter a future Gmail branch
```

The claim gate removes Data Tables from the concurrency trust boundary. It does **not** make Gmail
delivery exactly once.

## Laravel authorization protocol

Endpoint (disabled by default):

```text
POST /api/integrations/n8n/reminder-authorizations/claim
Authorization: Bearer <dedicated 32+ character secret>
Content-Type: application/json
```

The secret belongs in an n8n Header Auth credential and the Laravel environment. It must never be
embedded in workflow JSON. Bearer authentication was selected for this n8n-to-Laravel call because
the audited n8n instance restricts Code-node crypto and environment-secret access. This is a new
authorization protocol; it does not weaken or replace the exact-raw-body HMAC contract used for
Laravel-to-n8n outbox events.

Exact request fields (additional fields are rejected):

```json
{
  "event_id": "outbox event UUID",
  "subject_type": "ci_activity | bank_target | asset_target",
  "subject_id": 123,
  "ci_activity_id": 123,
  "schedule_version": 4,
  "occurrence_at": "2026-11-03T01:00:00Z"
}
```

On success Laravel returns HTTP 201 with `result=authorized`, a random `claim_id`, a stable
`occurrence_key`, and the **current original Activity Creator** user ID and email. n8n must discard
any recipient stored in Data Tables and use only this response. `assigned_ci_id` is never read by
the authorizer.

Before claiming, Laravel independently verifies:

- the feature is enabled and the constant-time bearer-token comparison succeeds;
- JSON is at most 16 KiB, has the exact field set, and satisfies strict types/formats;
- the schedule-state subject exists, is active, and matches the supplied parent and version;
- the immutable outbox event exists and matches subject, version, parent, event type, and trigger;
- the current activity/target still exists, is Scheduled, is not completed, and still has a date;
- the occurrence equals Laravel's recomputed trigger (date-only means 08:00 Asia/Manila; a selected
  time means that exact instant) and is due;
- the current parent `creator_id` resolves to an active user with a valid email and matches the
  authoritative schedule state.

Representative denials are `unknown_subject`, `activity_mismatch`, `outdated_schedule_version`,
`schedule_cancelled`, `activity_completed`, `reminder_not_due`, `invalid_recipient`,
`unknown_event`, `conflicting_event_identity`, and `duplicate_delivery_claim`. Unauthenticated
requests receive only a generic 401 response; a disabled endpoint is a 404.

## Atomic claim and idempotency design

`reminder_delivery_claims` is an application table, not an n8n internal table. The authorizer uses
a Laravel database transaction and `SELECT ... FOR UPDATE` on the one authoritative
`reminder_schedule_states` row. The transaction rechecks current domain rows and inserts the claim.

Database uniqueness, rather than a prior read, is the final race arbiter:

- `event_uuid` is unique;
- `occurrence_key` is unique;
- `(subject_type, subject_id, schedule_version)` is unique;
- `claim_uuid` is unique.

The stable occurrence key is SHA-256 over the protocol label, subject type/id, authoritative
schedule version, and authoritative UTC occurrence. A unique-key collision is translated to
`duplicate_delivery_claim` and never authorizes a second sender. MariaDB/InnoDB supplies the
intended transaction and row-lock semantics. The local SQLite test proves the unique-constraint
fallback and application decisions, but a concurrent MariaDB staging test remains required.

Only an email hash and creator user ID are stored in the claim; the plaintext address is returned
only in the successful response. No borrower name, institution, financial information, raw
webhook body, bearer token, or assigned-CI identity is stored.

### Claim is not delivery

These guarantees are deliberately different:

| Concern | Guarantee |
|---|---|
| Duplicate claims | Prevented atomically by row serialization plus database unique constraints. |
| Duplicate Gmail delivery | Not fully preventable: Gmail and MariaDB do not share a transaction. |
| Known failure before Gmail submission | Safe to investigate, but this POC does not automatically release the claim. |
| Claim acquired and worker crashes before send | Claim remains blocked; manual reconciliation is required. |
| Gmail accepted message but acknowledgement was lost | Claim remains blocked; never auto-retry because delivery is uncertain. |
| Retry after uncertain result | Forbidden until an operator verifies provider state and a separately approved recovery action exists. |

Claims have no automatic expiry. An expiring pre-send lease could authorize a duplicate after a
slow or partitioned sender. Phase 2B must design an explicit acknowledgement/reconciliation
protocol and operator controls. Exactly-once email is not claimed.

## Data Tables responsibilities

Data Tables remain suitable only for:

- schedule reference metadata and due-candidate discovery;
- non-authoritative processing status;
- non-authoritative delivery/audit hints.

They do not decide the current version, cancellation precedence, recipient, or permission to send.
The future workflow must put the claim request directly before Gmail and make the Gmail node
reachable only from the HTTP 201 authorized branch. A duplicate Data Table row produces one 201
and then 409 denials. A stale version, cancelled schedule, completed activity, changed occurrence,
or invalid creator produces no authorization. This makes Data Table races harmless only while
there is no alternate path around the claim gate.

New schedules, reschedules, and cancellations continue to reach n8n through the existing signed
transactional outbox. A missed cancellation may leave a stale Data Table row, but the Laravel gate
denies it. A failed outbound event remains pending in MariaDB and is retried only when
`n8n-reminders:deliver` runs.

## Failure and retry policy

1. **Outbox delivery failed:** retain the pending event and use the existing bounded backoff when
   an approved worker/command invocation occurs. Never derive permission from the Data Table row.
2. **Authorization denied:** do not send. Mark only non-sensitive denial metadata in Data Tables;
   refresh or retire the stale candidate.
3. **Claim granted, Gmail definitely not invoked:** keep the claim blocked in this POC. Phase 2B
   needs an authenticated, audited operator recovery flow before retry is allowed.
4. **Gmail outcome uncertain or acknowledgement lost:** never retry automatically. Reconcile with
   the provider first; otherwise a duplicate is possible.
5. **Acknowledged delivery:** a future Phase 2B endpoint may record the provider message ID and
   outcome, but that is not implemented here and cannot retroactively make delivery exactly once.

Successful and failed authorization execution data should not retain request/response bodies in
n8n. Proxy/application logs must exclude the Authorization header and recipient response. Data
Table/project access and the Header Auth credential must be restricted to the integration project.

## InfinityFree compatibility

**BLOCKED / production reachability UNVERIFIED.** No automated production request was made.

InfinityFree documents that free-hosted sites use a mandatory browser JavaScript/cookie security
system that blocks REST APIs, external webhooks, command-line clients, and other automated inbound
requests. That directly conflicts with n8n calling this Laravel endpoint. InfinityFree also states
that free hosting has no cron jobs and external cron substitutes are prohibited. Its outbound
connection documentation permits HTTPS on port 443 in general, so browser-triggered Laravel may
attempt the existing outbound n8n webhook if a public HTTPS route later exists, but that does not
make the reverse n8n-to-Laravel authorization call viable.

Provider references:

- [Why API access does not work on InfinityFree free hosting](https://forum.infinityfree.com/t/why-isnt-api-access-working-on-my-website/115198/1)
- [Cron Job feature disabled and automated periodic requests prohibited](https://forum.infinityfree.com/t/cron-job-feature-is-now-disabled/80495/)
- [Outbound sockets/cURL/API port restrictions](https://forum.infinityfree.com/t/can-i-connect-to-other-servers-from-my-website-sockets-curl-and-apis/49348)

The existing optional after-commit dispatch can attempt only the event created by the current
browser save. If that request fails, or events are already pending while the site is idle, no
approved cron/worker exists to wake the dispatcher. Browser traffic does not provide a bounded
retry guarantee. Reschedules/cancellations therefore may not reach n8n promptly even though the
Laravel authorization gate would safely deny their stale rows.

## Local verification matrix

`tests/Feature/Integrations/N8nReminderAuthorizationTest.php` covers the requested cases. Existing
outbox tests provide the schedule/target and HMAC regression coverage.

| Scenario | Local result |
|---|---|
| Valid due reminder | VERIFIED: HTTP 201 and only current creator returned. |
| Duplicate claim | VERIFIED: HTTP 409, one claim row. |
| Simultaneous/competing claims | PARTIAL: unique-constraint loser proven on SQLite; real concurrent MariaDB staging load remains unverified. |
| Outdated version | VERIFIED: denied. |
| Rescheduled reminder | VERIFIED: old version denied; new future occurrence denied until due. |
| Cancelled reminder | VERIFIED: denied. |
| Completed activity | VERIFIED against independently read domain row, even with deliberately stale active state. |
| Invalid authentication | VERIFIED: generic 401 and no claim. |
| Missing creator email | VERIFIED: denied. |
| Multiple Data Table rows | VERIFIED by three equivalent claim attempts: 201, 409, 409. This is a local behavior model, not an n8n integration test. |
| Claim acquired, Gmail not sent | VERIFIED fail-closed: remains claimed; second authorization denied. |
| Gmail sent, acknowledgement lost | VERIFIED fail-closed model: second authorization denied; no real Gmail call occurred. |
| Failed webhook delivery | VERIFIED by existing outbox fake tests; no external request occurred. |
| Pending outbox while idle | VERIFIED locally: advancing time alone leaves the event pending; it does not run a worker. |

Commands:

```powershell
php artisan test tests/Feature/Integrations/N8nReminderAuthorizationTest.php tests/Feature/Integrations/N8nReminderOutboxTest.php
node --test scripts/n8n-phase2a/contract-validator.test.cjs
node --test scripts/n8n-phase2a/data-table-compatibility.test.cjs
```

## Required future approvals and production blockers

1. Move the Laravel API to hosting that permits authenticated machine-to-machine HTTPS, or obtain
   an InfinityFree plan/configuration whose documented restrictions allow it. Do not bypass the
   browser challenge.
2. Approve a reliable outbox runner. InfinityFree Free cannot supply the required cron/worker, so
   pending events while idle have no bounded delivery time.
3. Run schema review and the new migration against an approved non-production MariaDB staging
   database, then perform truly concurrent claim tests. No persistent database was changed here.
4. Configure a public authenticated HTTPS n8n webhook route and validate the existing raw-body
   HMAC contract on the actual instance. The audited VM currently has no public n8n route.
5. Build an inactive staging workflow whose only Gmail path is gated by HTTP 201 from Laravel;
   test stale/duplicate Data Table rows and all worker/restart interleavings.
6. Approve Phase 2B Gmail credentials, acknowledgement semantics, execution-data retention,
   reconciliation tooling, and operator recovery policy.

Phase 2B readiness is therefore **BLOCKED**. The Laravel authority/claim pattern is feasible and
locally implemented, but the current free-hosting topology cannot accept the required n8n API call
and cannot provide reliable idle outbox dispatch.
