'use strict';

const { createHash, createHmac, timingSafeEqual } = require('node:crypto');
const { TextDecoder } = require('node:util');

const SCHEMA = 'brbi.cims.reminder.v1';
const MAX_BODY_BYTES = 16 * 1024;
const DEFAULT_TOLERANCE_SECONDS = 300;
const SUBJECT_TYPES = new Set(['ci_activity', 'bank_target', 'asset_target']);
const ACTIVE_EVENT_TYPES = new Set(['schedule.created', 'schedule.changed']);
const CANCEL_REASONS = new Set(['completed', 'unscheduled', 'deleted', 'recipient_unavailable']);
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const UTC_TIMESTAMP_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/;
const MANILA_TIMESTAMP_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+08:00$/;

class ContractError extends Error {
    constructor(code, status = 400) {
        super(code);
        this.name = 'ContractError';
        this.code = code;
        this.status = status;
    }
}

function rejectUnless(condition, code, status = 400) {
    if (!condition) {
        throw new ContractError(code, status);
    }
}

function plainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function exactKeys(value, expected, path) {
    rejectUnless(plainObject(value), `${path}_must_be_object`);
    const actual = Object.keys(value).sort();
    const wanted = [...expected].sort();
    rejectUnless(actual.length === wanted.length && actual.every((key, index) => key === wanted[index]), `${path}_unexpected_fields`);
}

function positiveInteger(value, path) {
    rejectUnless(Number.isSafeInteger(value) && value > 0, `${path}_must_be_positive_integer`);
}

function nullablePositiveInteger(value, path) {
    if (value !== null) {
        positiveInteger(value, path);
    }
}

function timestamp(value, pattern, path) {
    rejectUnless(typeof value === 'string' && pattern.test(value) && Number.isFinite(Date.parse(value)), `${path}_invalid`);
}

function dateInManila(value) {
    const parts = new Intl.DateTimeFormat('en', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(value));
    const part = (type) => parts.find((candidate) => candidate.type === type).value;

    return `${part('year')}-${part('month')}-${part('day')}`;
}

function header(headers, name) {
    rejectUnless(plainObject(headers), 'headers_missing', 401);
    const target = name.toLowerCase();
    const matches = Object.entries(headers).filter(([key]) => key.toLowerCase() === target);
    rejectUnless(matches.length === 1, `header_${target}_missing_or_repeated`, 401);
    const value = matches[0][1];
    rejectUnless(typeof value === 'string' && value.length > 0, `header_${target}_invalid`, 401);

    return value;
}

