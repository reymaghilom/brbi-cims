'use strict';

// Phase 2A-6 REFERENCE-MODEL tests: the committed workflow JSON is executed by
// workflow-harness.cjs (not by n8n). Synthetic data only; no network, no email.
//
//   node --test scripts/n8n-phase2a/phase-2a-6/workflows.test.cjs

const assert = require('node:assert/strict');
const { createHmac, randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { DataTableStore, execute } = require('./workflow-harness.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const DIR = path.join(ROOT, 'docs/n8n/phase-2a-6');
const SCHEMA = JSON.parse(fs.readFileSync(path.join(DIR, 'data-tables.schema.json'), 'utf8'));
const INGESTION = JSON.parse(fs.readFileSync(path.join(DIR, 'workflows/brbi-cims-reminder-event-ingestion.json'), 'utf8'));
const PROCESSING = JSON.parse(fs.readFileSync(path.join(DIR, 'workflows/brbi-cims-due-reminder-processing.json'), 'utf8'));

const SECRET = 'phase-2a-6-synthetic-signing-secret-0123456789';
const ORIGIN = 'https://cims.example.test';
const T0 = Date.parse('2026-10-25T02:00:00Z');
const HOUR = 60 * 60 * 1000;

// ----------------------------------------------------------------- fixtures

function world(now = T0) {
    const state = { now, executions: 0 };
    state.store = new DataTableStore(SCHEMA, () => state.now);
    state.env = (extra = {}) => ({
        store: state.store,
        clock: () => state.now,
        credentials: { crypto: { hmacSecret: SECRET } },
        executionId: String(++state.executions),
        ...extra,
    });

    return state;
}

const manila = (iso) => `${new Date(Date.parse(iso) + 8 * HOUR).toISOString().slice(0, 19)}+08:00`;
const zulu = (ms) => new Date(ms).toISOString().replace('.000Z', 'Z');

/** A Laravel-shaped brbi.cims.reminder.v1 payload (same field order as ReminderScheduleOutbox). */
function event({ subjectType = 'ci_activity', subjectId = 42, version = 1, type, at = '2026-11-03T01:00:00Z', dateOnly = false, cancelReason = 'completed', email = 'creator@example.test', userId = 7, eventId = randomUUID() } = {}) {
    const eventType = type ?? (version === 1 ? 'schedule.created' : 'schedule.changed');
    const cancelled = eventType === 'schedule.cancelled';
    // Date-only: scheduled at local midnight, triggered at 08:00 Asia/Manila on that date.
    const scheduledAt = at;
    const triggerAt = dateOnly ? zulu(Date.parse(`${manila(at).slice(0, 10)}T08:00:00+08:00`)) : at;

    return {
        schema: 'brbi.cims.reminder.v1',
        event_id: eventId,
        idempotency_key: `${subjectType}:${subjectId}:v${version}`,
        event_type: eventType,
        occurred_at: '2026-10-25T01:59:00Z',
        reminder: {
            key: `${subjectType}:${subjectId}`,
            subject_type: subjectType,
            subject_id: subjectId,
            ci_activity_id: subjectId,
            version,
            scheduled_at: cancelled ? null : scheduledAt,
            has_specific_time: cancelled ? null : !dateOnly,
            trigger_at: cancelled ? null : triggerAt,
            trigger_at_local: cancelled ? null : manila(triggerAt),
            timezone: 'Asia/Manila',
            cancel_reason: cancelled ? cancelReason : null,
        },
        recipient: cancelled ? { user_id: userId } : { user_id: userId, email },
        content: cancelled ? null : { subject_label: 'Barangay Check', activities_url: `${ORIGIN}/client-folders/9/activities` },
    };
}

/** The webhook item n8n produces for a signed Laravel request with Raw Body enabled. */
function request(state, payload, { secret = SECRET, timestamp = Math.floor(state.now / 1000), rawBody, signature, headers = {} } = {}) {
    const raw = Buffer.from(rawBody ?? JSON.stringify(payload), 'utf8');
    const sig = signature ?? `sha256=${createHmac('sha256', secret).update(`${timestamp}.`).update(raw).digest('hex')}`;
    let body;
    try {
        body = JSON.parse(raw.toString('utf8'));
    } catch {
        body = {};
    }

    return [{
        json: {
            headers: {
                'content-type': 'application/json',
                'x-cims-signature': sig,
                'x-cims-timestamp': String(timestamp),
                'x-cims-event-id': payload ? payload.event_id : '',
                'idempotency-key': payload ? payload.idempotency_key : '',
                ...headers,
            },
            params: {},
            query: {},
            body,
        },
        binary: { data: { data: raw.toString('base64'), mimeType: 'application/json' } },
    }];
}

async function drain(generator) {
    for (;;) {
        const step = await generator.next();
        if (step.done) return step.value;
    }
}
const ingest = (state, payload, options, envExtra) => drain(execute(INGESTION, request(state, payload, options), state.env(envExtra)));

/** Laravel's dispatcher semantics: retry the SAME bytes until a 2xx (bounded). */
async function deliver(state, payload, attempts = 5) {
    let result;
    for (let i = 0; i < attempts; i++) {
        result = await ingest(state, payload);
        if (result.response.status >= 200 && result.response.status < 300) return result;
    }
    return result;
}

function sendingEnabledCopy(gmail) {
    // TEST-ONLY copy: flips the Phase 2B gate and enables a MOCK Gmail node. Never written to disk.
    const copy = structuredClone(PROCESSING);
    const gate = copy.nodes.find((node) => node.name === 'Live Sending Approved?');
    gate.parameters.conditions.conditions[0].leftValue = 'APPROVED_FOR_PHASE_2B';
    copy.nodes.find((node) => node.name === 'Send Reminder Email').disabled = false;

    return (state, extra = {}) => drain(execute(copy, [{ json: {} }], state.env({ gmail, ...extra })));
}
const okGmail = () => {
    let n = 0;
    return () => ({ json: { id: `msg-${++n}`, threadId: `thr-${n}`, labelIds: ['SENT'] } });
};
const runDefaultProcessing = (state) => drain(execute(PROCESSING, [{ json: {} }], state.env()));

const schedules = (state) => state.store.all('cims_reminder_schedules');
const ledger = (state) => state.store.all('cims_reminder_events');
const logs = (state) => state.store.all('cims_reminder_delivery_logs');
const newest = (state, key = 'ci_activity:42') => {
    const rows = schedules(state).filter((row) => row.subject_key === key);
    const version = Math.max(...rows.map((row) => row.schedule_version));
    return rows.filter((row) => row.schedule_version === version);
};

function rng(seed) {
    return () => {
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
async function interleave(generators, random) {
    const live = generators.map((generator) => ({ generator, done: false, value: undefined }));
    while (live.some((entry) => !entry.done)) {
        const choices = live.filter((entry) => !entry.done);
        const pick = choices[Math.floor(random() * choices.length)];
        const step = await pick.generator.next();
        if (step.done) {
            pick.done = true;
            pick.value = step.value;
        }
    }
    return live.map((entry) => entry.value);
}

// ----------------------------------------------------------------- artifact safety (static)

test('artifacts are inactive, carry no secret, no credential id, no pinned data, and save no execution data', () => {
    for (const workflow of [INGESTION, PROCESSING]) {
        const text = JSON.stringify(workflow);
        assert.equal(workflow.active, false);
        assert.deepEqual(workflow.pinData, {});
        assert.equal(workflow.settings.saveDataSuccessExecution, 'none');
        assert.equal(workflow.settings.saveDataErrorExecution, 'none');
        assert.equal(workflow.settings.saveManualExecutions, false);
        assert.ok(!text.includes(SECRET));
        assert.ok(!/\$env|process\.env|require\(|hmacSecret|N8N_REMINDERS_SIGNING_SECRET/.test(text), 'no env, require or secret reference');
        for (const node of workflow.nodes) {
            assert.equal(node.credentials, undefined, `${node.name} must not embed a credential reference`);
        }
    }
});

test('artifacts use only node types and versions present in the n8n@2.43.8 tag', () => {
    const allowed = {
        'n8n-nodes-base.webhook': [2.1], 'n8n-nodes-base.extractFromFile': [1.1], 'n8n-nodes-base.code': [2],
        'n8n-nodes-base.if': [2.2], 'n8n-nodes-base.crypto': [2], 'n8n-nodes-base.dataTable': [1.1],
        'n8n-nodes-base.respondToWebhook': [1.1], 'n8n-nodes-base.scheduleTrigger': [1.2], 'n8n-nodes-base.gmail': [2.1],
    };
    for (const workflow of [INGESTION, PROCESSING]) {
        for (const node of workflow.nodes) {
            assert.ok(allowed[node.type] && allowed[node.type].includes(node.typeVersion), `${node.name}: ${node.type}@${node.typeVersion}`);
        }
        for (const [from, { main }] of Object.entries(workflow.connections)) {
            assert.ok(workflow.nodes.some((node) => node.name === from));
            for (const target of main.flat()) assert.ok(workflow.nodes.some((node) => node.name === target.node));
        }
    }
});

test('the Gmail node is disabled, credential-less, never retried by n8n, and behind a closed gate', () => {
    const gmail = PROCESSING.nodes.find((node) => node.type === 'n8n-nodes-base.gmail');
    assert.equal(gmail.disabled, true);
    assert.equal(gmail.retryOnFail, false);
    assert.equal(gmail.onError, 'continueErrorOutput');
    const gate = PROCESSING.nodes.find((node) => node.name === 'Live Sending Approved?');
    assert.notEqual(gate.parameters.conditions.conditions[0].leftValue, gate.parameters.conditions.conditions[0].rightValue);
    assert.deepEqual(PROCESSING.connections['Live Sending Approved?'].main[0], [{ node: 'Send Reminder Email', type: 'main', index: 0 }]);
    assert.equal(INGESTION.nodes.some((node) => node.type === 'n8n-nodes-base.gmail'), false);
});

test('HTTP 200 is reachable only after the schedule write, the re-read and the ledger update', () => {
    const predecessors = (name) => Object.entries(INGESTION.connections)
        .flatMap(([from, { main }]) => main.flatMap((targets, output) => targets.filter((target) => target.node === name).map(() => [from, output])));
    assert.deepEqual(predecessors('Respond Accepted'), [['Record Event Outcome', 0]]);
    assert.deepEqual(predecessors('Record Event Outcome'), [['Accepted Result', 0]]);
    assert.deepEqual(predecessors('Accepted Result'), [['Settled?', 0]]);
    assert.deepEqual(predecessors('Respond Duplicate'), [['Already Processed?', 0]]);
    // Every Data Table node routes its error output to Storage Failure -> 503.
    for (const node of INGESTION.nodes.filter((candidate) => candidate.type === 'n8n-nodes-base.dataTable')) {
        assert.equal(node.onError, 'continueErrorOutput');
        assert.deepEqual(INGESTION.connections[node.name].main[1], [{ node: 'Storage Failure', type: 'main', index: 0 }]);
    }
});

test('Code nodes use no require(), Node built-ins, or environment access', () => {
    for (const workflow of [INGESTION, PROCESSING]) {
        for (const node of workflow.nodes.filter((candidate) => candidate.type === 'n8n-nodes-base.code')) {
            assert.ok(!/require\s*\(|\$env|process\.|import\s|crypto\./.test(node.parameters.jsCode), node.name);
        }
    }
});

test('committed artifacts match the generator (no hand-edited drift)', () => {
    const { artifacts } = require('./build-workflows.cjs');
    assert.deepEqual(JSON.parse(JSON.stringify(artifacts['brbi-cims-reminder-event-ingestion.json'])), INGESTION);
    assert.deepEqual(JSON.parse(JSON.stringify(artifacts['brbi-cims-due-reminder-processing.json'])), PROCESSING);
});

// ----------------------------------------------------------------- Workflow A: ingestion

test('new schedule: HTTP 200 applied, one active row, ledger applied', async () => {
    const state = world();
    const result = await ingest(state, event({ version: 1 }));

    assert.equal(result.response.status, 200);
    assert.equal(result.response.body.result, 'applied');
    const [row] = schedules(state);
    assert.equal(schedules(state).length, 1);
    assert.equal(row.status, 'scheduled');
    assert.equal(row.delivery_status, 'pending');
    assert.equal(row.schedule_version, 1);
    assert.equal(row.recipient_email, 'creator@example.test');
    assert.equal(row.trigger_at_utc, '2026-11-03T01:00:00.000Z');
    assert.equal(row.trigger_at_manila, '2026-11-03T09:00:00+08:00');
    assert.deepEqual(ledger(state).map((entry) => entry.processing_status), ['applied']);
});

test('Laravel cross-runtime fixture: the Crypto-node HMAC accepts Laravel\'s exact signature', async () => {
    // Same fixture as N8nReminderOutboxTest::test_signature_contract_matches_the_phase_2a_cross_runtime_fixture.
    const state = world(1_798_500_000 * 1000);
    const raw = '{"schema":"brbi.cims.reminder.v1"}';
    const result = await drain(execute(INGESTION, request(state, null, { rawBody: raw, signature: 'sha256=1d1fe21a032e64c85dae0e94e2c06b89ef96b9f270e9844ac0cbaf1ef3f2ae39', timestamp: 1_798_500_000, headers: { 'x-cims-event-id': 'fixture', 'idempotency-key': 'fixture' } }), {
        ...state.env(), credentials: { crypto: { hmacSecret: 'phase-2a-test-signing-secret-0123456789abcdef' } },
    }));

    // The signature is accepted; only then does the strict schema reject the fixture body.
    assert.equal(result.response.status, 422);
    assert.equal(result.response.body.code, 'payload_unexpected_fields');
});

test('date-only schedule triggers at 08:00 Asia/Manila; explicit time keeps the selected time', async () => {
    const state = world();
    await ingest(state, event({ subjectId: 1, at: '2026-11-02T16:00:00Z', dateOnly: true }));
    await ingest(state, event({ subjectId: 2, at: '2026-11-03T06:45:00Z' }));

    const byKey = Object.fromEntries(schedules(state).map((row) => [row.subject_key, row]));
    assert.equal(byKey['ci_activity:1'].trigger_at_manila, '2026-11-03T08:00:00+08:00');
    assert.equal(byKey['ci_activity:1'].trigger_at_utc, '2026-11-03T00:00:00.000Z');
    assert.equal(byKey['ci_activity:1'].has_specific_time, false);
    assert.equal(byKey['ci_activity:2'].trigger_at_manila, '2026-11-03T14:45:00+08:00');
    assert.equal(byKey['ci_activity:2'].has_specific_time, true);

    const send = sendingEnabledCopy(okGmail());
    state.now = Date.parse('2026-11-02T23:59:00Z');
    assert.equal((await send(state)).sentEmails.length, 0, 'not before 08:00 Manila');
    state.now = Date.parse('2026-11-03T00:00:00Z');
    assert.deepEqual((await send(state)).sentEmails.map((mail) => mail.to), ['creator@example.test']);
    state.now = Date.parse('2026-11-03T06:44:59Z');
    assert.equal((await send(state)).sentEmails.length, 0, 'explicit time not yet due');
    state.now = Date.parse('2026-11-03T06:45:00Z');
    assert.equal((await send(state)).sentEmails.length, 1);
});

test('a date-only payload whose trigger is not 08:00 Manila is rejected (422)', async () => {
    const state = world();
    const bad = event({ at: '2026-11-02T16:00:00Z', dateOnly: true });
    bad.reminder.trigger_at = '2026-11-03T01:00:00Z';
    bad.reminder.trigger_at_local = '2026-11-03T09:00:00+08:00';
    const result = await ingest(state, bad);
    assert.equal(result.response.status, 422);
    assert.equal(result.response.body.code, 'date_only_trigger_time_invalid');
    assert.equal(schedules(state).length, 0);
});

test('rescheduling replaces the trigger; only the new time is emailed', async () => {
    const state = world();
    await ingest(state, event({ version: 1, at: '2026-11-03T01:00:00Z' }));
    await ingest(state, event({ version: 2, at: '2026-11-04T02:00:00Z' }));

    assert.equal(schedules(state).length, 1);
    assert.equal(schedules(state)[0].schedule_version, 2);
    const send = sendingEnabledCopy(okGmail());
    state.now = Date.parse('2026-11-03T01:30:00Z');
    assert.equal((await send(state)).sentEmails.length, 0, 'old trigger no longer fires');
    state.now = Date.parse('2026-11-04T02:00:00Z');
    const mail = (await send(state)).sentEmails;
    assert.equal(mail.length, 1);
    assert.match(mail[0].subject, /2026-11-04 10:00/);
});

test('cancellation and completion stop the reminder and clear the stored email', async () => {
    for (const reason of ['unscheduled', 'completed', 'deleted', 'recipient_unavailable']) {
        const state = world();
        await ingest(state, event({ version: 1 }));
        const result = await ingest(state, event({ version: 2, type: 'schedule.cancelled', cancelReason: reason }));

        assert.equal(result.response.status, 200, reason);
        const [row] = schedules(state);
        assert.equal(row.status, 'cancelled');
        assert.equal(row.cancel_reason, reason);
        assert.equal(row.recipient_email, '');
        assert.equal(row.trigger_at_utc, null);
        state.now = Date.parse('2026-11-05T00:00:00Z');
        assert.equal((await sendingEnabledCopy(okGmail())(state)).sentEmails.length, 0, reason);
    }
});

test('completion invalidates a reminder even after it was claimed but before the outcome is written', async () => {
    const state = world();
    await ingest(state, event({ version: 1 }));
    state.now = Date.parse('2026-11-03T01:00:00Z');
    // Simulate a delivery claim left in "sending", then the completion arrives.
    state.store.update('cims_reminder_schedules', { type: 'and', filters: [{ columnName: 'subject_key', condition: 'eq', value: 'ci_activity:42' }] }, { delivery_status: 'sending', delivery_claim: 'x', delivery_updated_at: new Date(state.now).toISOString() });
    await ingest(state, event({ version: 2, type: 'schedule.cancelled', cancelReason: 'completed' }));

    assert.equal(newest(state)[0].status, 'cancelled');
    assert.equal(newest(state)[0].delivery_status, 'cancelled');
    state.now += 2 * HOUR;
    const run = await sendingEnabledCopy(okGmail())(state);
    assert.equal(run.sentEmails.length, 0);
    assert.equal(logs(state).length, 0, 'a cancelled subject is not even marked uncertain');
});

test('out-of-order versions: an older version never overwrites a newer one', async () => {
    const state = world();
    await ingest(state, event({ version: 1 }));
    assert.equal((await ingest(state, event({ version: 3, at: '2026-11-06T01:00:00Z' }))).response.body.result, 'applied');
    const late = await ingest(state, event({ version: 2, at: '2026-11-05T01:00:00Z' }));

    assert.equal(late.response.status, 200, 'acknowledged so Laravel stops retrying');
    assert.equal(late.response.body.result, 'stale');
    assert.equal(newest(state)[0].schedule_version, 3);
    assert.equal(newest(state)[0].trigger_at_utc, '2026-11-06T01:00:00.000Z');

    // A v3 delivered for the first time only after the v4 cancellation is stale, not applied.
    const other = world();
    await ingest(other, event({ version: 1 }));
    await ingest(other, event({ version: 4, type: 'schedule.cancelled' }));
    const delayed = await ingest(other, event({ version: 3, at: '2026-11-06T01:00:00Z' }));
    assert.equal(delayed.response.body.result, 'stale');
    assert.equal(newest(other)[0].status, 'cancelled');
    assert.equal(newest(other)[0].schedule_version, 4);
});

test('a cancellation that arrives before its creation event still wins', async () => {
    const state = world();
    await ingest(state, event({ version: 2, type: 'schedule.cancelled' }));
    const created = await ingest(state, event({ version: 1 }));

    assert.equal(created.response.body.result, 'stale');
    assert.equal(schedules(state).length, 1);
    assert.equal(schedules(state)[0].status, 'cancelled');
});

test('duplicate event ids: replay is acknowledged once and changes nothing; conflicts are refused', async () => {
    const state = world();
    const payload = event({ version: 1 });
    await ingest(state, payload);
    const replay = await ingest(state, payload, { timestamp: Math.floor(state.now / 1000) + 30 });

    assert.equal(replay.response.status, 200);
    assert.equal(replay.response.body.result, 'duplicate');
    assert.equal(ledger(state).length, 1);
    assert.equal(schedules(state).length, 1);

    const conflicting = { ...payload, occurred_at: '2026-10-25T01:59:59Z' };
    const conflict = await ingest(state, conflicting);
    assert.equal(conflict.response.status, 409);
    assert.equal(conflict.response.body.code, 'event_identity_conflict');

    const reusedKey = event({ version: 1, eventId: randomUUID() });
    assert.equal((await ingest(state, reusedKey)).response.status, 409);
});

test('invalid HMAC, tampering, wrong secret, expired timestamp and missing headers are rejected with no writes', async () => {
    const state = world();
    const payload = event();
    const cases = [
        [{ signature: `sha256=${'0'.repeat(64)}` }, 401, 'signature_invalid'],
        [{ secret: 'a-different-but-long-enough-secret-value-000000' }, 401, 'signature_invalid'],
        [{ timestamp: Math.floor(state.now / 1000) - 301 }, 401, 'timestamp_outside_tolerance'],
        [{ signature: 'sha256=abc' }, 401, 'signature_malformed'],
        [{ headers: { 'x-cims-signature': '' } }, 401, 'authentication_headers_missing'],
        [{ headers: { 'x-cims-event-id': randomUUID() } }, 422, 'event_id_header_mismatch'],
        [{ rawBody: 'x'.repeat(16 * 1024 + 1) }, 413, 'body_size_invalid'],
    ];
    for (const [options, status, code] of cases) {
        const result = await ingest(state, payload, options);
        assert.equal(result.response.status, status, code);
        assert.equal(result.response.body.code, code);
    }
    // Tampering a single byte AFTER signing.
    const raw = JSON.stringify(payload);
    const timestamp = Math.floor(state.now / 1000);
    const goodSig = `sha256=${createHmac('sha256', SECRET).update(`${timestamp}.${raw}`).digest('hex')}`;
    const tampered = await ingest(state, payload, { rawBody: raw.replace('creator@', 'attacker@'), signature: goodSig, timestamp });
    assert.equal(tampered.response.status, 401);

    assert.equal(schedules(state).length, 0);
    assert.equal(ledger(state).length, 0);
});

test('a missing Crypto credential fails closed with a non-2xx response', async () => {
    const state = world();
    const result = await ingest(state, event(), undefined, { credentials: {} });
    assert.equal(result.response.status, 500);
    assert.equal(schedules(state).length, 0);
});

test('missing creator email: an active event is rejected; Laravel\'s recipient_unavailable cancellation is accepted', async () => {
    const state = world();
    const missing = event();
    delete missing.recipient.email;
    assert.equal((await ingest(state, missing)).response.status, 422);
    assert.equal((await ingest(state, event({ email: 'not-an-email' }))).response.body.code, 'recipient_email_invalid');
    assert.equal((await ingest(state, event({ version: 1, type: 'schedule.cancelled', cancelReason: 'recipient_unavailable' }))).response.status, 200);
    assert.equal(schedules(state)[0].status, 'cancelled');
});

test('storage failure at every Data Table step returns 503 (never 200); the retried event then applies once', async () => {
    const steps = [
        ['cims_reminder_events', 'get'], ['cims_reminder_events', 'insert'], ['cims_reminder_schedules', 'update'],
        ['cims_reminder_schedules', 'get'], ['cims_reminder_schedules', 'insert'], ['cims_reminder_events', 'update'],
    ];
    for (const [table, operation] of steps) {
        const state = world();
        const payload = event();
        state.store.failNext(table, operation);
        const failed = await ingest(state, payload);
        assert.equal(failed.response.status, 503, `${table}.${operation}`);
        assert.equal(failed.response.body.code, 'storage_unavailable');

        const retried = await ingest(state, payload);
        assert.equal(retried.response.status, 200, `${table}.${operation} retry`);
        assert.ok(['applied', 'duplicate'].includes(retried.response.body.result));
        assert.equal(newest(state).length, 1);
        assert.equal(newest(state)[0].schedule_version, 1);
    }
});

test('concurrent ingestion (300 random interleavings): versions converge on the newest and never regress', async () => {
    const random = rng(2026);
    for (let round = 0; round < 300; round++) {
        const state = world();
        const v1 = event({ version: 1, at: '2026-11-03T01:00:00Z' });
        const v2 = event({ version: 2, at: '2026-11-04T01:00:00Z' });
        const v3 = event({ version: 3, type: 'schedule.cancelled' });
        const inputs = [v1, v2, v3, v1].sort(() => random() - 0.5);
        const results = await interleave(inputs.map((payload) => execute(INGESTION, request(state, payload), state.env())), random);
        for (const result of results) {
            assert.ok([200, 503].includes(result.response.status), JSON.stringify(result.response));
        }
        // Laravel keeps any non-acknowledged event pending and retries the same bytes.
        for (const [index, result] of results.entries()) {
            if (result.response.status !== 200) await deliver(state, inputs[index]);
        }

        const current = newest(state);
        assert.ok(current.length >= 1);
        for (const row of current) {
            assert.equal(row.schedule_version, 3, `round ${round}`);
            assert.equal(row.status, 'cancelled');
        }
        state.now = Date.parse('2026-11-05T00:00:00Z');
        assert.equal((await sendingEnabledCopy(okGmail())(state)).sentEmails.length, 0, `round ${round}`);
    }
});

// ----------------------------------------------------------------- Workflow B: due processing

test('the committed (unmodified) processing workflow never sends and releases its claims', async () => {
    const state = world();
    await ingest(state, event());
    state.now = Date.parse('2026-11-03T02:00:00Z');
    const run = await runDefaultProcessing(state);

    assert.equal(run.error, null);
    assert.equal(run.sentEmails.length, 0);
    assert.ok(run.runData.has('Release Claims'));
    assert.equal(run.runData.has('Send Reminder Email'), false);
    const [row] = schedules(state);
    assert.equal(row.delivery_status, 'pending');
    assert.equal(row.delivery_attempts, 0);
    assert.equal(row.delivery_claim, '');
});

test('the email goes only to the creator email from the validated event, with generic content', async () => {
    const state = world();
    await ingest(state, event({ email: 'original.creator@example.test' }));
    state.now = Date.parse('2026-11-03T01:00:00Z');
    const [mail] = (await sendingEnabledCopy(okGmail())(state)).sentEmails;

    assert.equal(mail.to, 'original.creator@example.test');
    assert.match(mail.message, /Barangay Check/);
    assert.match(mail.message, /https:\/\/cims\.example\.test\/client-folders\/9\/activities/);
    const [entry] = logs(state);
    assert.equal(entry.outcome, 'sent');
    assert.equal(entry.provider_message_id, 'msg-1');
    assert.ok(!JSON.stringify(logs(state)).includes('@'), 'delivery logs store no email address');
});

test('reminder already sent: later runs send nothing', async () => {
    const state = world();
    await ingest(state, event());
    state.now = Date.parse('2026-11-03T01:00:00Z');
    const send = sendingEnabledCopy(okGmail());
    assert.equal((await send(state)).sentEmails.length, 1);
    for (let i = 0; i < 3; i++) {
        state.now += 5 * 60 * 1000;
        assert.equal((await send(state)).sentEmails.length, 0);
    }
    assert.equal(schedules(state)[0].delivery_status, 'sent');
    // A replayed (duplicate) event cannot re-arm a sent reminder.
    await ingest(state, event({ version: 1, eventId: schedules(state)[0].last_event_id }));
    assert.equal(schedules(state)[0].delivery_status, 'sent');
});

test('duplicate Data Table rows for one subject produce one email and are all marked', async () => {
    const state = world();
    await ingest(state, event({ version: 2 }));
    // Simulate rows left by a concurrent first-insert race: a stale v1 and an identical v2 copy.
    const [row] = schedules(state);
    const copy = Object.fromEntries(Object.entries(row).filter(([column]) => !['id', 'createdAt', 'updatedAt'].includes(column)));
    state.store.insert('cims_reminder_schedules', copy);
    state.store.insert('cims_reminder_schedules', { ...copy, schedule_version: 1, trigger_at_utc: '2026-11-02T01:00:00Z', last_event_id: randomUUID() });
    state.now = Date.parse('2026-11-03T01:00:00Z');

    const run = await sendingEnabledCopy(okGmail())(state);
    assert.equal(run.sentEmails.length, 1);
    assert.deepEqual(newest(state).map((candidate) => candidate.delivery_status), ['sent', 'sent']);
    assert.equal(schedules(state).find((candidate) => candidate.schedule_version === 1).delivery_status, 'pending', 'lower version ignored');
});

test('overlapping processing runs (200 interleavings) never send the same reminder twice', async () => {
    const random = rng(7);
    for (let round = 0; round < 200; round++) {
        const state = world();
        await ingest(state, event({ subjectId: 1 }));
        await ingest(state, event({ subjectId: 2, at: '2026-11-03T00:30:00Z' }));
        state.now = Date.parse('2026-11-03T01:00:00Z');
        const gmail = okGmail();
        const copy = structuredClone(PROCESSING);
        copy.nodes.find((node) => node.name === 'Live Sending Approved?').parameters.conditions.conditions[0].leftValue = 'APPROVED_FOR_PHASE_2B';
        copy.nodes.find((node) => node.name === 'Send Reminder Email').disabled = false;
        const runs = await interleave([0, 1, 2].map(() => execute(copy, [{ json: {} }], state.env({ gmail }))), random);

        const sent = runs.flatMap((run) => run.sentEmails.map((mail) => mail.subject));
        assert.equal(sent.length, 2, `round ${round}: ${sent.join(' | ')}`);
        assert.equal(new Set(sent).size, 2);
    }
});

test('definite Gmail failure (4xx): bounded, delayed retries, then it stops', async () => {
    const state = world();
    await ingest(state, event());
    state.now = Date.parse('2026-11-03T01:00:00Z');
    let calls = 0;
    const send = sendingEnabledCopy(() => {
        calls++;
        return { error: { message: 'Bad request - please check your parameters', httpCode: '400' } };
    });

    await send(state);
    assert.equal(schedules(state)[0].delivery_status, 'failed');
    await send(state);
    assert.equal(calls, 1, 'not retried before the delay');
    state.now += 16 * 60 * 1000;
    await send(state);
    assert.equal(calls, 2);
    state.now += 31 * 60 * 1000;
    await send(state);
    assert.equal(calls, 3);
    state.now += 10 * HOUR;
    await send(state);
    assert.equal(calls, 3, 'stops after MAX_ATTEMPTS');
    assert.deepEqual(logs(state).map((entry) => entry.outcome), ['failed', 'failed', 'failed']);
    assert.deepEqual(logs(state).map((entry) => entry.response_status), [400, 400, 400]);
});

test('uncertain Gmail outcome (5xx, timeout, unrecognised response) is never retried automatically', async () => {
    const outcomes = [
        { error: { message: 'The service is receiving too many requests', httpCode: '503' } },
        { error: { message: 'ETIMEDOUT' } },
        { json: { ok: true } },
    ];
    for (const outcome of outcomes) {
        const state = world();
        await ingest(state, event());
        state.now = Date.parse('2026-11-03T01:00:00Z');
        let calls = 0;
        const send = sendingEnabledCopy(() => {
            calls++;
            return outcome;
        });
        await send(state);
        state.now += 24 * HOUR;
        await send(state);

        assert.equal(calls, 1, JSON.stringify(outcome));
        assert.equal(schedules(state)[0].delivery_status, 'uncertain');
        assert.equal(logs(state).length, 1);
        assert.equal(logs(state)[0].outcome, 'uncertain');
    }
});

test('the disabled Gmail node\'s pass-through item is never mistaken for a sent email', async () => {
    const state = world();
    await ingest(state, event());
    state.now = Date.parse('2026-11-03T01:00:00Z');
    const copy = structuredClone(PROCESSING);
    copy.nodes.find((node) => node.name === 'Live Sending Approved?').parameters.conditions.conditions[0].leftValue = 'APPROVED_FOR_PHASE_2B';
    // Gate opened but the Gmail node left disabled (a plausible operator mistake).
    const run = await drain(execute(copy, [{ json: {} }], state.env()));

    assert.equal(run.sentEmails.length, 0);
    assert.equal(schedules(state)[0].delivery_status, 'uncertain');
    assert.equal(logs(state)[0].error_code, 'gmail_response_unrecognized');
});

test('a claim abandoned mid-send becomes uncertain after 30 minutes and is not resent', async () => {
    const state = world();
    await ingest(state, event());
    state.now = Date.parse('2026-11-03T01:00:00Z');
    state.store.update('cims_reminder_schedules', { type: 'and', filters: [{ columnName: 'subject_key', condition: 'eq', value: 'ci_activity:42' }] }, { delivery_status: 'sending', delivery_claim: 'crashed-run', delivery_attempts: 1, delivery_updated_at: new Date(state.now).toISOString() });
    const send = sendingEnabledCopy(okGmail());

    state.now += 10 * 60 * 1000;
    assert.equal((await send(state)).sentEmails.length, 0);
    assert.equal(schedules(state)[0].delivery_status, 'sending');
    state.now += 25 * 60 * 1000;
    assert.equal((await send(state)).sentEmails.length, 0);
    assert.equal(schedules(state)[0].delivery_status, 'uncertain');
    assert.equal(logs(state)[0].error_code, 'claim_expired_without_result');
});

test('an invalid stored recipient is never emailed', async () => {
    const state = world();
    await ingest(state, event());
    state.store.update('cims_reminder_schedules', { type: 'and', filters: [{ columnName: 'subject_key', condition: 'eq', value: 'ci_activity:42' }] }, { recipient_email: '' });
    state.now = Date.parse('2026-11-03T01:00:00Z');
    assert.equal((await sendingEnabledCopy(okGmail())(state)).sentEmails.length, 0);
});
