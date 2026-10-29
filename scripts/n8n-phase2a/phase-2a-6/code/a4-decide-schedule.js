// BRBI CIMS Phase 2A-6, Workflow A: decide from a FRESH read after the version-guarded write.
// The Data Table update result is deliberately not trusted (on SQLite n8n selects the affected
// rows before the UPDATE runs); only this re-read is used.
const AFTER_INSERT = __AFTER_INSERT__;
const event = $('Verify Signature and Validate').first().json;
const seen = new Set();
const rows = $input.all()
    .map((item) => item.json)
    .filter((row) => row && row.subject_key === event.subject_key)
    .filter((row) => {
        const id = String(row.id);
        if (seen.has(id)) {
            return false;
        }
        seen.add(id);

        return true;
    });

if (rows.length === 0) {
    return [{
        json: AFTER_INSERT
            ? { route: 'reject', http_status: 503, code: 'schedule_row_not_persisted' }
            : { route: 'insert', event_id: event.event_id },
    }];
}

const newest = Math.max(...rows.map((row) => Number(row.schedule_version)));
if (newest >= event.schedule_version) {
    const applied = rows.some((row) => Number(row.schedule_version) === event.schedule_version && row.last_event_id === event.event_id);

    // "stale": an equal-or-newer version is already stored, so this older event changed nothing.
    return [{ json: { route: 'done', event_id: event.event_id, outcome: applied ? 'applied' : 'stale' } }];
}

// Only older rows exist: one was inserted concurrently after this event's guarded write. Do not
// acknowledge; Laravel retries the same event later and the guarded write then converges.
return [{ json: { route: 'reject', http_status: 503, code: 'concurrent_write_retry' } }];
