// BRBI CIMS Phase 2A-6, Workflow B (run once for each item): Gmail success-output interpretation.
// "sent" requires a recognisable Gmail API message resource. Anything else, including the
// pass-through item a DISABLED Gmail node emits, is "uncertain" and is never retried
// automatically.
const claim = $('Confirm Claims').item.json;
const response = $json;
const sent = typeof response.id === 'string'
    && typeof response.threadId === 'string'
    && Array.isArray(response.labelIds)
    && response.labelIds.includes('SENT');

return {
    json: {
        action: 'record',
        subject_key: claim.subject_key,
        schedule_version: claim.schedule_version,
        event_id: claim.event_id,
        recipient_user_id: claim.recipient_user_id,
        delivery_key: claim.delivery_key,
        claim_token: claim.claim_token,
        outcome: sent ? 'sent' : 'uncertain',
        next_claim: claim.claim_token,
        next_attempt_at: null,
        attempt_number: claim.attempt_number,
        provider_message_id: sent ? response.id : '',
        response_status: null,
        error_code: sent ? '' : 'gmail_response_unrecognized',
        attempted_at: new Date().toISOString(),
    },
};
