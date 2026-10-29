'use strict';

// Phase 2A-7: builds the two SIMPLIFIED importable n8n 2.43.8 workflows from the reviewed Code
// sources in ./code and docs/n8n/phase-2a-7/data-tables.schema.json. Node types, versions and
// parameter names follow the n8n@2.43.8 tag of packages/nodes-base. Both workflows are emitted
// INACTIVE, without credential ids or pinned data, and with execution-data saving off.
// The Phase 2A-6 artifacts and generator are kept unchanged as the backup.
//
//   node scripts/n8n-phase2a/phase-2a-7/build-workflows.cjs          (write)
//   node scripts/n8n-phase2a/phase-2a-7/build-workflows.cjs --check  (fail if artifacts drifted)

const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../../..');
const OUT_DIR = path.join(ROOT, 'docs/n8n/phase-2a-7/workflows');
const SCHEMA = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/n8n/phase-2a-7/data-tables.schema.json'), 'utf8'));

const SCHEDULES = 'cims_reminder_schedules';
const EVENTS = 'cims_reminder_events';
const LOGS = 'cims_reminder_delivery_logs';
const VERIFY = 'Verify Signature and Validate';
const DECIDE = 'Decide Outcome';

const code = (file) => fs.readFileSync(path.join(__dirname, 'code', file), 'utf8');
const columnsOf = (table) => SCHEMA.tables.find((candidate) => candidate.name === table).columns;
const table = (name) => ({ __rl: true, mode: 'name', value: name });
const ref = (node, field) => `={{ $('${node}').first().json.${field} }}`;
const json = (field) => `={{ $json.${field} }}`;
const now = '={{ $now.toUTC().toISO() }}';
const has = (object, name) => Object.prototype.hasOwnProperty.call(object, name);

function columns(tableName, values) {
    const known = columnsOf(tableName);
    for (const name of Object.keys(values)) {
        if (!known.some((column) => column.name === name)) {
            throw new Error(`${tableName} has no column ${name}`);
        }
    }

    return {
        mappingMode: 'defineBelow',
        value: values,
        matchingColumns: [],
        schema: known.filter((column) => has(values, column.name)).map((column) => ({
            id: column.name,
            displayName: column.name,
            required: false,
            defaultMatch: false,
            display: true,
            type: column.type,
            canBeUsedToMatch: true,
            removed: false,
        })),
        attemptToConvertTypes: false,
        convertFieldsToString: false,
    };
}
const conditions = (list) => ({ conditions: list.map(([keyName, condition, keyValue]) => ({ keyName, condition, keyValue })) });
const filter = (id, leftValue, rightValue, operator) => ({
    options: { caseSensitive: true, leftValue: '', typeValidation: 'strict', version: 2 },
    conditions: [{ id, leftValue, rightValue, operator }],
    combinator: 'and',
});
const stringEquals = { type: 'string', operation: 'equals' };
const ifEquals = (left, right) => ({ conditions: filter(`${left}=${right}`, left, right, stringEquals), options: {} });
const ifTrue = (left) => ({ conditions: filter(`${left}:true`, left, '', { type: 'boolean', operation: 'true', singleValue: true }), options: {} });

function workflow(name, idPrefix, nodeList, links, description) {
    const nodes = nodeList.map((node, index) => ({
        parameters: node.parameters,
        id: `${idPrefix}-${String(index + 1).padStart(12, '0')}`,
        name: node.name,
        type: node.type,
        typeVersion: node.typeVersion,
        position: [index * 240, 0],
        ...(node.notes ? { notes: node.notes } : {}),
        ...node.extra,
    }));
    const names = new Set(nodes.map((node) => node.name));
    if (names.size !== nodes.length) {
        throw new Error(`duplicate node name in ${name}`);
    }
    const connections = {};
    for (const [from, output, to] of links) {
        if (!names.has(from) || !names.has(to)) {
            throw new Error(`unknown connection ${from} -> ${to}`);
        }
        connections[from] ??= { main: [] };
        while (connections[from].main.length <= output) {
            connections[from].main.push([]);
        }
        connections[from].main[output].push({ node: to, type: 'main', index: 0 });
    }

    return {
        name,
        nodes,
        connections,
        active: false,
        settings: {
            executionOrder: 'v1',
            timezone: 'Asia/Manila',
            saveDataSuccessExecution: 'none',
            saveDataErrorExecution: 'none',
            saveManualExecutions: false,
            saveExecutionProgress: false,
            callerPolicy: 'workflowsFromSameOwner',
        },
        pinData: {},
        meta: { templateCredsSetupCompleted: false, brbiCimsPhase: '2A-7', description },
        tags: [],
    };
}

