'use strict';

// Phase 2A-7 REFERENCE-MODEL tests for the simplified workflows. The committed JSON is executed by
// ../phase-2a-6/workflow-harness.cjs (NOT by n8n). Synthetic data only; no network, no email.
//
//   node --test scripts/n8n-phase2a/phase-2a-7/workflows.test.cjs

const assert = require('node:assert/strict');
const { createHmac, randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { DataTableStore, execute } = require('../phase-2a-6/workflow-harness.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const DIR = path.join(ROOT, 'docs/n8n/phase-2a-7');
const SCHEMA = JSON.parse(fs.readFileSync(path.join(DIR, 'data-tables.schema.json'), 'utf8'));
const RECEIVER = JSON.parse(fs.readFileSync(path.join(DIR, 'workflows/brbi-cims-schedule-receiver.json'), 'utf8'));
const REMINDER = JSON.parse(fs.readFileSync(path.join(DIR, 'workflows/brbi-cims-email-reminder.json'), 'utf8'));

const SECRET = 'phase-2a-7-synthetic-signing-secret-0123456789';
const ORIGIN = 'https://cims.example.test';
const T0 = Date.parse('2026-10-25T02:00:00Z');
const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const SCHEDULES = 'cims_reminder_schedules';

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
function event({ subjectType = 'ci_activity', subjectId = 42, activityId = 42, version = 1, type, at = '2026-11-03T01:00:00Z', dateOnly = false, cancelReason = 'completed', email = 'creator@example.test', userId = 7, label = 'Barangay Check', eventId = randomUUID() } = {}) {
    const eventType = type ?? (version === 1 ? 'schedule.created' : 'schedule.changed');
    const cancelled = eventType === 'schedule.cancelled';
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
            ci_activity_id: activityId,
            version,
            scheduled_at: cancelled ? null : at,
            has_specific_time: cancelled ? null : !dateOnly,
            trigger_at: cancelled ? null : triggerAt,
            trigger_at_local: cancelled ? null : manila(triggerAt),
            timezone: 'Asia/Manila',
            cancel_reason: cancelled ? cancelReason : null,
        },
        recipient: cancelled ? { user_id: userId } : { user_id: userId, email },
        content: cancelled ? null : { subject_label: label, activities_url: `${ORIGIN}/client-folders/9/activities` },
    };
}

/** The item n8n's Webhook node emits for a signed Laravel request with Raw Body enabled. */
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
                'x-cims-event-id': payload ? payload.event_id : 'none',
                'idempotency-key': payload ? payload.idempotency_key : 'none',
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
/** Runs an execution but abandons it (simulated n8n crash/restart) after `steps` nodes. */
async function crashAfter(generator, steps) {
    for (let i = 0; i <= steps; i++) {
        const step = await generator.next();
        if (step.done) return step.value;
    }
    await generator.return(undefined);
    return null;
}
const receive = (state, payload, options, envExtra) => drain(execute(RECEIVER, request(state, payload, options), state.env(envExtra)));

/** Laravel dispatcher semantics: re-send the SAME bytes until a 2xx (bounded). */
async function deliver(state, payload, attempts = 5) {
    let result;
    for (let i = 0; i < attempts; i++) {
        result = await receive(state, payload);
        if (result.response.status >= 200 && result.response.status < 300) return result;
    }
    return result;
}

/** TEST-ONLY copy: opens the Phase 2B gate and enables a MOCK Gmail node. Never written to disk. */
function sendingEnabled(gmail, { enableGmailNode = true } = {}) {
    const copy = structuredClone(REMINDER);
    copy.nodes.find((node) => node.name === 'Live Sending Approved?').parameters.conditions.conditions[0].leftValue = 'APPROVED_FOR_PHASE_2B';
    copy.nodes.find((node) => node.name === 'Send Reminder Email').disabled = !enableGmailNode;

    return copy;
}
const run = (workflow, state, extra = {}) => drain(execute(workflow, [{ json: {} }], state.env(extra)));
const sender = (gmail = okGmail()) => {
    const copy = sendingEnabled(gmail);
    return (state) => run(copy, state, { gmail });
};
function okGmail() {
    let n = 0;
    return () => ({ json: { id: `msg-${++n}`, threadId: `thr-${n}`, labelIds: ['SENT'] } });
}