function validateIncoming({ headers, rawBody, secret, nowSeconds, toleranceSeconds = DEFAULT_TOLERANCE_SECONDS, allowedActivitiesOrigin }) {
    rejectUnless(Buffer.isBuffer(rawBody), 'unmodified_raw_body_unavailable', 500);
    rejectUnless(rawBody.length > 0 && rawBody.length <= MAX_BODY_BYTES, 'body_size_invalid', 413);
    rejectUnless(typeof secret === 'string' && Buffer.byteLength(secret, 'utf8') >= 32, 'signing_secret_invalid', 500);
    rejectUnless(Number.isSafeInteger(nowSeconds) && nowSeconds > 0, 'server_time_invalid', 500);
    rejectUnless(Number.isSafeInteger(toleranceSeconds) && toleranceSeconds >= 1 && toleranceSeconds <= 900, 'timestamp_tolerance_invalid', 500);

    const signature = header(headers, 'x-cims-signature');
    const timestampHeader = header(headers, 'x-cims-timestamp');
    const eventHeader = header(headers, 'x-cims-event-id');
    const idempotencyHeader = header(headers, 'idempotency-key');

    rejectUnless(/^\d{1,12}$/.test(timestampHeader), 'timestamp_malformed', 401);
    const requestTimestamp = Number(timestampHeader);
    rejectUnless(Number.isSafeInteger(requestTimestamp) && Math.abs(nowSeconds - requestTimestamp) <= toleranceSeconds, 'timestamp_outside_tolerance', 401);
    rejectUnless(/^sha256=[0-9a-f]{64}$/.test(signature), 'signature_malformed', 401);

    const hmac = createHmac('sha256', secret)
        .update(Buffer.from(`${timestampHeader}.`, 'utf8'))
        .update(rawBody)
        .digest('hex');
    const expectedSignature = Buffer.from(`sha256=${hmac}`, 'ascii');
    const suppliedSignature = Buffer.from(signature, 'ascii');
    rejectUnless(
        suppliedSignature.length === expectedSignature.length && timingSafeEqual(suppliedSignature, expectedSignature),
        'signature_invalid',
        401,
    );

    let rawJson;
    let payload;
    try {
        rawJson = new TextDecoder('utf-8', { fatal: true }).decode(rawBody);
        payload = JSON.parse(rawJson);
    } catch {
        throw new ContractError('body_must_be_utf8_json');
    }

    exactKeys(payload, ['schema', 'event_id', 'idempotency_key', 'event_type', 'occurred_at', 'reminder', 'recipient', 'content'], 'payload');
    rejectUnless(payload.schema === SCHEMA, 'schema_unsupported');
    rejectUnless(typeof payload.event_id === 'string' && UUID_PATTERN.test(payload.event_id), 'event_id_invalid');
    rejectUnless(payload.event_id === eventHeader, 'event_id_header_mismatch');
    rejectUnless(typeof payload.idempotency_key === 'string' && payload.idempotency_key === idempotencyHeader, 'idempotency_key_header_mismatch');
    rejectUnless(ACTIVE_EVENT_TYPES.has(payload.event_type) || payload.event_type === 'schedule.cancelled', 'event_type_invalid');
    timestamp(payload.occurred_at, UTC_TIMESTAMP_PATTERN, 'occurred_at');

    exactKeys(payload.reminder, ['key', 'subject_type', 'subject_id', 'ci_activity_id', 'version', 'scheduled_at', 'has_specific_time', 'trigger_at', 'trigger_at_local', 'timezone', 'cancel_reason'], 'reminder');
    rejectUnless(SUBJECT_TYPES.has(payload.reminder.subject_type), 'subject_type_invalid');
    positiveInteger(payload.reminder.subject_id, 'subject_id');
    positiveInteger(payload.reminder.ci_activity_id, 'ci_activity_id');
    positiveInteger(payload.reminder.version, 'version');
    const expectedKey = `${payload.reminder.subject_type}:${payload.reminder.subject_id}`;
    rejectUnless(payload.reminder.key === expectedKey, 'reminder_key_invalid');
    rejectUnless(payload.idempotency_key === `${expectedKey}:v${payload.reminder.version}`, 'idempotency_key_invalid');
    rejectUnless(payload.reminder.timezone === 'Asia/Manila', 'timezone_invalid');

    const active = ACTIVE_EVENT_TYPES.has(payload.event_type);
    if (active) {
        timestamp(payload.reminder.scheduled_at, UTC_TIMESTAMP_PATTERN, 'scheduled_at');
        timestamp(payload.reminder.trigger_at, UTC_TIMESTAMP_PATTERN, 'trigger_at');
        timestamp(payload.reminder.trigger_at_local, MANILA_TIMESTAMP_PATTERN, 'trigger_at_local');
        rejectUnless(typeof payload.reminder.has_specific_time === 'boolean', 'has_specific_time_invalid');
        rejectUnless(payload.reminder.cancel_reason === null, 'cancel_reason_must_be_null');
        rejectUnless(Date.parse(payload.reminder.trigger_at) === Date.parse(payload.reminder.trigger_at_local), 'trigger_timestamps_disagree');
        if (payload.reminder.has_specific_time) {
            rejectUnless(Date.parse(payload.reminder.scheduled_at) === Date.parse(payload.reminder.trigger_at), 'specific_time_trigger_mismatch');
        } else {
            rejectUnless(payload.reminder.trigger_at_local.slice(11, 19) === '08:00:00', 'date_only_trigger_time_invalid');
            rejectUnless(dateInManila(payload.reminder.scheduled_at) === payload.reminder.trigger_at_local.slice(0, 10), 'date_only_trigger_date_invalid');
        }

        exactKeys(payload.recipient, ['user_id', 'email'], 'recipient');
        positiveInteger(payload.recipient.user_id, 'recipient_user_id');
        rejectUnless(
            typeof payload.recipient.email === 'string'
                && payload.recipient.email.length <= 254
                && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.recipient.email),
            'recipient_email_invalid',
        );

        exactKeys(payload.content, ['subject_label', 'activities_url'], 'content');
        rejectUnless(typeof payload.content.subject_label === 'string' && payload.content.subject_label.length >= 1 && payload.content.subject_label.length <= 100, 'subject_label_invalid');
        rejectUnless(typeof payload.content.activities_url === 'string' && payload.content.activities_url.length <= 2048, 'activities_url_invalid');
        let activitiesUrl;
        try {
            activitiesUrl = new URL(payload.content.activities_url);
        } catch {
            throw new ContractError('activities_url_invalid');
        }
        rejectUnless(['http:', 'https:'].includes(activitiesUrl.protocol), 'activities_url_invalid');
        if (allowedActivitiesOrigin) {
            rejectUnless(activitiesUrl.origin === allowedActivitiesOrigin, 'activities_url_origin_not_allowed');
        }
    } else {
        rejectUnless(payload.reminder.scheduled_at === null, 'cancelled_scheduled_at_must_be_null');
        rejectUnless(payload.reminder.has_specific_time === null, 'cancelled_has_specific_time_must_be_null');
        rejectUnless(payload.reminder.trigger_at === null, 'cancelled_trigger_at_must_be_null');
        rejectUnless(payload.reminder.trigger_at_local === null, 'cancelled_trigger_at_local_must_be_null');
        rejectUnless(CANCEL_REASONS.has(payload.reminder.cancel_reason), 'cancel_reason_invalid');
        exactKeys(payload.recipient, ['user_id'], 'recipient');
        nullablePositiveInteger(payload.recipient.user_id, 'recipient_user_id');
        rejectUnless(payload.content === null, 'cancelled_content_must_be_null');
    }

    return {
        payload,
        payloadHash: createHash('sha256').update(rawBody).digest('hex'),
        active,
        requestTimestamp,
        receivedAt: new Date(nowSeconds * 1000).toISOString(),
    };
}

module.exports = {
    ContractError,
    DEFAULT_TOLERANCE_SECONDS,
    MAX_BODY_BYTES,
    SCHEMA,
    validateIncoming,
};