const dataTable = 'n8n-nodes-base.dataTable';
const codeNode = 'n8n-nodes-base.code';
const ifNode = 'n8n-nodes-base.if';
const respond = 'n8n-nodes-base.respondToWebhook';
// Webhook-path Data Table nodes: single event, always emit an item, and route storage errors to 503.
const storage = { alwaysOutputData: true, executeOnce: true, onError: 'continueErrorOutput' };
const hmac = (name, value, property) => ({
    name,
    type: 'n8n-nodes-base.crypto',
    typeVersion: 2,
    parameters: { action: 'hmac', type: 'SHA256', value, dataPropertyName: property, encoding: 'hex' },
});
const header = (name) => `String(($json.headers || {})['${name}'] || '')`;
const scheduleValues = Object.fromEntries(columnsOf(SCHEDULES).map((column) => [column.name, ref(VERIFY, column.name)]));

// ------------------------------------------------------------------ Workflow A: Schedule Receiver
const receiver = workflow('BRBI CIMS — Schedule Receiver', '00000000-2a7a-4000-8000', [
    {
        name: 'CIMS Schedule Webhook',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2.1,
        parameters: { httpMethod: 'POST', path: 'brbi-cims/reminder-events', responseMode: 'responseNode', options: { rawBody: true } },
        extra: { webhookId: '5f0c2a7e-6a61-4c1d-9b2e-2a7000000001' },
    },
    {
        name: 'Read Raw Body',
        type: 'n8n-nodes-base.extractFromFile',
        typeVersion: 1.1,
        parameters: { operation: 'text', binaryPropertyName: 'data', destinationKey: 'raw_body', options: { encoding: 'utf8', stripBOM: false, keepSource: 'json' } },
        // An unreadable body continues without raw_body and is rejected with 400 by validation.
        extra: { onError: 'continueRegularOutput' },
    },
    hmac('HMAC Expected Signature', `={{ ${header('x-cims-timestamp')} + '.' + String($json.raw_body || '') }}`, 'expected_hex'),
    hmac('Seal Expected Signature', "={{ 'sha256=' + $json.expected_hex }}", 'expected_mac'),
    hmac('Seal Supplied Signature', `={{ ${header('x-cims-signature')} }}`, 'supplied_mac'),
    { name: VERIFY, type: codeNode, typeVersion: 2, parameters: { jsCode: code('a1-verify-and-validate.js') } },
    { name: 'Event Valid?', type: ifNode, typeVersion: 2.2, parameters: ifTrue('={{ $json.ok }}') },
    {
        name: 'Guarded Schedule Write',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        notes: 'Single UPDATE ... WHERE subject_key = K AND schedule_version < V: an older event can never overwrite a newer one.',
        parameters: {
            resource: 'row',
            operation: 'update',
            dataTableId: table(SCHEDULES),
            matchType: 'allConditions',
            filters: conditions([
                ['subject_key', 'eq', ref(VERIFY, 'subject_key')],
                ['schedule_version', 'lt', ref(VERIFY, 'schedule_version')],
            ]),
            columns: columns(SCHEDULES, scheduleValues),
            options: {},
        },
    },
    {
        name: 'Read Schedule Rows',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        parameters: {
            resource: 'row',
            operation: 'get',
            dataTableId: table(SCHEDULES),
            matchType: 'allConditions',
            filters: conditions([['subject_key', 'eq', ref(VERIFY, 'subject_key')]]),
            returnAll: true,
        },
    },
    { name: DECIDE, type: codeNode, typeVersion: 2, parameters: { jsCode: code('a2-decide-outcome.js') } },
    {
        name: 'Route Outcome',
        type: 'n8n-nodes-base.switch',
        typeVersion: 3.2,
        parameters: {
            mode: 'rules',
            rules: {
                values: ['insert', 'done', 'reject'].map((route) => ({
                    conditions: filter(`route=${route}`, '={{ $json.route }}', route, stringEquals),
                    renameOutput: true,
                    outputKey: route,
                })),
            },
            options: {},
        },
    },
    {
        name: 'Insert Schedule Row',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        parameters: { resource: 'row', operation: 'insert', dataTableId: table(SCHEDULES), columns: columns(SCHEDULES, scheduleValues), options: {} },
    },
    {
        name: 'Record Event',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        parameters: {
            resource: 'row',
            operation: 'insert',
            dataTableId: table(EVENTS),
            columns: columns(EVENTS, {
                event_id: ref(VERIFY, 'event_id'),
                idempotency_key: ref(VERIFY, 'idempotency_key'),
                subject_key: ref(VERIFY, 'subject_key'),
                schedule_version: ref(VERIFY, 'schedule_version'),
                event_type: ref(VERIFY, 'event_type'),
                processing_status: ref(DECIDE, 'outcome'),
                received_at: ref(VERIFY, 'received_at'),
            }),
            options: {},
        },
    },
    {
        name: 'Respond Accepted',
        type: respond,
        typeVersion: 1.1,
        parameters: {
            respondWith: 'json',
            responseBody: `={{ JSON.stringify({ result: $('${DECIDE}').first().json.outcome, event_id: $('${DECIDE}').first().json.event_id }) }}`,
            options: { responseCode: 200 },
        },
    },
    {
        name: 'Respond Storage Failure',
        type: respond,
        typeVersion: 1.1,
        notes: 'Never acknowledge an event whose persistence failed: Laravel keeps it pending and retries.',
        parameters: { respondWith: 'json', responseBody: '={{ JSON.stringify({ result: "rejected", code: "storage_unavailable" }) }}', options: { responseCode: 503 } },
    },
    {
        name: 'Respond Rejected',
        type: respond,
        typeVersion: 1.1,
        parameters: { respondWith: 'json', responseBody: '={{ JSON.stringify({ result: "rejected", code: $json.code }) }}', options: { responseCode: '={{ $json.http_status }}' } },
    },
], [
    ['CIMS Schedule Webhook', 0, 'Read Raw Body'],
    ['Read Raw Body', 0, 'HMAC Expected Signature'],
    ['HMAC Expected Signature', 0, 'Seal Expected Signature'],
    ['Seal Expected Signature', 0, 'Seal Supplied Signature'],
    ['Seal Supplied Signature', 0, VERIFY],
    [VERIFY, 0, 'Event Valid?'],
    ['Event Valid?', 0, 'Guarded Schedule Write'],
    ['Event Valid?', 1, 'Respond Rejected'],
    ['Guarded Schedule Write', 0, 'Read Schedule Rows'],
    ['Guarded Schedule Write', 1, 'Respond Storage Failure'],
    ['Read Schedule Rows', 0, DECIDE],
    ['Read Schedule Rows', 1, 'Respond Storage Failure'],
    [DECIDE, 0, 'Route Outcome'],
    ['Route Outcome', 0, 'Insert Schedule Row'],
    ['Route Outcome', 1, 'Record Event'],
    ['Route Outcome', 2, 'Respond Rejected'],
    ['Insert Schedule Row', 0, 'Record Event'],
    ['Insert Schedule Row', 1, 'Respond Storage Failure'],
    ['Record Event', 0, 'Respond Accepted'],
    ['Record Event', 1, 'Respond Storage Failure'],
], 'Laravel -> n8n only. HMAC via Crypto nodes + an n8n Crypto credential (assign at staging). Inactive.');

