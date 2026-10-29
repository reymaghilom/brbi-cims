'use strict';

// Builds the two importable n8n 2.43.8 workflow JSON artifacts from the reviewed Code-node sources
// in ./code and the Data Table schema. Node types/versions/parameter names were taken from the
// n8n@2.43.8 tag of packages/nodes-base. Both workflows are emitted INACTIVE, without credential
// ids, without pinned data, and with execution-data saving off.
//
//   node scripts/n8n-phase2a/phase-2a-6/build-workflows.cjs          (write)
//   node scripts/n8n-phase2a/phase-2a-6/build-workflows.cjs --check  (fail if artifacts drifted)

const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../../..');
const OUT_DIR = path.join(ROOT, 'docs/n8n/phase-2a-6/workflows');
const SCHEMA = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/n8n/phase-2a-6/data-tables.schema.json'), 'utf8'));

const SCHEDULES = 'cims_reminder_schedules';
const EVENTS = 'cims_reminder_events';
const LOGS = 'cims_reminder_delivery_logs';
const VERIFY = 'Verify Signature and Validate';

const code = (file, replacements = {}) => {
    let source = fs.readFileSync(path.join(__dirname, 'code', file), 'utf8');
    for (const [token, value] of Object.entries(replacements)) {
        source = source.split(token).join(value);
    }

    return source;
};
const columnsOf = (table) => SCHEMA.tables.find((candidate) => candidate.name === table).columns;
const table = (name) => ({ __rl: true, mode: 'name', value: name });
const ref = (node, field) => `={{ $('${node}').first().json.${field} }}`;

