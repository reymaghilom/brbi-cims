'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const schemaPath = path.resolve(__dirname, '../../docs/n8n/phase-2a-3/data-tables.schema.json');
const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
const supportedTypes = new Set(['string', 'number', 'boolean', 'date']);

test('Phase 2A-3 declares exactly the three approved logical Data Tables with supported types', () => {
    assert.deepEqual(schema.tables.map((table) => table.name), [
        'cims_reminder_schedules',
        'cims_reminder_events',
        'cims_reminder_delivery_logs',
    ]);

    for (const table of schema.tables) {
        assert.ok(table.columns.length > 0);
        assert.equal(new Set(table.columns.map((column) => column.name)).size, table.columns.length);
        for (const column of table.columns) {
            assert.match(column.name, /^[a-zA-Z][a-zA-Z0-9_]*$/);
            assert.ok(supportedTypes.has(column.type), `${table.name}.${column.name} uses ${column.type}`);
            assert.ok(!['id', 'createdAt', 'updatedAt'].includes(column.name));
        }
    }
});

test('Get then Insert permits duplicate event identities under interleaving', () => {
    const rows = [];
    const eventId = '018f65ee-34c0-7ec8-8f67-b06f22b51f9a';

    // Both executions observe absence before either execution writes.
    const firstSaw = rows.some((row) => row.event_id === eventId);
    const secondSaw = rows.some((row) => row.event_id === eventId);
    assert.equal(firstSaw, false);
    assert.equal(secondSaw, false);

    rows.push({ event_id: eventId });
    rows.push({ event_id: eventId });
    assert.equal(rows.filter((row) => row.event_id === eventId).length, 2);
});

test('Get then version-check then Upsert permits a delayed older write to replace a newer write', () => {
    const state = { subject_key: 'ci_activity:42', schedule_version: 1 };

    // Executions for v2 and v3 both read v1 and independently decide that they may write.
    const versionSeenByTwo = state.schedule_version;
    const versionSeenByThree = state.schedule_version;
    assert.ok(2 > versionSeenByTwo);
    assert.ok(3 > versionSeenByThree);

    state.schedule_version = 3;
    state.schedule_version = 2;
    assert.equal(state.schedule_version, 2);
});

test('an append-only ledger avoids overwrites but cannot select one authoritative conflicting identity', () => {
    const rows = [
        { event_id: 'same-id', idempotency_key: 'ci_activity:42:v4', schedule_version: 4, fingerprint: 'aaa' },
        { event_id: 'same-id', idempotency_key: 'ci_activity:42:v4', schedule_version: 4, fingerprint: 'bbb' },
    ];

    const greatestVersion = Math.max(...rows.map((row) => row.schedule_version));
    const candidates = rows.filter((row) => row.schedule_version === greatestVersion);
    assert.equal(candidates.length, 2);
    assert.notEqual(candidates[0].fingerprint, candidates[1].fingerprint);
});