// ------------------------------------------------------------------ Workflow B: Email Reminder
const update = (name, filters, values) => ({
    name,
    type: dataTable,
    typeVersion: 1.1,
    extra: { alwaysOutputData: true },
    parameters: { resource: 'row', operation: 'update', dataTableId: table(SCHEDULES), matchType: 'allConditions', filters: conditions(filters), columns: columns(SCHEDULES, values), options: {} },
});

const reminder = workflow('BRBI CIMS — Email Reminder', '00000000-2a7b-4000-8000', [
    { name: 'Every 5 Minutes', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, parameters: { rule: { interval: [{ field: 'minutes', minutesInterval: 5 }] } } },
    {
        name: 'Find Due Reminders',
        type: dataTable,
        typeVersion: 1.1,
        parameters: {
            resource: 'row',
            operation: 'get',
            dataTableId: table(SCHEDULES),
            matchType: 'allConditions',
            filters: conditions([['status', 'eq', 'scheduled'], ['trigger_at_utc', 'lte', now]]),
            returnAll: false,
            limit: 50,
            orderBy: true,
            orderByColumn: 'trigger_at_utc',
            orderByDirection: 'ASC',
        },
    },
    {
        name: 'Read Subject Rows',
        type: dataTable,
        typeVersion: 1.1,
        notes: 'All rows of each due subject, so a newer (rescheduled or cancelled) version always wins.',
        parameters: { resource: 'row', operation: 'get', dataTableId: table(SCHEDULES), matchType: 'allConditions', filters: conditions([['subject_key', 'eq', json('subject_key')]]), returnAll: true },
    },
    { name: 'Select Deliverable', type: codeNode, typeVersion: 2, parameters: { jsCode: code('b1-select-deliverable.js') } },
    { name: 'Claim Action?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.action }}', 'claim') },
    // Phase 2B gate, placed BEFORE the claim: while closed the workflow never writes or sends.
    // Changing the left value to APPROVED_FOR_PHASE_2B requires separate approval.
    { name: 'Live Sending Approved?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('NOT_APPROVED', 'APPROVED_FOR_PHASE_2B') },
    update('Claim Reminder', [
        ['subject_key', 'eq', json('subject_key')],
        ['schedule_version', 'eq', json('schedule_version')],
        ['status', 'eq', 'scheduled'],
        ['delivery_status', 'eq', json('expected_delivery_status')],
        ['delivery_attempts', 'eq', json('expected_attempts')],
    ], { delivery_status: 'sending', delivery_claim: json('claim_token'), delivery_attempts: json('attempt_number'), delivery_updated_at: now }),
    {
        name: 'Read Claimed Rows',
        type: dataTable,
        typeVersion: 1.1,
        extra: { alwaysOutputData: true, executeOnce: true },
        parameters: { resource: 'row', operation: 'get', dataTableId: table(SCHEDULES), matchType: 'allConditions', filters: conditions([['delivery_status', 'eq', 'sending']]), returnAll: true },
    },
    { name: 'Confirm Claims', type: codeNode, typeVersion: 2, parameters: { jsCode: code('b2-confirm-claims.js') } },
    {
        name: 'Send Reminder Email',
        type: 'n8n-nodes-base.gmail',
        typeVersion: 2.1,
        parameters: {
            authentication: 'oAuth2',
            resource: 'message',
            operation: 'send',
            sendTo: json('recipient_email'),
            subject: json('email_subject'),
            emailType: 'text',
            message: json('email_body'),
            options: { appendAttribution: false },
        },
        extra: { disabled: true, onError: 'continueErrorOutput', retryOnFail: false, notes: 'Disabled, no credential. Phase 2B approval required before enabling.' },
    },
    { name: 'Interpret Gmail Outcome', type: codeNode, typeVersion: 2, parameters: { mode: 'runOnceForEachItem', jsCode: code('b3-interpret-gmail-outcome.js') } },
    update('Record Delivery Outcome', [
        ['subject_key', 'eq', json('subject_key')],
        ['schedule_version', 'eq', json('schedule_version')],
        ['delivery_status', 'eq', 'sending'],
        ['delivery_claim', 'eq', json('claim_token')],
    ], { delivery_status: json('outcome'), delivery_claim: json('next_claim'), delivery_next_attempt_at: json('next_attempt_at'), delivery_updated_at: now }),
    {
        name: 'Write Delivery Log',
        type: dataTable,
        typeVersion: 1.1,
        parameters: {
            resource: 'row',
            operation: 'insert',
            dataTableId: table(LOGS),
            columns: columns(LOGS, Object.fromEntries(columnsOf(LOGS).map((column) => [column.name, json(column.name)]))),
            options: {},
        },
    },
], [
    ['Every 5 Minutes', 0, 'Find Due Reminders'],
    ['Find Due Reminders', 0, 'Read Subject Rows'],
    ['Read Subject Rows', 0, 'Select Deliverable'],
    ['Select Deliverable', 0, 'Claim Action?'],
    ['Claim Action?', 0, 'Live Sending Approved?'],
    ['Claim Action?', 1, 'Record Delivery Outcome'],
    ['Claim Action?', 1, 'Write Delivery Log'],
    ['Live Sending Approved?', 0, 'Claim Reminder'],
    ['Claim Reminder', 0, 'Read Claimed Rows'],
    ['Read Claimed Rows', 0, 'Confirm Claims'],
    ['Confirm Claims', 0, 'Send Reminder Email'],
    ['Send Reminder Email', 0, 'Interpret Gmail Outcome'],
    ['Send Reminder Email', 1, 'Interpret Gmail Outcome'],
    ['Interpret Gmail Outcome', 0, 'Record Delivery Outcome'],
    ['Interpret Gmail Outcome', 0, 'Write Delivery Log'],
], 'Due reminders. Live sending gated off before any claim; Gmail node disabled with no credential. Inactive.');

const artifacts = {
    'brbi-cims-schedule-receiver.json': receiver,
    'brbi-cims-email-reminder.json': reminder,
};

if (require.main === module) {
    const check = process.argv.includes('--check');
    fs.mkdirSync(OUT_DIR, { recursive: true });
    let drift = false;
    for (const [file, content] of Object.entries(artifacts)) {
        const target = path.join(OUT_DIR, file);
        const serialized = `${JSON.stringify(content, null, 2)}\n`;
        if (check) {
            if (!fs.existsSync(target) || fs.readFileSync(target, 'utf8') !== serialized) {
                drift = true;
                console.error(`DRIFT: ${file}`);
            }
        } else {
            fs.writeFileSync(target, serialized);
            console.log(`wrote ${path.relative(ROOT, target)}`);
        }
    }
    process.exitCode = drift ? 1 : 0;
}

module.exports = { artifacts, OUT_DIR };
