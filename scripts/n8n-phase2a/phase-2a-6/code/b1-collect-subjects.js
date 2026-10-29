// BRBI CIMS Phase 2A-6, Workflow B: one item per due subject (rows may be duplicated).
const MAX_SUBJECTS_PER_RUN = 25;
const keys = [];
for (const item of $input.all()) {
    const key = item.json && item.json.subject_key;
    if (typeof key === 'string' && key !== '' && !keys.includes(key)) {
        keys.push(key);
    }
}

return keys.slice(0, MAX_SUBJECTS_PER_RUN).map((subjectKey) => ({ json: { subject_key: subjectKey } }));