function columns(tableName, values) {
    const known = columnsOf(tableName);
    for (const key of Object.keys(values)) {
        if (!known.some((column) => column.name === key)) {
            throw new Error(`${tableName} has no column ${key}`);
        }
    }

    return {
        mappingMode: 'defineBelow',
        value: values,
        matchingColumns: [],
        schema: known.filter((column) => key(values, column.name)).map((column) => ({
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
function key(object, name) {
    return Object.prototype.hasOwnProperty.call(object, name);
}
function conditions(list) {
    return { conditions: list.map(([keyName, condition, keyValue]) => ({ keyName, condition, keyValue })) };
}
function ifEquals(left, right) {
    return {
        conditions: {
            options: { caseSensitive: true, leftValue: '', typeValidation: 'strict', version: 2 },
            conditions: [{ id: `${left}=${right}`, leftValue: left, rightValue: right, operator: { type: 'string', operation: 'equals' } }],
            combinator: 'and',
        },
        options: {},
    };
}
function ifTrue(left) {
    return {
        conditions: {
            options: { caseSensitive: true, leftValue: '', typeValidation: 'strict', version: 2 },
            conditions: [{ id: `${left}:true`, leftValue: left, rightValue: '', operator: { type: 'boolean', operation: 'true', singleValue: true } }],
            combinator: 'and',
        },
        options: {},
    };
}

function workflow(name, nodeList, links, description) {
    const nodes = nodeList.map((node, index) => ({
        parameters: node.parameters,
        id: `00000000-2a60-4000-8000-${String(index + 1).padStart(12, '0')}`.replace('2a60-4000', name.includes('Ingestion') ? '2a6a-4000' : '2a6b-4000'),
        name: node.name,
        type: node.type,
        typeVersion: node.typeVersion,
        position: [index * 240, (node.row ?? 0) * 200],
        ...node.extra,
    }));
    const names = new Set(nodes.map((node) => node.name));
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
        meta: { templateCredsSetupCompleted: false, brbiCimsPhase: '2A-6', description },
        tags: [],
    };
}

const storage = { alwaysOutputData: true, executeOnce: true, onError: 'continueErrorOutput' };
const dataTable = 'n8n-nodes-base.dataTable';
const codeNode = 'n8n-nodes-base.code';
const ifNode = 'n8n-nodes-base.if';
const respond = 'n8n-nodes-base.respondToWebhook';

const scheduleValues = Object.fromEntries(columnsOf(SCHEDULES).map((column) => [column.name, ref(VERIFY, column.name)]));
const guardedWrite = (name) => ({
    name,
    type: dataTable,
    typeVersion: 1.1,
    extra: storage,
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
});
const readSchedule = (name) => ({
    name,
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
});
const crypto = (name, action, value, property) => ({
    name,
    type: 'n8n-nodes-base.crypto',
    typeVersion: 2,
    parameters: { action, type: 'SHA256', value, dataPropertyName: property, encoding: 'hex' },
});

const ingestion = workflow('BRBI CIMS — Reminder Event Ingestion', [
    {
        name: 'CIMS Reminder Webhook',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2.1,
        parameters: { httpMethod: 'POST', path: 'brbi-cims/reminder-events', responseMode: 'responseNode', options: { rawBody: true } },
        extra: { webhookId: '5f0c2a6e-6a61-4c1d-9b2e-2a6000000001' },
    },
    {
        name: 'Read Raw Body',
        type: 'n8n-nodes-base.extractFromFile',
        typeVersion: 1.1,
        parameters: { operation: 'text', binaryPropertyName: 'data', destinationKey: 'raw_body', options: { encoding: 'utf8', stripBOM: false, keepSource: 'json' } },
        extra: { onError: 'continueRegularOutput' },
    },
    { name: 'Precheck Request', type: codeNode, typeVersion: 2, parameters: { jsCode: code('a1-precheck-request.js') } },
    { name: 'Precheck Passed?', type: ifNode, typeVersion: 2.2, parameters: ifTrue('={{ $json.ok }}') },
    crypto('HMAC Expected Signature', 'hmac', '={{ $json.signed_string }}', 'expected_hex'),
    crypto('Seal Expected Signature', 'hmac', '=sha256={{ $json.expected_hex }}', 'expected_mac'),
    crypto('Seal Supplied Signature', 'hmac', '={{ $json.supplied_signature }}', 'supplied_mac'),
    crypto('Fingerprint Body', 'hash', '={{ $json.raw_body }}', 'payload_fingerprint'),
    { name: VERIFY, type: codeNode, typeVersion: 2, parameters: { jsCode: code('a2-verify-and-validate.js') } },
    { name: 'Event Valid?', type: ifNode, typeVersion: 2.2, parameters: ifTrue('={{ $json.ok }}') },
    {
        name: 'Find Event Identity',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        parameters: {
            resource: 'row',
            operation: 'get',
            dataTableId: table(EVENTS),
            matchType: 'anyCondition',
            filters: conditions([
                ['event_id', 'eq', ref(VERIFY, 'event_id')],
                ['idempotency_key', 'eq', ref(VERIFY, 'idempotency_key')],
            ]),
            returnAll: false,
            limit: 20,
        },
    },
    { name: 'Classify Identity', type: codeNode, typeVersion: 2, parameters: { jsCode: code('a3-classify-identity.js') } },
    { name: 'Identity Conflict?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.route }}', 'reject') },
    { name: 'Already Processed?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.route }}', 'duplicate') },
    { name: 'New Event?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.route }}', 'new') },
    {
        name: 'Insert Event Ledger',
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
                payload_fingerprint: ref(VERIFY, 'payload_fingerprint'),
                processing_status: 'received',
                failure_code: '',
                received_at: ref(VERIFY, 'received_at'),
            }),
            options: {},
        },
    },
    guardedWrite('Guarded Schedule Write'),
    readSchedule('Read Schedule Rows'),
    { name: 'Decide After Write', type: codeNode, typeVersion: 2, parameters: { jsCode: code('a4-decide-schedule.js', { __AFTER_INSERT__: 'false' }) } },
    { name: 'Insert Needed?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.route }}', 'insert') },
    {
        name: 'Insert Schedule Row',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        parameters: { resource: 'row', operation: 'insert', dataTableId: table(SCHEDULES), columns: columns(SCHEDULES, scheduleValues), options: {} },
    },
    guardedWrite('Converge Schedule Rows'),
    readSchedule('Final Read Schedule Rows'),
    { name: 'Decide After Insert', type: codeNode, typeVersion: 2, parameters: { jsCode: code('a4-decide-schedule.js', { __AFTER_INSERT__: 'true' }) } },
    { name: 'Settled?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.route }}', 'done') },
    { name: 'Accepted Result', type: codeNode, typeVersion: 2, parameters: { jsCode: 'return [$input.first()];\n' } },
    {
        name: 'Record Event Outcome',
        type: dataTable,
        typeVersion: 1.1,
        extra: storage,
        parameters: {
            resource: 'row',
            operation: 'update',
            dataTableId: table(EVENTS),
            matchType: 'allConditions',
            filters: conditions([['event_id', 'eq', ref('Accepted Result', 'event_id')]]),
            columns: columns(EVENTS, { processing_status: ref('Accepted Result', 'outcome'), failure_code: '' }),
            options: {},
        },
    },
    {
        name: 'Respond Accepted',
        type: respond,
        typeVersion: 1.1,
        parameters: {
            respondWith: 'json',
            responseBody: "={{ JSON.stringify({ result: $('Accepted Result').first().json.outcome, event_id: $('Accepted Result').first().json.event_id }) }}",
            options: { responseCode: 200 },
        },
    },
    {
        name: 'Respond Duplicate',
        type: respond,
        typeVersion: 1.1,
        parameters: { respondWith: 'json', responseBody: "={{ JSON.stringify({ result: 'duplicate', event_id: $json.event_id }) }}", options: { responseCode: 200 } },
    },
    {
        name: 'Storage Failure',
        type: codeNode,
        typeVersion: 2,
        parameters: { jsCode: "// Never acknowledge an event whose persistence failed: Laravel keeps it pending and retries.\nreturn [{ json: { http_status: 503, code: 'storage_unavailable' } }];\n" },
    },
    {
        name: 'Respond Rejected',
        type: respond,
        typeVersion: 1.1,
        parameters: { respondWith: 'json', responseBody: "={{ JSON.stringify({ result: 'rejected', code: $json.code }) }}", options: { responseCode: '={{ $json.http_status }}' } },
    },
], [
    ['CIMS Reminder Webhook', 0, 'Read Raw Body'],
    ['Read Raw Body', 0, 'Precheck Request'],
    ['Precheck Request', 0, 'Precheck Passed?'],
    ['Precheck Passed?', 0, 'HMAC Expected Signature'],
    ['Precheck Passed?', 1, 'Respond Rejected'],
    ['HMAC Expected Signature', 0, 'Seal Expected Signature'],
    ['Seal Expected Signature', 0, 'Seal Supplied Signature'],
    ['Seal Supplied Signature', 0, 'Fingerprint Body'],
    ['Fingerprint Body', 0, VERIFY],
    [VERIFY, 0, 'Event Valid?'],
    ['Event Valid?', 0, 'Find Event Identity'],
    ['Event Valid?', 1, 'Respond Rejected'],
    ['Find Event Identity', 0, 'Classify Identity'],
    ['Find Event Identity', 1, 'Storage Failure'],
    ['Classify Identity', 0, 'Identity Conflict?'],
    ['Identity Conflict?', 0, 'Respond Rejected'],
    ['Identity Conflict?', 1, 'Already Processed?'],
    ['Already Processed?', 0, 'Respond Duplicate'],
    ['Already Processed?', 1, 'New Event?'],
    ['New Event?', 0, 'Insert Event Ledger'],
    ['New Event?', 1, 'Guarded Schedule Write'],
    ['Insert Event Ledger', 0, 'Guarded Schedule Write'],
    ['Insert Event Ledger', 1, 'Storage Failure'],
    ['Guarded Schedule Write', 0, 'Read Schedule Rows'],
    ['Guarded Schedule Write', 1, 'Storage Failure'],
    ['Read Schedule Rows', 0, 'Decide After Write'],
    ['Read Schedule Rows', 1, 'Storage Failure'],
    ['Decide After Write', 0, 'Insert Needed?'],
    ['Insert Needed?', 0, 'Insert Schedule Row'],
    ['Insert Needed?', 1, 'Settled?'],
    ['Insert Schedule Row', 0, 'Converge Schedule Rows'],
    ['Insert Schedule Row', 1, 'Storage Failure'],
    ['Converge Schedule Rows', 0, 'Final Read Schedule Rows'],
    ['Converge Schedule Rows', 1, 'Storage Failure'],
    ['Final Read Schedule Rows', 0, 'Decide After Insert'],
    ['Final Read Schedule Rows', 1, 'Storage Failure'],
    ['Decide After Insert', 0, 'Settled?'],
    ['Settled?', 0, 'Accepted Result'],
    ['Settled?', 1, 'Respond Rejected'],
    ['Accepted Result', 0, 'Record Event Outcome'],
    ['Record Event Outcome', 0, 'Respond Accepted'],
    ['Record Event Outcome', 1, 'Storage Failure'],
    ['Storage Failure', 0, 'Respond Rejected'],
], 'One-way Laravel -> n8n ingestion. HMAC via Crypto nodes + Crypto credential (assign at staging). Inactive.');

