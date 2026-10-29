'use strict';

const assert = require('node:assert/strict');
const { createHmac, randomUUID } = require('node:crypto');
const test = require('node:test');

const { ContractError, MAX_BODY_BYTES, validateIncoming } = require('./contract-validator.cjs');
const { ReferenceStore } = require('./reference-store.cjs');

const SECRET = 'phase-2a-test-signing-secret-0123456789abcdef';
const NOW = 1_798_500_000;
const ORIGIN = 'https://cims.example.test';

function payload(version = 1, overrides = {}) {
    const subjectType = overrides.subjectType ?? 'ci_activity';
    const subjectId = overrides.subjectId ?? 42;
    const eventType = overrides.eventType ?? (version === 1 ? 'schedule.created' : 'schedule.changed');
    const cancelled = eventType === 'schedule.cancelled';
    const eventId = overrides.eventId ?? randomUUID();
    const idempotencyKey = overrides.idempotencyKey ?? `${subjectType}:${subjectId}:v${version}`;
    const scheduledAt = overrides.scheduledAt ?? '2027-01-05T01:00:00Z';
    const triggerAt = overrides.triggerAt ?? scheduledAt;
    const triggerAtLocal = new Date(Date.parse(triggerAt) + (8 * 60 * 60 * 1000)).toISOString().replace('Z', '+08:00');

    return {
        schema: 'brbi.cims.reminder.v1',
        event_id: eventId,
        idempotency_key: idempotencyKey,
        event_type: eventType,
        occurred_at: '2026-12-29T01:20:00Z',
        reminder: {
            key: `${subjectType}:${subjectId}`,
            subject_type: subjectType,
            subject_id: subjectId,
            ci_activity_id: 42,
            version,
            scheduled_at: cancelled ? null : scheduledAt,
            has_specific_time: cancelled ? null : (overrides.specificTime ?? true),
            trigger_at: cancelled ? null : triggerAt,
            trigger_at_local: cancelled ? null : triggerAtLocal,
            timezone: 'Asia/Manila',
            cancel_reason: cancelled ? (overrides.cancelReason ?? 'completed') : null,
        },
        recipient: cancelled ? { user_id: 7 } : { user_id: 7, email: 'creator@example.test' },
        content: cancelled ? null : {
            subject_label: 'Barangay Check',
            activities_url: `${ORIGIN}/client-folders/9/activities`,
        },
    };
}

function request(event, options = {}) {
    const rawBody = Buffer.from(JSON.stringify(event), 'utf8');
    const timestamp = String(options.timestamp ?? NOW);
    const signature = `sha256=${createHmac('sha256', SECRET).update(Buffer.from(`${timestamp}.`)).update(rawBody).digest('hex')}`;

    return {
        headers: {
            'x-cims-signature': options.signature ?? signature,
            'x-cims-timestamp': timestamp,
            'x-cims-event-id': event.event_id,
            'idempotency-key': event.idempotency_key,
        },
        rawBody,
        secret: SECRET,
        nowSeconds: NOW,
        allowedActivitiesOrigin: ORIGIN,
    };
}

function verified(event, options = {}) {
    return validateIncoming(request(event, options));
}

test('Laravel canonical HMAC uses timestamp dot exact raw JSON bytes', () => {
    const rawBody = Buffer.from('{"schema":"brbi.cims.reminder.v1"}', 'utf8');
    const timestamp = '1798500000';
    const signature = `sha256=${createHmac('sha256', SECRET).update(Buffer.from(`${timestamp}.`)).update(rawBody).digest('hex')}`;

    assert.equal(signature, 'sha256=1d1fe21a032e64c85dae0e94e2c06b89ef96b9f270e9844ac0cbaf1ef3f2ae39');
});

test('A/B: version 1 is applied once and its replay is only audited as duplicate', async () => {
    const store = new ReferenceStore();
    const event = payload(1);

    assert.equal((await store.ingestAtomic(verified(event))).outcome, 'applied');
    assert.equal((await store.ingestAtomic(verified(event))).outcome, 'duplicate');
    assert.equal(store.states.size, 1);
    assert.equal(store.states.get('ci_activity:42').version, 1);
    assert.equal(store.eventsByUuid.size, 1);
});

test('C: version 2 cannot overwrite version 3 when it arrives later', async () => {
    const store = new ReferenceStore();

    await store.ingestAtomic(verified(payload(3)));
    assert.equal((await store.ingestAtomic(verified(payload(2)))).outcome, 'stale');
    assert.equal(store.states.get('ci_activity:42').version, 3);
});

test('D/E: cancellation version 4 arriving first wins and delayed version 3 cannot reactivate it', async () => {
    const store = new ReferenceStore();

    await store.ingestAtomic(verified(payload(4, { eventType: 'schedule.cancelled' })));
    assert.equal((await store.ingestAtomic(verified(payload(3, { scheduledAt: '2027-01-06T01:00:00Z' })))).outcome, 'stale');

    const state = store.states.get('ci_activity:42');
    assert.equal(state.version, 4);
    assert.equal(state.active, false);
    assert.equal(state.cancelReason, 'completed');
    assert.equal(state.recipientEmail, null);
});

