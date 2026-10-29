// BRBI CIMS Phase 2A-6, Workflow B (run once for each item): Gmail error-output classification.
//  - A definite 4xx API rejection (except 408) means Gmail did not accept the message:
//    "failed", eligible for a bounded, delayed retry.
//  - Everything else (5xx, 408, timeouts, network resets, unknown errors) may or may not have
//    sent the message: "uncertain", NEVER retried automatically.
const MAX_ATTEMPTS = 3;
const RETRY_DELAY_MINUTES = 15;
const claim = $('Confirm Claims').item.json;
const error = ($json && $json.error) || {};

const candidates = [error.httpCode, error.status, error.statusCode];
const fromMessage = String(error.message || error.description || '').match(/\b([45]\d\d)\b/);
if (fromMessage) {
    candidates.push(fromMessage[1]);
}
const status = candidates.map(Number).find((value) => Number.isInteger(value) && value >= 100 && value <= 599) ?? null;
const definite = status !== null && status >= 400 && status < 500 && status !== 408;
const retryable = definite && claim.attempt_number < MAX_ATTEMPTS;
const now = Date.now();

return {
    json: {
        action: 'record',
        subject_key: claim.subject_key,
        schedule_version: claim.schedule_version,
        event_id: claim.event_id,
        recipient_user_id: claim.recipient_user_id,
        delivery_key: claim.delivery_key,
        claim_token: claim.claim_token,
        outcome: definite ? 'failed' : 'uncertain',
        next_claim: definite ? '' : claim.claim_token,
        next_attempt_at: retryable ? new Date(now + RETRY_DELAY_MINUTES * claim.attempt_number * 60 * 1000).toISOString() : null,
        attempt_number: claim.attempt_number,
        provider_message_id: '',
        response_status: status,
        error_code: definite ? `gmail_rejected_${status}` : 'gmail_outcome_uncertain',
        attempted_at: new Date(now).toISOString(),
    },
};
