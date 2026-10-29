// BRBI CIMS Phase 2A-6, Workflow A: pre-check the raw request BEFORE any HMAC work.
// Plain JavaScript only: no module loading, no Node built-ins, no environment variables. The signing secret is never
// visible here; the Crypto nodes that follow read it from an n8n Crypto credential.
const MAX_BODY_BYTES = 16 * 1024;
const TOLERANCE_SECONDS = 300;

function utf8Length(text) {
    let bytes = 0;
    for (const character of text) {
        const point = character.codePointAt(0);
        bytes += point < 0x80 ? 1 : point < 0x800 ? 2 : point < 0x10000 ? 3 : 4;
    }

    return bytes;
}

const reject = (httpStatus, code) => [{ json: { ok: false, http_status: httpStatus, code } }];
const request = $input.first().json;
const headers = request.headers && typeof request.headers === 'object' ? request.headers : {};
const raw = typeof request.raw_body === 'string' ? request.raw_body : null;

if (raw === null) {
    return reject(400, 'raw_body_unavailable');
}
const size = utf8Length(raw);
if (size === 0 || size > MAX_BODY_BYTES) {
    return reject(413, 'body_size_invalid');
}

// Node.js joins a repeated custom header with ", ", which then fails the strict formats below.
const single = (name) => {
    const value = headers[name];

    return typeof value === 'string' && value.length > 0 && value.length <= 256 ? value : null;
};
const signature = single('x-cims-signature');
const timestamp = single('x-cims-timestamp');
const eventId = single('x-cims-event-id');
const idempotencyKey = single('idempotency-key');

if (signature === null || timestamp === null || eventId === null || idempotencyKey === null) {
    return reject(401, 'authentication_headers_missing');
}
if (!/^\d{1,12}$/.test(timestamp)) {
    return reject(401, 'timestamp_malformed');
}
if (Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > TOLERANCE_SECONDS) {
    return reject(401, 'timestamp_outside_tolerance');
}
if (!/^sha256=[0-9a-f]{64}$/.test(signature)) {
    return reject(401, 'signature_malformed');
}

// Canonical signed string: "{timestamp}.{exact raw body}". The body is never re-serialized.
return [{
    json: {
        ok: true,
        signed_string: `${timestamp}.${raw}`,
        supplied_signature: signature,
        header_event_id: eventId,
        header_idempotency_key: idempotencyKey,
        raw_body: raw,
    },
}];