const now = '={{ $now.toUTC().toISO() }}';
const json = (field) => `={{ $json.${field} }}`;
const perItemUpdate = (name, filters, values, extra = { alwaysOutputData: true }) => ({
    name,
    type: dataTable,
    typeVersion: 1.1,
    extra,
    parameters: { resource: 'row', operation: 'update', dataTableId: table(SCHEDULES), matchType: 'allConditions', filters: conditions(filters), columns: columns(SCHEDULES, values), options: {} },
});

const processing = workflow('BRBI CIMS — Due Reminder Processing', [
    {
        name: 'Every 5 Minutes',
        type: 'n8n-nodes-base.scheduleTrigger',
        typeVersion: 1.2,
        parameters: { rule: { interval: [{ field: 'minutes', minutesInterval: 5 }] } },
    },
    {
        name: 'Find Due Reminders',
        type: dataTable,
        typeVersion: 1.1,
        extra: { alwaysOutputData: true },
        parameters: {
            resource: 'row',
            operation: 'get',
            dataTableId: table(SCHEDULES),
            matchType: 'allConditions',
            filters: conditions([['status', 'eq', 'scheduled'], ['trigger_at_utc', 'lte', now]]),
            returnAll: false,
            limit: 200,
            orderBy: true,
            orderByColumn: 'trigger_at_utc',
            orderByDirection: 'ASC',
        },
    },
    { name: 'Collect Subjects', type: codeNode, typeVersion: 2, parameters: { jsCode: code('b1-collect-subjects.js') } },
    {
        name: 'Read Subject Rows',
        type: dataTable,
        typeVersion: 1.1,
        extra: { alwaysOutputData: true },
        parameters: { resource: 'row', operation: 'get', dataTableId: table(SCHEDULES), matchType: 'allConditions', filters: conditions([['subject_key', 'eq', json('subject_key')]]), returnAll: true },
    },
    { name: 'Select Deliverable', type: codeNode, typeVersion: 2, parameters: { jsCode: code('b2-select-deliverable.js') } },
    { name: 'Claim Action?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('={{ $json.action }}', 'claim') },
    perItemUpdate('Claim Reminder', [
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
    { name: 'Confirm Claims', type: codeNode, typeVersion: 2, parameters: { jsCode: code('b3-confirm-claims.js') } },
    // Phase 2B gate. Changing the left value to APPROVED_FOR_PHASE_2B requires separate approval.
    { name: 'Live Sending Approved?', type: ifNode, typeVersion: 2.2, parameters: ifEquals('NOT_APPROVED', 'APPROVED_FOR_PHASE_2B') },
    perItemUpdate('Release Claims', [
        ['subject_key', 'eq', json('subject_key')],
        ['schedule_version', 'eq', json('schedule_version')],
        ['delivery_status', 'eq', 'sending'],
        ['delivery_claim', 'eq', json('claim_token')],
    ], { delivery_status: json('expected_delivery_status'), delivery_claim: '', delivery_attempts: json('expected_attempts'), delivery_updated_at: now }),
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
    { name: 'Interpret Gmail Result', type: codeNode, typeVersion: 2, parameters: { mode: 'runOnceForEachItem', jsCode: code('b4-interpret-gmail-result.js') } },
    { name: 'Classify Gmail Error', type: codeNode, typeVersion: 2, parameters: { mode: 'runOnceForEachItem', jsCode: code('b5-classify-gmail-error.js') } },
    perItemUpdate('Record Delivery Outcome', [
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
    ['Find Due Reminders', 0, 'Collect Subjects'],
    ['Collect Subjects', 0, 'Read Subject Rows'],
    ['Read Subject Rows', 0, 'Select Deliverable'],
    ['Select Deliverable', 0, 'Claim Action?'],
    ['Claim Action?', 0, 'Claim Reminder'],
    ['Claim Action?', 1, 'Record Delivery Outcome'],
    ['Claim Action?', 1, 'Write Delivery Log'],
    ['Claim Reminder', 0, 'Read Claimed Rows'],
    ['Read Claimed Rows', 0, 'Confirm Claims'],
    ['Confirm Claims', 0, 'Live Sending Approved?'],
    ['Live Sending Approved?', 0, 'Send Reminder Email'],
    ['Live Sending Approved?', 1, 'Release Claims'],
    ['Send Reminder Email', 0, 'Interpret Gmail Result'],
    ['Send Reminder Email', 1, 'Classify Gmail Error'],
    ['Interpret Gmail Result', 0, 'Record Delivery Outcome'],
    ['Interpret Gmail Result', 0, 'Write Delivery Log'],
    ['Classify Gmail Error', 0, 'Record Delivery Outcome'],
    ['Classify Gmail Error', 0, 'Write Delivery Log'],
], 'Due reminder processing. Gmail node disabled with no credential; live sending gated off. Inactive.');

const artifacts = {
    'brbi-cims-reminder-event-ingestion.json': ingestion,
    'brbi-cims-due-reminder-processing.json': processing,
};

if (require.main === module) {
    const check = process.argv.includes('--check');
    fs.mkdirSync(OUT_DIR, { recursive: true });
    let drift = false;
    for (const [file, content] of Object.entries(artifacts)) {
        const target = path.join(OUT_DIR, file);
        const serialized = `${JSON.stringify(content, null, 2)}\n`;
        if (check) {
            const existing = fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
            if (existing !== serialized) {
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
