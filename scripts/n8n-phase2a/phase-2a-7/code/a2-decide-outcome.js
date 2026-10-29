// BRBI CIMS Phase 2A-7, Workflow A "Decide Outcome": runs on a FRESH read taken after the
// version-guarded write (the Data Table update's own result is not trusted: on SQLite n8n
// selects the affected rows before the UPDATE runs).
//
// Invariant relied on by Workflow B: for each subject only the HIGHEST schedule_version row(s)
// count. So whenever no row at or above this version exists, inserting this version is always
// correct, even if a concurrent execution inserts an older or newer row at the same moment.
const event = $('Verify Signature and Validate').first().json;
const seen = new Set();
const rows = $input.all()
    .map((item) => item.json)
    .filter((row) => row && row.subject_key === event.subject_key && !seen.has(String(row.id)) && seen.add(String(row.id)));
const base = { event_id: event.event_id, schedule_version: event.schedule_version };

const atVersion = rows.filter((row) => Number(row.schedule_version) === event.schedule_version);
const newer = rows.filter((row) => Number(row.schedule_version) > event.schedule_version);

if (atVersion.some((row) => row.last_event_id !== event.event_id)) {
    // Laravel issues exactly one event per version; a different event id here is a contract bug.
    return [{ json: { ...base, route: 'reject', http_status: 409, code: 'version_identity_conflict' } }];
}
if (atVersion.length > 0) {
    // This execution's guarded write stamped last_execution_id; otherwise it was a replay.
    const mine = atVersion.some((row) => row.last_execution_id === event.last_execution_id);
    return [{ json: { ...base, route: 'done', outcome: mine ? 'applied' : 'duplicate' } }];
}
if (newer.length > 0) {
    // An equal-or-newer version is already stored: this older event changes nothing.
    return [{ json: { ...base, route: 'done', outcome: 'stale' } }];
}

return [{ json: { ...base, route: 'insert', outcome: 'applied' } }];