const schedules = (state) => state.store.all(SCHEDULES);
const receipts = (state) => state.store.all('cims_reminder_events');
const logs = (state) => state.store.all('cims_reminder_delivery_logs');
const newest = (state, key = 'ci_activity:42') => {
    const rows = schedules(state).filter((row) => row.subject_key === key);
    const version = Math.max(...rows.map((row) => row.schedule_version));
    return rows.filter((row) => row.schedule_version === version);
};
const bySubject = (key) => ({ type: 'and', filters: [{ columnName: 'subject_key', condition: 'eq', value: key }] });

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
const predecessors = (workflow, name) => Object.entries(workflow.connections)
    .flatMap(([from, { main }]) => main.flatMap((targets, output) => targets.filter((target) => target.node === name).map(() => [from, output])));

// ----------------------------------------------------------------- static artifact checks

test('node counts: Schedule Receiver 16 (was 31), Email Reminder 13 (was 16)', () => {
    assert.equal(RECEIVER.nodes.length, 16);
    assert.equal(REMINDER.nodes.length, 13);
    assert.equal(RECEIVER.name, 'BRBI CIMS — Schedule Receiver');
    assert.equal(REMINDER.name, 'BRBI CIMS — Email Reminder');
});

test('artifacts are inactive, carry no secret or credential reference, no pinned data, and save no execution data', () => {
    for (const workflow of [RECEIVER, REMINDER]) {
        const text = JSON.stringify(workflow);
        assert.equal(workflow.active, false);
        assert.deepEqual(workflow.pinData, {});
        assert.equal(workflow.settings.saveDataSuccessExecution, 'none');
        assert.equal(workflow.settings.saveDataErrorExecution, 'none');
        assert.equal(workflow.settings.saveManualExecutions, false);
        assert.equal(workflow.settings.executionOrder, 'v1');
        assert.ok(!text.includes(SECRET));
        assert.ok(!/\$env|process\.env|require\(|hmacSecret|N8N_REMINDERS_SIGNING_SECRET/.test(text));
        for (const node of workflow.nodes) {
            assert.equal(node.credentials, undefined, `${node.name} must not embed a credential reference`);
        }
    }
});

test('only node types/versions present in the n8n@2.43.8 tag; every connection resolves', () => {
    const allowed = {
        'n8n-nodes-base.webhook': [2.1], 'n8n-nodes-base.extractFromFile': [1.1], 'n8n-nodes-base.code': [2],
        'n8n-nodes-base.if': [2.2], 'n8n-nodes-base.switch': [3.2], 'n8n-nodes-base.crypto': [2],
        'n8n-nodes-base.dataTable': [1.1], 'n8n-nodes-base.respondToWebhook': [1.1],
        'n8n-nodes-base.scheduleTrigger': [1.2], 'n8n-nodes-base.gmail': [2.1],
    };
    for (const workflow of [RECEIVER, REMINDER]) {
        for (const node of workflow.nodes) {
            assert.ok(allowed[node.type] && allowed[node.type].includes(node.typeVersion), `${node.name}: ${node.type}@${node.typeVersion}`);
        }
        for (const [from, { main }] of Object.entries(workflow.connections)) {
            assert.ok(workflow.nodes.some((node) => node.name === from));
            for (const target of main.flat()) assert.ok(workflow.nodes.some((node) => node.name === target.node));
        }
    }
});

test('Code nodes use no module loading, Node built-ins, environment access, or the URL global', () => {
    for (const workflow of [RECEIVER, REMINDER]) {
        for (const node of workflow.nodes.filter((candidate) => candidate.type === 'n8n-nodes-base.code')) {
            assert.ok(!/require\s*\(|\$env|process\.|import\s|crypto\.|new URL\(|Buffer\./.test(node.parameters.jsCode), node.name);
        }
    }
});

test('HMAC is computed only by Crypto nodes; every hmac node needs the (unassigned) Crypto credential', () => {
    const cryptoNodes = RECEIVER.nodes.filter((node) => node.type === 'n8n-nodes-base.crypto');
    assert.deepEqual(cryptoNodes.map((node) => node.parameters.action), ['hmac', 'hmac', 'hmac']);
    for (const node of cryptoNodes) {
        assert.equal(node.parameters.type, 'SHA256');
        assert.equal(node.parameters.encoding, 'hex');
        assert.ok(!('secret' in node.parameters));
    }
    assert.equal(RECEIVER.nodes.find((node) => node.type === 'n8n-nodes-base.webhook').parameters.options.rawBody, true);
});

test('HTTP 200 is reachable only after the guarded write, the re-read and the receipt insert', () => {
    assert.deepEqual(predecessors(RECEIVER, 'Respond Accepted'), [['Record Event', 0]]);
    assert.deepEqual(predecessors(RECEIVER, 'Record Event'), [['Route Outcome', 1], ['Insert Schedule Row', 0]]);
    assert.deepEqual(predecessors(RECEIVER, 'Route Outcome'), [['Decide Outcome', 0]]);
    assert.deepEqual(predecessors(RECEIVER, 'Decide Outcome'), [['Read Schedule Rows', 0]]);
    assert.deepEqual(predecessors(RECEIVER, 'Read Schedule Rows'), [['Guarded Schedule Write', 0]]);
    for (const node of RECEIVER.nodes.filter((candidate) => candidate.type === 'n8n-nodes-base.dataTable')) {
        assert.equal(node.onError, 'continueErrorOutput');
        assert.deepEqual(RECEIVER.connections[node.name].main[1], [{ node: 'Respond Storage Failure', type: 'main', index: 0 }]);
    }
    assert.equal(RECEIVER.nodes.find((node) => node.name === 'Respond Storage Failure').parameters.options.responseCode, 503);
});

test('the Gmail node is disabled, credential-less and never retried; the closed gate precedes the claim', () => {
    const gmail = REMINDER.nodes.find((node) => node.type === 'n8n-nodes-base.gmail');
    assert.equal(gmail.disabled, true);
    assert.equal(gmail.retryOnFail, false);
    assert.equal(gmail.onError, 'continueErrorOutput');
    const gate = REMINDER.nodes.find((node) => node.name === 'Live Sending Approved?').parameters.conditions.conditions[0];
    assert.notEqual(gate.leftValue, gate.rightValue);
    assert.deepEqual(predecessors(REMINDER, 'Claim Reminder'), [['Live Sending Approved?', 0]]);
    assert.equal((REMINDER.connections['Live Sending Approved?'].main[1] || []).length, 0, 'closed gate leads nowhere');
    assert.equal(RECEIVER.nodes.some((node) => node.type === 'n8n-nodes-base.gmail'), false);
});

test('committed artifacts match the generator; the Phase 2A-6 backup is untouched', () => {
    const { artifacts } = require('./build-workflows.cjs');
    assert.deepEqual(JSON.parse(JSON.stringify(artifacts['brbi-cims-schedule-receiver.json'])), RECEIVER);
    assert.deepEqual(JSON.parse(JSON.stringify(artifacts['brbi-cims-email-reminder.json'])), REMINDER);
    for (const file of ['brbi-cims-reminder-event-ingestion.json', 'brbi-cims-due-reminder-processing.json']) {
        assert.ok(fs.existsSync(path.join(ROOT, 'docs/n8n/phase-2a-6/workflows', file)), file);
    }
});

// ----------------------------------------------------------------- Workflow A: Schedule Receiver

test('1. creating a schedule: 200 applied, one active row, one receipt', async () => {
    const state = world();
    const result = await receive(state, event());

    assert.equal(result.response.status, 200);
    assert.deepEqual(result.response.body, { result: 'applied', event_id: schedules(state)[0].last_event_id });
    assert.equal(schedules(state).length, 1);
    const [row] = schedules(state);
    assert.equal(row.status, 'scheduled');
    assert.equal(row.delivery_status, 'pending');
    assert.equal(row.trigger_at_utc, '2026-11-03T01:00:00.000Z');
    assert.equal(row.trigger_at_manila, '2026-11-03T09:00:00+08:00');
    assert.deepEqual(receipts(state).map((receipt) => receipt.processing_status), ['applied']);
});

test('Bank/Cooperative and Asset Check targets are independent subjects of the same activity', async () => {
    const state = world();
    await receive(state, event({ subjectId: 42, activityId: 42 }));
    await receive(state, event({ subjectType: 'bank_target', subjectId: 5, activityId: 42, label: 'Bank/Cooperative Check', at: '2026-11-03T02:00:00Z' }));
    await receive(state, event({ subjectType: 'asset_target', subjectId: 9, activityId: 42, label: 'Asset Check', at: '2026-11-03T03:00:00Z' }));
    await receive(state, event({ subjectType: 'bank_target', subjectId: 5, activityId: 42, version: 2, type: 'schedule.cancelled' }));

    const status = Object.fromEntries(schedules(state).map((row) => [row.subject_key, row.status]));
    assert.deepEqual(status, { 'ci_activity:42': 'scheduled', 'bank_target:5': 'cancelled', 'asset_target:9': 'scheduled' });
    state.now = Date.parse('2026-11-03T04:00:00Z');
    const subjects = (await sender()(state)).sentEmails.map((mail) => mail.subject).sort();
    assert.deepEqual(subjects, [
        'BRBI CIMS reminder: Asset Check scheduled 2026-11-03 11:00 (Asia/Manila)',
        'BRBI CIMS reminder: Barangay Check scheduled 2026-11-03 09:00 (Asia/Manila)',
    ]);
});

test('Laravel cross-runtime fixture: the Crypto-node HMAC accepts Laravel\'s exact signature', async () => {
    const state = world(1_798_500_000 * 1000);
    const result = await drain(execute(RECEIVER, request(state, null, {
        rawBody: '{"schema":"brbi.cims.reminder.v1"}',
        signature: 'sha256=1d1fe21a032e64c85dae0e94e2c06b89ef96b9f270e9844ac0cbaf1ef3f2ae39',
        timestamp: 1_798_500_000,
    }), { ...state.env(), credentials: { crypto: { hmacSecret: 'phase-2a-test-signing-secret-0123456789abcdef' } } }));

    // Signature accepted; only then does the strict schema reject the fixture body.
    assert.equal(result.response.status, 422);
    assert.equal(result.response.body.code, 'payload_unexpected_fields');
});

test('2-3. rescheduling and repeated rescheduling: only the newest time is stored and emailed', async () => {
    const state = world();
    const times = ['2026-11-03T01:00:00Z', '2026-11-04T02:00:00Z', '2026-11-05T03:00:00Z', '2026-11-06T04:30:00Z'];
    for (const [index, at] of times.entries()) {
        const result = await receive(state, event({ version: index + 1, at }));
        assert.equal(result.response.body.result, 'applied');
    }
    assert.equal(schedules(state).length, 1);
    assert.equal(schedules(state)[0].schedule_version, 4);

    const send = sender();
    for (const at of times.slice(0, 3)) {
        state.now = Date.parse(at) + MINUTE;
        assert.equal((await send(state)).sentEmails.length, 0, `old trigger ${at} must not fire`);
    }
    state.now = Date.parse(times[3]);
    const mail = (await send(state)).sentEmails;
    assert.equal(mail.length, 1);
    assert.match(mail[0].subject, /2026-11-06 12:30/);
});

test('4-5. cancellation and completion invalidate the reminder and clear the stored email', async () => {
    for (const reason of ['unscheduled', 'completed', 'deleted', 'recipient_unavailable']) {
        const state = world();
        await receive(state, event({ version: 1 }));
        const result = await receive(state, event({ version: 2, type: 'schedule.cancelled', cancelReason: reason }));

        assert.equal(result.response.status, 200, reason);
        const [row] = schedules(state);
        assert.equal(row.status, 'cancelled');
        assert.equal(row.cancel_reason, reason);
        assert.equal(row.recipient_email, '');
        assert.equal(row.trigger_at_utc, null);
        state.now = Date.parse('2026-11-05T00:00:00Z');
        assert.equal((await sender()(state)).sentEmails.length, 0, reason);
    }
});

test('5b. completion arriving after a delivery claim leaves the subject cancelled and unsent', async () => {
    const state = world();
    await receive(state, event({ version: 1 }));
    state.now = Date.parse('2026-11-03T01:00:00Z');
    state.store.update(SCHEDULES, bySubject('ci_activity:42'), { delivery_status: 'sending', delivery_claim: 'x', delivery_updated_at: new Date(state.now).toISOString() });
    await receive(state, event({ version: 2, type: 'schedule.cancelled', cancelReason: 'completed' }));

    assert.equal(newest(state)[0].delivery_status, 'cancelled');
    state.now += 2 * HOUR;
    assert.equal((await sender()(state)).sentEmails.length, 0);
    assert.equal(logs(state).length, 0);
});

test('6. the email goes only to the original creator email carried by the validated event', async () => {
    const state = world();
    await receive(state, event({ email: 'original.creator@example.test', userId: 11 }));
    state.now = Date.parse('2026-11-03T01:00:00Z');
    const [mail] = (await sender()(state)).sentEmails;

    assert.equal(mail.to, 'original.creator@example.test');
    assert.match(mail.message, /Barangay Check/);
    assert.equal(logs(state)[0].recipient_user_id, 11);
    assert.ok(!JSON.stringify(logs(state)).includes('@'), 'delivery logs store no email address');
});

test('7-8. invalid signature, tampering, wrong secret, expired timestamp and bad headers are rejected with no writes', async () => {
    const state = world();
    const payload = event();
    const cases = [
        [{ signature: `sha256=${'0'.repeat(64)}` }, 401, 'signature_invalid'],
        [{ secret: 'a-different-but-long-enough-secret-value-000000' }, 401, 'signature_invalid'],
        [{ timestamp: Math.floor(state.now / 1000) - 301 }, 401, 'timestamp_outside_tolerance'],
        [{ timestamp: Math.floor(state.now / 1000) + 301 }, 401, 'timestamp_outside_tolerance'],
        [{ signature: 'sha256=abc' }, 401, 'signature_malformed'],
        [{ headers: { 'x-cims-signature': '' } }, 401, 'authentication_headers_missing'],
        [{ headers: { 'x-cims-timestamp': undefined } }, 401, 'authentication_headers_missing'],
        [{ headers: { 'x-cims-event-id': randomUUID() } }, 422, 'event_id_header_mismatch'],
        [{ rawBody: 'x'.repeat(16 * 1024 + 1) }, 413, 'body_size_invalid'],
    ];
    for (const [options, status, code] of cases) {
        const result = await receive(state, payload, options);
        assert.equal(result.response.status, status, code);
        assert.equal(result.response.body.code, code);
    }
    const raw = JSON.stringify(payload);
    const timestamp = Math.floor(state.now / 1000);
    const goodSig = `sha256=${createHmac('sha256', SECRET).update(`${timestamp}.${raw}`).digest('hex')}`;
    const tampered = await receive(state, payload, { rawBody: raw.replace('creator@', 'attacker@'), signature: goodSig, timestamp });
    assert.equal(tampered.response.status, 401);
    assert.equal(tampered.response.body.code, 'signature_invalid');

    assert.equal(schedules(state).length, 0);
    assert.equal(receipts(state).length, 0);
});

test('a missing Crypto credential fails closed (non-2xx, no writes)', async () => {
    const state = world();
    const result = await receive(state, event(), undefined, { credentials: {} });
    assert.equal(result.response.status, 500);
    assert.equal(schedules(state).length, 0);
});

test('missing or invalid creator email on an active event is rejected; recipient_unavailable cancellation is accepted', async () => {
    const state = world();
    const missing = event();
    delete missing.recipient.email;
    assert.equal((await receive(state, missing)).response.status, 422);
    assert.equal((await receive(state, event({ email: 'not-an-email' }))).response.body.code, 'recipient_email_invalid');
    assert.equal((await receive(state, event({ type: 'schedule.cancelled', cancelReason: 'recipient_unavailable' }))).response.status, 200);
    assert.equal(schedules(state)[0].status, 'cancelled');
});

test('schema rules: date-only must trigger at 08:00 Manila; activities_url must be http(s)', async () => {
    const state = world();
    const badTime = event({ at: '2026-11-02T16:00:00Z', dateOnly: true });
    badTime.reminder.trigger_at = '2026-11-03T01:00:00Z';
    badTime.reminder.trigger_at_local = '2026-11-03T09:00:00+08:00';
    assert.equal((await receive(state, badTime)).response.body.code, 'date_only_trigger_time_invalid');
    const badUrl = event();
    badUrl.content.activities_url = 'javascript:alert(1)';
    assert.equal((await receive(state, badUrl)).response.body.code, 'activities_url_invalid');
    assert.equal(schedules(state).length, 0);
});

test('9. duplicate event: replay answers 200 duplicate and changes nothing; a different event at the same version is 409', async () => {
    const state = world();
    const payload = event();
    await receive(state, payload);
    const before = schedules(state);
    const replay = await receive(state, payload, { timestamp: Math.floor(state.now / 1000) + 30 });

    assert.equal(replay.response.status, 200);
    assert.equal(replay.response.body.result, 'duplicate');
    assert.deepEqual(schedules(state), before);
    assert.deepEqual(receipts(state).map((receipt) => receipt.processing_status), ['applied', 'duplicate']);

    const conflict = await receive(state, event({ version: 1, eventId: randomUUID() }));
    assert.equal(conflict.response.status, 409);
    assert.equal(conflict.response.body.code, 'version_identity_conflict');
    assert.deepEqual(schedules(state), before);
});

test('10. out-of-order events: an older version never overwrites a newer one; late creation loses to cancellation', async () => {
    const state = world();
    await receive(state, event({ version: 1 }));
    await receive(state, event({ version: 3, at: '2026-11-06T01:00:00Z' }));
    const late = await receive(state, event({ version: 2, at: '2026-11-05T01:00:00Z' }));
    assert.equal(late.response.status, 200, 'acknowledged so Laravel stops retrying');
    assert.equal(late.response.body.result, 'stale');
    assert.equal(newest(state)[0].trigger_at_utc, '2026-11-06T01:00:00.000Z');

    const other = world();
    await receive(other, event({ version: 2, type: 'schedule.cancelled' }));
    assert.equal((await receive(other, event({ version: 1 }))).response.body.result, 'stale');
    assert.equal(schedules(other).length, 1);
    assert.equal(schedules(other)[0].status, 'cancelled');
});

test('11. concurrent events (400 random interleavings + Laravel retries): newest version always wins', async () => {
    const random = rng(2027);
    for (let round = 0; round < 400; round++) {
        const state = world();
        const inputs = [
            event({ version: 1, at: '2026-11-03T01:00:00Z' }),
            event({ version: 2, at: '2026-11-04T01:00:00Z' }),
            event({ version: 3, at: '2026-11-04T05:00:00Z' }),
        ];
        const cancelling = round % 2 === 0;
        if (cancelling) inputs.push(event({ version: 4, type: 'schedule.cancelled' }));
        inputs.push(inputs[0], inputs[1]);
        inputs.sort(() => random() - 0.5);

        const results = await interleave(inputs.map((payload) => execute(RECEIVER, request(state, payload), state.env())), random);
        for (const [index, result] of results.entries()) {
            assert.ok([200, 503].includes(result.response.status), JSON.stringify(result.response));
            if (result.response.status !== 200) await deliver(state, inputs[index]);
        }

        const current = newest(state);
        for (const row of current) {
            assert.equal(row.schedule_version, cancelling ? 4 : 3, `round ${round}`);
            assert.equal(row.status, cancelling ? 'cancelled' : 'scheduled');
        }
        state.now = Date.parse('2026-11-05T00:00:00Z');
        const mail = (await sender()(state)).sentEmails;
        assert.equal(mail.length, cancelling ? 0 : 1, `round ${round}`);
        if (!cancelling) assert.match(mail[0].subject, /2026-11-04 13:00/);
    }
});

test('17-18. a Data Table failure at any step returns 503, never 200; the retried event then succeeds', async () => {
    const steps = [[SCHEDULES, 'update'], [SCHEDULES, 'get'], [SCHEDULES, 'insert'], ['cims_reminder_events', 'insert']];
    for (const [table, operation] of steps) {
        const state = world();
        const payload = event();
        state.store.failNext(table, operation);
        const failed = await receive(state, payload);
        assert.equal(failed.response.status, 503, `${table}.${operation}`);
        assert.deepEqual(failed.response.body, { result: 'rejected', code: 'storage_unavailable' });

        const retried = await receive(state, payload);
        assert.equal(retried.response.status, 200, `${table}.${operation} retry`);
        assert.ok(['applied', 'duplicate'].includes(retried.response.body.result));
        assert.equal(newest(state).length, 1);
        assert.equal(newest(state)[0].schedule_version, 1);
    }
});

test('19a. receiver restart/recovery: a crash after ANY node leaves no 200, and the redelivered event converges', async () => {
    for (let steps = 0; steps < RECEIVER.nodes.length; steps++) {
        const state = world();
        await receive(state, event({ version: 1 }));
        const v2 = event({ version: 2, at: '2026-11-04T01:00:00Z' });
        const crashed = await crashAfter(execute(RECEIVER, request(state, v2), state.env()), steps);
        if (crashed) continue; // finished before the crash point

        const retried = await deliver(state, v2);
        assert.equal(retried.response.status, 200, `crash after ${steps} nodes`);
        assert.ok(['applied', 'duplicate'].includes(retried.response.body.result));
        assert.equal(newest(state)[0].schedule_version, 2);
        assert.equal(newest(state)[0].trigger_at_utc, '2026-11-04T01:00:00.000Z');
    }
});

// ----------------------------------------------------------------- Workflow B: Email Reminder

test('20. the committed (gate closed) Email Reminder workflow never claims, writes or sends', async () => {
    const state = world();
    await receive(state, event());
    state.now = Date.parse('2026-11-03T02:00:00Z');
    const before = JSON.stringify(schedules(state));
    const result = await run(REMINDER, state);

    assert.equal(result.error, null);
    assert.ok(result.runData.has('Select Deliverable'));
    assert.equal(result.runData.has('Claim Reminder'), false);
    assert.equal(result.runData.has('Send Reminder Email'), false);
    assert.equal(result.sentEmails.length, 0);
    assert.equal(JSON.stringify(schedules(state)), before);
    // The candidate it WOULD send is visible for staging inspection.
    const candidate = result.runData.get('Select Deliverable')[0][0][0].json;
    assert.equal(candidate.recipient_email, 'creator@example.test');
    assert.equal(candidate.trigger_at_manila, '2026-11-03T09:00:00+08:00');
});

test('12-13. date-only fires at 08:00 Asia/Manila; an explicit time fires at that time', async () => {
    const state = world();
    await receive(state, event({ subjectId: 1, at: '2026-11-02T16:00:00Z', dateOnly: true }));
    await receive(state, event({ subjectId: 2, at: '2026-11-03T06:45:00Z' }));
    const rows = Object.fromEntries(schedules(state).map((row) => [row.subject_key, row]));
    assert.equal(rows['ci_activity:1'].trigger_at_manila, '2026-11-03T08:00:00+08:00');
    assert.equal(rows['ci_activity:1'].has_specific_time, false);
    assert.equal(rows['ci_activity:2'].trigger_at_manila, '2026-11-03T14:45:00+08:00');

    const send = sender();
    state.now = Date.parse('2026-11-02T23:59:59Z');
    assert.equal((await send(state)).sentEmails.length, 0, 'not before 08:00 Manila');
    state.now = Date.parse('2026-11-03T00:00:00Z');
    const dateOnly = (await send(state)).sentEmails;
    assert.equal(dateOnly.length, 1);
    assert.match(dateOnly[0].subject, /scheduled 2026-11-03 \(Asia\/Manila\)$/);
    state.now = Date.parse('2026-11-03T06:44:59Z');
    assert.equal((await send(state)).sentEmails.length, 0, 'explicit time not yet due');
    state.now = Date.parse('2026-11-03T06:45:00Z');
    assert.match((await send(state)).sentEmails[0].subject, /2026-11-03 14:45/);
});

test('14. an already-sent reminder is never sent again, even after a replayed event', async () => {
    const state = world();
    const payload = event();
    await receive(state, payload);
    state.now = Date.parse('2026-11-03T01:00:00Z');
    const send = sender();
    assert.equal((await send(state)).sentEmails.length, 1);
    await receive(state, payload);
    for (let i = 0; i < 3; i++) {
        state.now += 5 * MINUTE;
        assert.equal((await send(state)).sentEmails.length, 0);
    }
    assert.equal(schedules(state)[0].delivery_status, 'sent');
});

test('duplicate rows for one subject (concurrent first inserts) produce exactly one email', async () => {
    const state = world();
    await receive(state, event({ version: 2 }));
    const [row] = schedules(state);
    const copy = Object.fromEntries(Object.entries(row).filter(([column]) => !['id', 'createdAt', 'updatedAt'].includes(column)));
    state.store.insert(SCHEDULES, copy);
    state.store.insert(SCHEDULES, { ...copy, schedule_version: 1, trigger_at_utc: '2026-11-02T01:00:00Z', last_event_id: randomUUID() });
    state.now = Date.parse('2026-11-03T01:00:00Z');

    assert.equal((await sender()(state)).sentEmails.length, 1);
    assert.deepEqual(newest(state).map((candidate) => candidate.delivery_status), ['sent', 'sent']);
    assert.equal(schedules(state).find((candidate) => candidate.schedule_version === 1).delivery_status, 'pending', 'superseded row ignored');
});

test('overlapping reminder runs (200 interleavings) never send a reminder twice', async () => {
    const random = rng(11);
    for (let round = 0; round < 200; round++) {
        const state = world();
        await receive(state, event({ subjectId: 1 }));
        await receive(state, event({ subjectId: 2, at: '2026-11-03T00:30:00Z' }));
        state.now = Date.parse('2026-11-03T01:00:00Z');
        const gmail = okGmail();
        const copy = sendingEnabled(gmail);
        const runs = await interleave([0, 1, 2].map(() => execute(copy, [{ json: {} }], state.env({ gmail }))), random);

        const sent = runs.flatMap((result) => result.sentEmails.map((mail) => mail.subject));
        assert.equal(sent.length, 2, `round ${round}: ${sent.join(' | ')}`);
        assert.equal(new Set(sent).size, 2);
    }
});

test('15. a definite Gmail failure (4xx) is retried with a delay, at most 3 attempts', async () => {
    const state = world();
    await receive(state, event());
    state.now = Date.parse('2026-11-03T01:00:00Z');
    let calls = 0;
    const gmail = () => {
        calls++;
        return { error: { message: 'Bad request - please check your parameters', httpCode: '400' } };
    };
    const copy = sendingEnabled(gmail);
    const send = () => run(copy, state, { gmail });

    await send();
    assert.equal(schedules(state)[0].delivery_status, 'failed');
    await send();
    assert.equal(calls, 1, 'not retried before the delay');
    state.now += 16 * MINUTE;
    await send();
    assert.equal(calls, 2);
    state.now += 31 * MINUTE;
    await send();
    assert.equal(calls, 3);
    state.now += 10 * HOUR;
    await send();
    assert.equal(calls, 3, 'stops after 3 attempts');
    assert.deepEqual(logs(state).map((entry) => [entry.outcome, entry.response_status, entry.attempt_number]), [['failed', 400, 1], ['failed', 400, 2], ['failed', 400, 3]]);
});

test('16. uncertain delivery (5xx, timeout, unrecognised response, disabled-node pass-through) is never retried', async () => {
    const outcomes = [
        { gmail: { error: { message: 'The service is receiving too many requests', httpCode: '503' } } },
        { gmail: { error: { message: 'ETIMEDOUT' } } },
        { gmail: { json: { ok: true } } },
        { gmail: null, enableGmailNode: false },
    ];
    for (const { gmail: outcome, enableGmailNode = true } of outcomes) {
        const state = world();
        await receive(state, event());
        state.now = Date.parse('2026-11-03T01:00:00Z');
        let calls = 0;
        const gmail = () => {
            calls++;
            return outcome;
        };
        const copy = sendingEnabled(gmail, { enableGmailNode });
        const first = await run(copy, state, { gmail });
        state.now += 24 * HOUR;
        await run(copy, state, { gmail });

        void first;
        assert.equal(calls, enableGmailNode ? 1 : 0, JSON.stringify(outcome));
        assert.equal(schedules(state)[0].delivery_status, 'uncertain');
        assert.equal(logs(state).length, 1);
        assert.equal(logs(state)[0].outcome, 'uncertain');
    }
});

test('19b. reminder restart/recovery: a crash at ANY node never double-sends; an abandoned claim becomes uncertain', async () => {
    for (let crashAt = 0; crashAt < REMINDER.nodes.length; crashAt++) {
        const state = world();
        await receive(state, event());
        state.now = Date.parse('2026-11-03T01:00:00Z');
        const ok = okGmail();
        let calls = 0;
        const gmail = (params) => {
            calls++;
            return ok(params);
        };
        const copy = sendingEnabled(gmail);
        await crashAfter(execute(copy, [{ json: {} }], state.env({ gmail })), crashAt);
        for (const advance of [5 * MINUTE, 40 * MINUTE, 24 * HOUR]) {
            state.now += advance;
            await run(copy, state, { gmail });
        }

        const status = schedules(state)[0].delivery_status;
        assert.ok(calls <= 1, `crash at ${crashAt}: ${calls} Gmail calls`);
        // Either it was (re)sent exactly once, or a send of unknown outcome is parked as uncertain.
        assert.ok((status === 'sent' && calls === 1) || status === 'uncertain', `crash at ${crashAt}: ${status}/${calls}`);
    }
});

test('a claim abandoned mid-send becomes uncertain after 30 minutes and is not resent', async () => {
    const state = world();
    await receive(state, event());
    state.now = Date.parse('2026-11-03T01:00:00Z');
    state.store.update(SCHEDULES, bySubject('ci_activity:42'), { delivery_status: 'sending', delivery_claim: 'crashed-run', delivery_attempts: 1, delivery_updated_at: new Date(state.now).toISOString() });
    const send = sender();

    state.now += 10 * MINUTE;
    assert.equal((await send(state)).sentEmails.length, 0);
    assert.equal(schedules(state)[0].delivery_status, 'sending');
    state.now += 25 * MINUTE;
    assert.equal((await send(state)).sentEmails.length, 0);
    assert.equal(schedules(state)[0].delivery_status, 'uncertain');
    assert.equal(logs(state)[0].error_code, 'claim_expired_without_result');
});

test('an invalid stored recipient is never emailed', async () => {
    const state = world();
    await receive(state, event());
    state.store.update(SCHEDULES, bySubject('ci_activity:42'), { recipient_email: '' });
    state.now = Date.parse('2026-11-03T01:00:00Z');
    assert.equal((await sender()(state)).sentEmails.length, 0);
});
