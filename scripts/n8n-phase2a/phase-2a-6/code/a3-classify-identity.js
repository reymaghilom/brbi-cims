// BRBI CIMS Phase 2A-6, Workflow A: replay / duplicate / conflict classification.
// Input: ledger rows whose event_id OR idempotency_key matches this event (possibly none).
// Data Tables have no unique constraint, so two simultaneous first deliveries can both see
// "new" and both insert a ledger row; that is harmless because the schedule write below is a
// version-guarded compare-and-set and is safe to repeat.
const TERMINAL = ['applied', 'stale'];
const event = $('Verify Signature and Validate').first().json;
const rows = $input.all().map((item) => item.json).filter((row) => row && typeof row.event_id === 'string');

const same = [];
const conflicts = [];
for (const row of rows) {
    const identical = row.event_id === event.event_id
        && row.idempotency_key === event.idempotency_key
        && row.payload_fingerprint === event.payload_fingerprint;
    (identical ? same : conflicts).push(row);
}

if (conflicts.length > 0) {
    // Same event id or idempotency key with different bytes: never apply, never acknowledge.
    return [{ json: { route: 'reject', http_status: 409, code: 'event_identity_conflict' } }];
}
if (same.some((row) => TERMINAL.includes(row.processing_status))) {
    return [{ json: { route: 'duplicate', event_id: event.event_id, outcome: 'duplicate' } }];
}

// "resume": a previous attempt recorded the event but did not finish; re-run the idempotent write.
return [{ json: { route: same.length > 0 ? 'resume' : 'new', event_id: event.event_id } }];
