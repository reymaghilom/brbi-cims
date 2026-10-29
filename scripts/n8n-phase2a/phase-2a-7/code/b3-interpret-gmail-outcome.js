// BRBI CIMS Phase 2A-7, Workflow B "Interpret Gmail Outcome" (run once for each item).
// Connected to BOTH Gmail outputs: an item carrying `error` came from the error output.
//
//  - "sent" only for a recognisable Gmail API message resource (id, threadId, labelIds incl. SENT).
//    Anything else on the success output, including the pass-through item a DISABLED Gmail node
//    emits, is "uncertain".
//  - A definite 4xx rejection (except 408) means Gmail did not accept the message: "failed",
//    retried at most MAX_ATTEMPTS times after a growing delay.
//  - 5xx, 408, timeouts, network resets and unknown errors may or may not have sent the
//    message: "uncertain", NEVER retried automatically.
const MAX_ATTEMPTS = 3;
const RETRY_DELAY_MINUTES = 15;
const claim = $('Confirm Claims').item.json;
const now = Date.now();

let outcome;
let status = null;
let providerMessageId = '';
let errorCode = '';
let nextAttemptAt = null;

if ($json && $json.error) {
    const error = $json.error;
    const fromMessage = String(error.message || error.description || '').match(/\b([45]\d\d)\b/);
    status = [error.httpCode, error.status, error.statusCode, fromMessage ? fromMessage[1] : null]
        .map(Number)
        .find((value) => Number.isInteger(value) && value >= 100 && value <= 599) ?? null;
    const definite = status !== null && status >= 400 && status < 500 && status !== 408;
    outcome = definite ? 'failed' : 'uncertain';
    errorCode = definite ? `gmail_rejected_${status}` : 'gmail_outcome_uncertain';
    if (definite && claim.attempt_number < MAX_ATTEMPTS) {
        nextAttemptAt = new Date(now + RETRY_DELAY_MINUTES * claim.attempt_number * 60 * 1000).toISOString();
    }
} else {
    const sent = $json
        && typeof $json.id === 'string'
        && typeof $json.threadId === 'string'
        && Array.isArray($json.labelIds)
        && $json.labelIds.includes('SENT');
    outcome = sent ? 'sent' : 'uncertain';
    providerMessageId = sent ? $json.id : '';
    errorCode = sent ? '' : 'gmail_response_unrecognized';
}

return {
    json: {
        action: 'record',
        subject_key: claim.subject_key,
        schedule_version: claim.schedule_version,
        event_id: claim.event_id,
        recipient_user_id: claim.recipient_user_id,
        delivery_key: claim.delivery_key,
        claim_token: claim.claim_token,
        outcome,
        // A failed attempt releases the claim so a later run may retry it.
        next_claim: outcome === 'failed' ? '' : claim.claim_token,
        next_attempt_at: nextAttemptAt,
        attempt_number: claim.attempt_number,
        provider_message_id: providerMessageId,
        response_status: status,
        error_code: errorCode,
        attempted_at: new Date(now).toISOString(),
    },
};
