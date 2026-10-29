// BRBI CIMS Phase 2A-6, Workflow A: signature decision and strict brbi.cims.reminder.v1 schema.
// Plain JavaScript only: no module loading, no Node built-ins, no environment variables.
//
// Constant-time-equivalent comparison WITHOUT Code-node crypto: the Crypto nodes computed
// expected_mac = HMAC(secret, expected signature) and supplied_mac = HMAC(secret, supplied
// signature). Comparing those two digests ("double HMAC") leaks nothing usable through timing,
// because an attacker cannot predict either digest without the secret.

// Optional, non-secret staging setting: the only origin activities_url may point at, e.g.
// 'https://cims.example.org'. Empty accepts any http(s) URL (the body is already authenticated).
const ALLOWED_ACTIVITIES_ORIGIN = '';

const SCHEMA = 'brbi.cims.reminder.v1';
const SUBJECT_TYPES = ['ci_activity', 'bank_target', 'asset_target'];
const ACTIVE_EVENT_TYPES = ['schedule.created', 'schedule.changed'];
const CANCEL_REASONS = ['completed', 'unscheduled', 'deleted', 'recipient_unavailable'];
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/;
const MANILA = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+08:00$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MANILA_OFFSET_MS = 8 * 60 * 60 * 1000; // Asia/Manila has no daylight saving time.

const reject = (httpStatus, code) => [{ json: { ok: false, http_status: httpStatus, code } }];