test('F: one event UUID with conflicting content is rejected by persistence', async () => {
    const store = new ReferenceStore();
    const eventId = randomUUID();

    await store.ingestAtomic(verified(payload(1, { eventId })));
    const result = await store.ingestAtomic(verified(payload(1, { eventId, scheduledAt: '2027-01-06T01:00:00Z' })));

    assert.equal(result.outcome, 'conflict');
    assert.equal(result.reason, 'event_uuid_payload_conflict');
});

test('G: one idempotency key with conflicting content is rejected by persistence', async () => {
    const store = new ReferenceStore();
    const idempotencyKey = 'ci_activity:42:v1';

    await store.ingestAtomic(verified(payload(1, { idempotencyKey })));
    const result = await store.ingestAtomic(verified(payload(1, {
        eventId: randomUUID(),
        idempotencyKey,
        scheduledAt: '2027-01-06T01:00:00Z',
    })));

    assert.equal(result.outcome, 'conflict');
    assert.equal(result.reason, 'idempotency_key_payload_conflict');
});

test('H reference semantics serialize simultaneous versions and retain the greatest version', async () => {
    const store = new ReferenceStore();

    await Promise.all([
        store.ingestAtomic(verified(payload(6))),
        store.ingestAtomic(verified(payload(5))),
    ]);

    assert.equal(store.states.get('ci_activity:42').version, 6);
});

test('I: invalid signatures and stale timestamps never reach persistence', async () => {
    const store = new ReferenceStore();
    const event = payload(1);

    assert.throws(() => verified(event, { signature: `sha256=${'0'.repeat(64)}` }), (error) => error instanceof ContractError && error.code === 'signature_invalid');
    assert.throws(() => verified(event, { timestamp: NOW - 301 }), (error) => error instanceof ContractError && error.code === 'timestamp_outside_tolerance');
    assert.equal(store.eventsByUuid.size, 0);
    assert.equal(store.states.size, 0);
    assert.equal(store.audit.length, 0);
});

test('raw bytes are mandatory and malformed, oversized, or unexpected payloads are rejected', () => {
    const event = payload(1);
    const signed = request(event);
    assert.throws(() => validateIncoming({ ...signed, rawBody: signed.rawBody.toString('utf8') }), /unmodified_raw_body_unavailable/);

    const unexpected = { ...event, assigned_ci_id: 999 };
    assert.throws(() => verified(unexpected), /payload_unexpected_fields/);

    const oversized = Buffer.alloc(MAX_BODY_BYTES + 1, 0x20);
    assert.throws(() => validateIncoming({ ...signed, rawBody: oversized }), /body_size_invalid/);

    const invalidJson = Buffer.from('{"schema":', 'utf8');
    const timestamp = String(NOW);
    const invalidJsonSignature = `sha256=${createHmac('sha256', SECRET).update(Buffer.from(`${timestamp}.`)).update(invalidJson).digest('hex')}`;
    assert.throws(() => validateIncoming({
        ...signed,
        rawBody: invalidJson,
        headers: { ...signed.headers, 'x-cims-signature': invalidJsonSignature },
    }), /body_must_be_utf8_json/);
});

test('required headers and timestamp syntax are validated before persistence', () => {
    const event = payload(1);
    const signed = request(event);
    const missing = { ...signed.headers };
    delete missing['x-cims-event-id'];

    assert.throws(() => validateIncoming({ ...signed, headers: missing }), /header_x-cims-event-id_missing_or_repeated/);
    assert.throws(() => validateIncoming(request(event, { timestamp: 'not-a-timestamp' })), /timestamp_malformed/);
});

test('UTC and Manila trigger timestamps must describe the same schedule instant', () => {
    const event = payload(1);
    event.reminder.trigger_at_local = '2027-01-05T10:00:00+08:00';

    assert.throws(() => verified(event), /trigger_timestamps_disagree/);
});

test('date-only schedules accept only 08:00 Asia/Manila on the scheduled local date', () => {
    const event = payload(1, {
        specificTime: false,
        scheduledAt: '2027-01-04T16:00:00Z',
        triggerAt: '2027-01-05T00:00:00Z',
    });

    assert.equal(verified(event).payload.reminder.trigger_at_local, '2027-01-05T08:00:00.000+08:00');
    event.reminder.trigger_at_local = '2027-01-05T09:00:00+08:00';
    assert.throws(() => verified(event), /trigger_timestamps_disagree|date_only_trigger_time_invalid/);
});

test('exact scheduled time is retained as the trigger instant', () => {
    const event = payload(1, {
        specificTime: true,
        scheduledAt: '2027-01-05T01:23:45Z',
        triggerAt: '2027-01-05T01:23:45Z',
    });

    const accepted = verified(event);
    assert.equal(accepted.payload.reminder.trigger_at, '2027-01-05T01:23:45Z');
    assert.equal(accepted.payload.reminder.trigger_at_local, '2027-01-05T09:23:45.000+08:00');
});

test('invalid recipient addresses are rejected before persistence', () => {
    const event = payload(1);
    event.recipient.email = 'not-an-email-address';

    assert.throws(() => verified(event), /recipient_email_invalid/);
});

test('recipient is accepted only from the creator-shaped payload and no assigned CI field is allowed', () => {
    const accepted = verified(payload(1));
    assert.deepEqual(accepted.payload.recipient, { user_id: 7, email: 'creator@example.test' });

    const event = payload(1);
    event.recipient.assigned_ci_id = 99;
    assert.throws(() => verified(event), /recipient_unexpected_fields/);
});
