// BRBI CIMS Phase 2A-7, Workflow B "Select Deliverable": decide per subject from ALL of its rows
// (input: every row of every subject that has a due row; rows may repeat and are de-duplicated).
//  - Only the highest schedule_version is authoritative; a lower-version duplicate is ignored.
//  - If the authoritative version is cancelled, nothing is sent.
//  - "sent" and "uncertain" are never retried automatically.
//  - "failed" (a definitive provider rejection) is retried at most MAX_ATTEMPTS times.
//  - A "sending" claim older than STALE_CLAIM_MINUTES has an unknown outcome -> "uncertain".
const MAX_ATTEMPTS = 3;
const STALE_CLAIM_MINUTES = 30;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const now = Date.now();
const runToken = `${$execution.id}:${now}`;

const bySubject = new Map();
const seen = new Set();
for (const item of $input.all()) {
    const row = item.json;
    if (!row || typeof row.subject_key !== 'string' || seen.has(String(row.id))) {
        continue;
    }
    seen.add(String(row.id));
    if (!bySubject.has(row.subject_key)) {
        bySubject.set(row.subject_key, []);
    }
    bySubject.get(row.subject_key).push(row);
}

const output = [];
for (const [subjectKey, rows] of bySubject) {
    const version = Math.max(...rows.map((row) => Number(row.schedule_version)));
    const current = rows.filter((row) => Number(row.schedule_version) === version);
    if (current.some((row) => row.status !== 'scheduled')) {
        continue;
    }

    const states = current.map((row) => row.delivery_status);
    if (states.includes('sent') || states.includes('uncertain') || states.includes('skipped')) {
        continue;
    }

    const row = current[0];
    if (!(Date.parse(row.trigger_at_utc) <= now)) {
        continue;
    }

    const common = {
        subject_key: subjectKey,
        schedule_version: version,
        event_id: row.last_event_id,
        recipient_user_id: Number(row.recipient_user_id),
        delivery_key: `${subjectKey}:v${version}`,
    };

    const sending = current.find((candidate) => candidate.delivery_status === 'sending');
    if (sending) {
        if (now - Date.parse(sending.delivery_updated_at) > STALE_CLAIM_MINUTES * 60 * 1000) {
            output.push({
                json: {
                    ...common,
                    action: 'record',
                    claim_token: sending.delivery_claim,
                    outcome: 'uncertain',
                    next_claim: sending.delivery_claim,
                    next_attempt_at: null,
                    attempt_number: Number(sending.delivery_attempts) || 0,
                    provider_message_id: '',
                    response_status: null,
                    error_code: 'claim_expired_without_result',
                    attempted_at: new Date(now).toISOString(),
                },
            });
        }
        continue;
    }

    if (typeof row.recipient_email !== 'string' || !EMAIL.test(row.recipient_email)) {
        continue;
    }

    const attempts = Number(row.delivery_attempts) || 0;
    const retryDue = row.delivery_status === 'failed'
        && attempts < MAX_ATTEMPTS
        && (row.delivery_next_attempt_at === null || row.delivery_next_attempt_at === '' || Date.parse(row.delivery_next_attempt_at) <= now);
    if (row.delivery_status !== 'pending' && !retryDue) {
        continue;
    }

    output.push({
        json: {
            ...common,
            action: 'claim',
            claim_token: `${runToken}:${subjectKey}`,
            expected_delivery_status: row.delivery_status,
            expected_attempts: attempts,
            attempt_number: attempts + 1,
            recipient_email: row.recipient_email,
            subject_label: row.subject_label,
            activities_url: row.activities_url,
            trigger_at_manila: row.trigger_at_manila,
            has_specific_time: row.has_specific_time === true,
        },
    });
}

return output;
