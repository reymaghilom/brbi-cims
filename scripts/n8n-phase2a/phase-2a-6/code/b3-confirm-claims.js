// BRBI CIMS Phase 2A-6, Workflow B: keep only candidates whose claim this execution now owns.
// The claim itself was one guarded UPDATE (status, version, delivery state and attempt count must
// all still match), so of two overlapping executions at most one can own a subject's claim.
const candidates = $('Select Deliverable').all().map((item) => item.json).filter((candidate) => candidate.action === 'claim');
const rows = $input.all().map((item) => item.json).filter((row) => row && typeof row.subject_key === 'string');

function when(candidate) {
    const date = String(candidate.trigger_at_manila).slice(0, 10);
    const time = String(candidate.trigger_at_manila).slice(11, 16);

    return candidate.has_specific_time ? `${date} ${time} (Asia/Manila)` : `${date} (Asia/Manila)`;
}

const output = [];
for (const candidate of candidates) {
    const owned = rows.some((row) => row.subject_key === candidate.subject_key
        && Number(row.schedule_version) === candidate.schedule_version
        && row.delivery_claim === candidate.claim_token);
    if (!owned) {
        continue;
    }

    // Generic wording only: subject_label is a generic activity label chosen by Laravel.
    output.push({
        json: {
            ...candidate,
            email_subject: `BRBI CIMS reminder: ${candidate.subject_label} scheduled ${when(candidate)}`,
            email_body: [
                'This is a reminder for a CI activity you created in BRBI CIMS.',
                '',
                `Activity: ${candidate.subject_label}`,
                `Scheduled: ${when(candidate)}`,
                '',
                `Open the activities list: ${candidate.activities_url}`,
                '',
                'This message was sent automatically. Sign in to BRBI CIMS for details.',
            ].join('\n'),
        },
    });
}

return output;