function invalid(code) {
    const error = new Error(code);
    error.contractCode = code;
    throw error;
}
function check(condition, code) {
    if (!condition) {
        invalid(code);
    }
}
function plain(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function exactKeys(value, expected, path) {
    check(plain(value), `${path}_must_be_object`);
    const actual = Object.keys(value).sort();
    const wanted = [...expected].sort();
    check(actual.length === wanted.length && actual.every((key, index) => key === wanted[index]), `${path}_unexpected_fields`);
}
function positiveInteger(value, path) {
    check(Number.isSafeInteger(value) && value > 0, `${path}_must_be_positive_integer`);
}
function timestamp(value, pattern, path) {
    check(typeof value === 'string' && pattern.test(value) && Number.isFinite(Date.parse(value)), `${path}_invalid`);
}
function manila(isoUtc) {
    return `${new Date(Date.parse(isoUtc) + MANILA_OFFSET_MS).toISOString().slice(0, 19)}+08:00`;
}

const input = $input.first().json;
const expected = input.expected_mac;
if (!(typeof expected === 'string' && /^[0-9a-f]{64}$/.test(expected) && expected === input.supplied_mac)) {
    return reject(401, 'signature_invalid');
}

let payload;
try {
    payload = JSON.parse(input.raw_body);
} catch (error) {
    return reject(400, 'body_must_be_json');
}

try {
    exactKeys(payload, ['schema', 'event_id', 'idempotency_key', 'event_type', 'occurred_at', 'reminder', 'recipient', 'content'], 'payload');
    check(payload.schema === SCHEMA, 'schema_unsupported');
    check(typeof payload.event_id === 'string' && UUID.test(payload.event_id), 'event_id_invalid');
    check(payload.event_id === input.header_event_id, 'event_id_header_mismatch');
    check(typeof payload.idempotency_key === 'string' && payload.idempotency_key === input.header_idempotency_key, 'idempotency_key_header_mismatch');
    check(ACTIVE_EVENT_TYPES.includes(payload.event_type) || payload.event_type === 'schedule.cancelled', 'event_type_invalid');
    timestamp(payload.occurred_at, UTC, 'occurred_at');

    const reminder = payload.reminder;
    exactKeys(reminder, ['key', 'subject_type', 'subject_id', 'ci_activity_id', 'version', 'scheduled_at', 'has_specific_time', 'trigger_at', 'trigger_at_local', 'timezone', 'cancel_reason'], 'reminder');
    check(SUBJECT_TYPES.includes(reminder.subject_type), 'subject_type_invalid');
    positiveInteger(reminder.subject_id, 'subject_id');
    positiveInteger(reminder.ci_activity_id, 'ci_activity_id');
    positiveInteger(reminder.version, 'version');
    const subjectKey = `${reminder.subject_type}:${reminder.subject_id}`;
    check(reminder.key === subjectKey, 'reminder_key_invalid');
    check(payload.idempotency_key === `${subjectKey}:v${reminder.version}`, 'idempotency_key_invalid');
    check(reminder.timezone === 'Asia/Manila', 'timezone_invalid');

    const active = ACTIVE_EVENT_TYPES.includes(payload.event_type);
    if (active) {
        timestamp(reminder.scheduled_at, UTC, 'scheduled_at');
        timestamp(reminder.trigger_at, UTC, 'trigger_at');
        timestamp(reminder.trigger_at_local, MANILA, 'trigger_at_local');
        check(typeof reminder.has_specific_time === 'boolean', 'has_specific_time_invalid');
        check(reminder.cancel_reason === null, 'cancel_reason_must_be_null');
        check(Date.parse(reminder.trigger_at) === Date.parse(reminder.trigger_at_local), 'trigger_timestamps_disagree');
        if (reminder.has_specific_time) {
            check(Date.parse(reminder.scheduled_at) === Date.parse(reminder.trigger_at), 'specific_time_trigger_mismatch');
        } else {
            check(reminder.trigger_at_local.slice(11, 19) === '08:00:00', 'date_only_trigger_time_invalid');
            check(manila(reminder.scheduled_at).slice(0, 10) === reminder.trigger_at_local.slice(0, 10), 'date_only_trigger_date_invalid');
        }

        exactKeys(payload.recipient, ['user_id', 'email'], 'recipient');
        positiveInteger(payload.recipient.user_id, 'recipient_user_id');
        check(typeof payload.recipient.email === 'string' && payload.recipient.email.length <= 254 && EMAIL.test(payload.recipient.email), 'recipient_email_invalid');

        exactKeys(payload.content, ['subject_label', 'activities_url'], 'content');
        check(typeof payload.content.subject_label === 'string' && payload.content.subject_label.length >= 1 && payload.content.subject_label.length <= 100, 'subject_label_invalid');
        check(typeof payload.content.activities_url === 'string' && payload.content.activities_url.length <= 2048, 'activities_url_invalid');
        let url;
        try {
            url = new URL(payload.content.activities_url);
        } catch (error) {
            invalid('activities_url_invalid');
        }
        check(url.protocol === 'https:' || url.protocol === 'http:', 'activities_url_invalid');
        check(ALLOWED_ACTIVITIES_ORIGIN === '' || url.origin === ALLOWED_ACTIVITIES_ORIGIN, 'activities_url_origin_not_allowed');
    } else {
        check(reminder.scheduled_at === null, 'cancelled_scheduled_at_must_be_null');
        check(reminder.has_specific_time === null, 'cancelled_has_specific_time_must_be_null');
        check(reminder.trigger_at === null, 'cancelled_trigger_at_must_be_null');
        check(reminder.trigger_at_local === null, 'cancelled_trigger_at_local_must_be_null');
        check(CANCEL_REASONS.includes(reminder.cancel_reason), 'cancel_reason_invalid');
        exactKeys(payload.recipient, ['user_id'], 'recipient');
        check(payload.recipient.user_id === null || (Number.isSafeInteger(payload.recipient.user_id) && payload.recipient.user_id > 0), 'recipient_user_id_must_be_positive_integer');
        check(payload.content === null, 'cancelled_content_must_be_null');
    }

    const receivedAt = new Date().toISOString();

    // Flat, column-named output. A cancellation clears the email address (data minimisation).
    return [{
        json: {
            ok: true,
            event_id: payload.event_id,
            idempotency_key: payload.idempotency_key,
            event_type: payload.event_type,
            payload_fingerprint: input.payload_fingerprint,
            subject_key: subjectKey,
            subject_type: reminder.subject_type,
            subject_id: reminder.subject_id,
            ci_activity_id: reminder.ci_activity_id,
            schedule_version: reminder.version,
            recipient_user_id: payload.recipient.user_id,
            recipient_email: active ? payload.recipient.email : '',
            scheduled_at_utc: active ? reminder.scheduled_at : null,
            scheduled_at_manila: active ? manila(reminder.scheduled_at) : '',
            trigger_at_utc: active ? reminder.trigger_at : null,
            trigger_at_manila: active ? reminder.trigger_at_local : '',
            has_specific_time: active ? reminder.has_specific_time : false,
            status: active ? 'scheduled' : 'cancelled',
            cancel_reason: active ? '' : reminder.cancel_reason,
            subject_label: active ? payload.content.subject_label : '',
            activities_url: active ? payload.content.activities_url : '',
            last_event_id: payload.event_id,
            updated_at: receivedAt,
            delivery_status: active ? 'pending' : 'cancelled',
            delivery_claim: '',
            delivery_attempts: 0,
            delivery_next_attempt_at: null,
            delivery_updated_at: receivedAt,
            received_at: receivedAt,
        },
    }];
} catch (error) {
    if (error && typeof error.contractCode === 'string') {
        return reject(422, error.contractCode);
    }
    throw error;
}
