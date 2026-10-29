'use strict';

// REFERENCE-MODEL interpreter for the Phase 2A-6 workflow JSON. It is NOT n8n.
//
// It executes the committed workflow artifacts node by node: the real Code-node sources (in a
// separate VM context with a controllable clock and no require/$env), real HMAC/SHA-256 for the
// Crypto nodes, and an in-memory Data Table whose operations are individually atomic — exactly
// the guarantee n8n 2.43.8 gives (each Data Table get/insert/update is one SQL statement), and
// nothing more. Each execution is a generator that yields before every node, so tests can
// interleave concurrent executions at node granularity.
//
// Only the node types/options the two workflows use are modelled. Real-instance behaviour must
// still be verified on staging (see docs/n8n/phase-2a-6/README.md).

const { createHash, createHmac } = require('node:crypto');
const vm = require('node:vm');

const SYSTEM_COLUMNS = ['id', 'createdAt', 'updatedAt'];

class DataTableStore {
    constructor(schema, clock) {
        this.schema = new Map(schema.tables.map((table) => [table.name, new Map(table.columns.map((column) => [column.name, column.type]))]));
        this.rows = new Map(schema.tables.map((table) => [table.name, []]));
        this.nextId = 1;
        this.clock = clock;
        this.failures = [];
        this.log = [];
    }

    /** Make the next matching operation(s) throw, e.g. { table, operation, times }. */
    failNext(table, operation, times = 1) {
        this.failures.push({ table, operation, times });
    }

    maybeFail(table, operation) {
        const failure = this.failures.find((candidate) => candidate.table === table && candidate.operation === operation && candidate.times > 0);
        if (failure) {
            failure.times--;
            throw new Error(`simulated Data Table ${operation} failure on ${table}`);
        }
    }

    columns(table) {
        const columns = this.schema.get(table);
        if (!columns) {
            throw new Error(`Data table "${table}" does not exist`);
        }

        return columns;
    }

    coerce(table, column, value) {
        const type = this.columns(table).get(column);
        if (type === undefined) {
            throw new Error(`unknown column name '${column}' in ${table}`);
        }
        if (value === null || value === undefined) {
            return null;
        }
        const ok = {
            string: typeof value === 'string',
            number: typeof value === 'number' && Number.isFinite(value),
            boolean: typeof value === 'boolean',
            date: (typeof value === 'string' || value instanceof Date) && Number.isFinite(new Date(value).getTime()),
        }[type];
        if (!ok) {
            throw new Error(`value '${String(value)}' does not match column type '${type}' (${table}.${column})`);
        }

        return type === 'date' ? new Date(value).toISOString() : value;
    }

    matches(table, row, filter) {
        const results = filter.filters.map(({ columnName, condition, value }) => {
            const type = SYSTEM_COLUMNS.includes(columnName) ? (columnName === 'id' ? 'number' : 'date') : this.columns(table).get(columnName);
            if (type === undefined) {
                throw new Error(`Filter validation failed: Column(s) "${columnName}" do not exist`);
            }
            const stored = row[columnName];
            if (value === null || value === undefined || value === '') {
                if (condition === 'eq') return stored === null || (type === 'string' && stored === '');
                if (condition === 'neq') return !(stored === null || (type === 'string' && stored === ''));
            }
            if (stored === null || stored === undefined) {
                return false;
            }
            const normalise = (input) => (type === 'date' ? Date.parse(input) : type === 'number' ? Number(input) : type === 'boolean' ? input === true || input === 'true' : String(input));
            const left = normalise(stored);
            const right = normalise(value);
            switch (condition ?? 'eq') {
                case 'eq': return left === right;
                case 'neq': return left !== right;
                case 'lt': return left < right;
                case 'lte': return left <= right;
                case 'gt': return left > right;
                case 'gte': return left >= right;
                default: throw new Error(`unsupported condition ${condition}`);
            }
        });

        return filter.type === 'and' ? results.every(Boolean) : results.some(Boolean);
    }

    get(table, filter, { limit = 50, returnAll = false, orderBy = null } = {}) {
        this.maybeFail(table, 'get');
        let rows = this.rows.get(table).filter((row) => filter.filters.length === 0 || this.matches(table, row, filter));
        if (orderBy) {
            const [column, direction] = orderBy;
            rows = [...rows].sort((a, b) => (a[column] < b[column] ? -1 : a[column] > b[column] ? 1 : 0) * (direction === 'DESC' ? -1 : 1));
        }
        if (!returnAll) {
            rows = rows.slice(0, limit);
        }
        this.log.push({ op: 'get', table, count: rows.length });

        return rows.map((row) => ({ ...row }));
    }

    insert(table, values) {
        this.maybeFail(table, 'insert');
        const now = new Date(this.clock()).toISOString();
        const row = { id: this.nextId++, createdAt: now, updatedAt: now };
        for (const column of this.columns(table).keys()) {
            row[column] = null;
        }
        for (const [column, value] of Object.entries(values)) {
            row[column] = this.coerce(table, column, value);
        }
        this.rows.get(table).push(row);
        this.log.push({ op: 'insert', table, id: row.id });

        return { ...row };
    }

    /** One atomic UPDATE ... WHERE filter, like n8n's single-statement updateRows. */
    update(table, filter, values) {
        this.maybeFail(table, 'update');
        if (filter.filters.length === 0) {
            throw new Error('At least one condition is required');
        }
        const coerced = Object.fromEntries(Object.entries(values).map(([column, value]) => [column, this.coerce(table, column, value)]));
        const updated = [];
        for (const row of this.rows.get(table)) {
            if (this.matches(table, row, filter)) {
                Object.assign(row, coerced, { updatedAt: new Date(this.clock()).toISOString() });
                updated.push({ ...row });
            }
        }
        this.log.push({ op: 'update', table, count: updated.length });

        return updated;
    }

    all(table) {
        return this.rows.get(table).map((row) => ({ ...row }));
    }
}

class WorkflowError extends Error {}

/** IF v2 / Switch v3 filter with typeValidation "strict": a wrong left-value type is an error. */
function filterPasses(filterValue) {
    const results = filterValue.conditions.map(({ leftValue, rightValue, operator }) => {
        if (operator.type === 'boolean' && operator.operation === 'true') {
            if (typeof leftValue !== 'boolean') throw new WorkflowError(`Wrong type: '${leftValue}' is not a boolean`);
            return leftValue === true;
        }
        if (operator.type === 'string' && operator.operation === 'equals') {
            if (typeof leftValue !== 'string') throw new WorkflowError(`Wrong type: '${leftValue}' is not a string`);
            return leftValue === rightValue;
        }
        throw new WorkflowError(`unsupported filter operator ${JSON.stringify(operator)}`);
    });

    return filterValue.combinator === 'or' ? results.some(Boolean) : results.every(Boolean);
}

function makeEnvironment({ store, clock, credentials = {}, gmail = null, executionId = '1' }) {
    return { store, clock, credentials, gmail, executionId };
}

/**
 * Runs one execution of `workflow` as an async generator (yields a node name before each node). `trigger` is the list of trigger items.
 * Returns { response, error, runData, sentEmails }.
 */
async function* execute(workflow, trigger, env) {
    const nodes = new Map(workflow.nodes.map((node) => [node.name, node]));
    const runData = new Map();
    const result = { response: null, error: null, runData, sentEmails: [] };
    const start = workflow.nodes.find((node) => /Trigger$|\.webhook$/.test(node.type) || node.type.endsWith('scheduleTrigger'));

    const nodeAccessor = (name) => {
        const runs = runData.get(name);
        const latest = runs ? runs[runs.length - 1] : null;
        const main = latest ? (latest[0] ?? []) : [];

        return {
            get isExecuted() {
                return Boolean(runs);
            },
            first() {
                if (!main[0]) throw new WorkflowError(`node "${name}" has no output`);
                return main[0];
            },
            all() {
                return main;
            },
            itemFrom(current) {
                for (let cursor = current; cursor; cursor = cursor.__parent) {
                    if (cursor.__node === name) return cursor;
                }
                throw new WorkflowError(`no paired item for "${name}"`);
            },
        };
    };
    const nowShim = () => ({ toUTC() { return this; }, toISO: () => new Date(env.clock()).toISOString() });

    const evaluate = (value, item) => {
        if (Array.isArray(value)) return value.map((entry) => evaluate(entry, item));
        if (value && typeof value === 'object') {
            return Object.fromEntries(Object.entries(value).map(([key, entry]) => [key, evaluate(entry, item)]));
        }
        if (typeof value !== 'string' || !value.startsWith('=')) return value;
        const template = value.slice(1);
        const run = (expression) => new Function('$json', '$', '$now', '$execution', `return (${expression});`)(
            item ? item.json : {},
            (name) => {
                const accessor = nodeAccessor(name);
                return Object.defineProperty(accessor, 'item', { get: () => accessor.itemFrom(item) });
            },
            nowShim(),
            { id: env.executionId },
        );
        const whole = template.match(/^\{\{([\s\S]+)\}\}$/);
        if (whole && !whole[1].includes('}}')) return run(whole[1]);

        return template.replace(/\{\{([\s\S]+?)\}\}/g, (_, expression) => {
            const resolved = run(expression);
            return typeof resolved === 'object' ? JSON.stringify(resolved) : String(resolved);
        });
    };

    const child = (parent, json, extra = {}) => ({ json, __parent: parent, ...extra });

    const runCode = async (node, items) => {
        const FakeDate = class extends Date {
            constructor(...args) {
                if (args.length === 0) super(env.clock()); else super(...args);
            }
            static now() {
                return env.clock();
            }
        };
        const perItem = node.parameters.mode === 'runOnceForEachItem';
        const source = node.parameters.jsCode;
        const invoke = (current) => {
            const context = vm.createContext({
                $input: { all: () => items, first: () => items[0], item: current },
                $json: current ? current.json : undefined,
                $: (name) => {
                    const accessor = nodeAccessor(name);
                    return Object.defineProperty(accessor, 'item', { get: () => accessor.itemFrom(current) });
                },
                $execution: { id: env.executionId },
                Date: FakeDate,
                URL,
            });
            return vm.runInContext(`(async () => {\n${source}\n})()`, context, { timeout: 1000 });
        };
        if (perItem) {
            const out = [];
            for (const current of items) {
                const returned = await invoke(current);
                out.push(child(current, returned.json));
            }
            return [out];
        }
        const returned = await invoke(null);
        if (!Array.isArray(returned)) throw new WorkflowError(`${node.name} must return an array`);
        return [returned.map((entry, index) => child(items[Math.min(index, items.length - 1)], entry.json))];
    };

    const dataTableFilter = (node, item) => ({
        type: node.parameters.matchType === 'allConditions' ? 'and' : 'or',
        filters: ((evaluate(node.parameters.filters, item) || {}).conditions || []).map((condition) => ({
            columnName: condition.keyName,
            condition: condition.condition ?? 'eq',
            value: condition.keyValue,
        })),
    });

    const runNode = async (node, items) => {
        switch (node.type) {
            case 'n8n-nodes-base.webhook':
            case 'n8n-nodes-base.scheduleTrigger':
                return [items];
            case 'n8n-nodes-base.extractFromFile':
                return [items.map((item) => {
                    const binary = item.binary && item.binary[node.parameters.binaryPropertyName];
                    if (!binary) throw new WorkflowError('This operation expects the node\'s input data to contain a binary file');
                    const text = Buffer.from(binary.data, 'base64').toString('utf8');
                    return child(item, { ...item.json, [node.parameters.destinationKey]: text });
                })];
            case 'n8n-nodes-base.code':
                return runCode(node, items);
            case 'n8n-nodes-base.crypto':
                return [items.map((item) => {
                    const params = evaluate(node.parameters, item);
                    let digest;
                    if (params.action === 'hmac') {
                        const secret = env.credentials.crypto && env.credentials.crypto.hmacSecret;
                        if (!secret) throw new WorkflowError('No HMAC secret set in credentials.');
                        digest = createHmac(params.type.toLowerCase(), secret).update(params.value).digest(params.encoding);
                    } else {
                        digest = createHash(params.type.toLowerCase()).update(params.value).digest(params.encoding);
                    }
                    return child(item, { ...item.json, [params.dataPropertyName]: digest });
                })];
            case 'n8n-nodes-base.if': {
                const outputs = [[], []];
                for (const item of items) {
                    outputs[filterPasses(evaluate(node.parameters, item).conditions) ? 0 : 1].push(child(item, item.json));
                }
                return outputs;
            }
            case 'n8n-nodes-base.switch': {
                // Rules mode, first matching rule only (allMatchingOutputs off), no fallback output.
                const rules = node.parameters.rules.values;
                const outputs = rules.map(() => []);
                for (const item of items) {
                    const resolved = evaluate(node.parameters, item).rules.values;
                    const index = resolved.findIndex((rule) => filterPasses(rule.conditions));
                    if (index >= 0) outputs[index].push(child(item, item.json));
                }
                return outputs;
            }
            case 'n8n-nodes-base.dataTable': {
                const p = node.parameters;
                const tableName = p.dataTableId.value;
                const out = [];
                for (const item of node.executeOnce ? items.slice(0, 1) : items) {
                    if (p.operation === 'get') {
                        const rows = env.store.get(tableName, dataTableFilter(node, item), {
                            limit: p.limit ?? 50,
                            returnAll: p.returnAll === true,
                            orderBy: p.orderBy ? [p.orderByColumn, p.orderByDirection] : null,
                        });
                        out.push(...rows.map((row) => child(item, row)));
                    } else if (p.operation === 'insert') {
                        out.push(child(item, env.store.insert(tableName, evaluate(p.columns.value, item))));
                    } else if (p.operation === 'update') {
                        out.push(...env.store.update(tableName, dataTableFilter(node, item), evaluate(p.columns.value, item)).map((row) => child(item, row)));
                    } else {
                        throw new WorkflowError(`unsupported Data Table operation ${p.operation}`);
                    }
                }
                return [out];
            }
            case 'n8n-nodes-base.gmail': {
                if (node.disabled) {
                    // n8n semantics: a disabled node passes its input items through unchanged.
                    return [items.map((item) => child(item, item.json))];
                }
                const outputs = [[], []];
                for (const item of items) {
                    const params = evaluate(node.parameters, item);
                    if (!env.gmail) throw new WorkflowError('Gmail credentials are not set');
                    const outcome = env.gmail(params);
                    if (outcome.error) {
                        if (node.onError !== 'continueErrorOutput') throw new WorkflowError(outcome.error.message);
                        outputs[1].push(child(item, { ...item.json, error: outcome.error }));
                    } else {
                        result.sentEmails.push({ to: params.sendTo, subject: params.subject, message: params.message });
                        outputs[0].push(child(item, outcome.json));
                    }
                }
                return outputs;
            }
            case 'n8n-nodes-base.respondToWebhook': {
                const item = items[0];
                const params = evaluate(node.parameters, item);
                if (result.response === null) {
                    result.response = { status: Number(params.options.responseCode ?? 200), body: JSON.parse(params.responseBody) };
                }
                return [items];
            }
            default:
                throw new WorkflowError(`node type ${node.type} is not modelled`);
        }
    };

    const stack = [{ name: start.name, items: trigger }];
    while (stack.length > 0) {
        const { name, items } = stack.pop();
        const node = nodes.get(name);
        yield name;

        let outputs;
        try {
            outputs = await runNode(node, items);
        } catch (error) {
            if (node.onError === 'continueErrorOutput') {
                outputs = [[], items.map((item) => child(item, { error: { message: error.message } }))];
            } else if (node.onError === 'continueRegularOutput') {
                outputs = [items.map((item) => child(item, { error: { message: error.message } }))];
            } else {
                result.error = { node: name, message: error.message };
                break;
            }
        }
        if (node.alwaysOutputData && outputs.every((output) => !output || output.length === 0)) {
            outputs[0] = [child(items[0], {})];
        }
        for (const output of outputs) {
            for (const item of output || []) item.__node = name;
        }
        if (!runData.has(name)) runData.set(name, []);
        runData.get(name).push(outputs);

        const next = [];
        const links = (workflow.connections[name] || {}).main || [];
        links.forEach((targets, outputIndex) => {
            const outItems = outputs[outputIndex] || [];
            if (outItems.length === 0) return;
            for (const target of targets) next.push({ name: target.node, items: outItems });
        });
        for (let index = next.length - 1; index >= 0; index--) stack.push(next[index]);
    }

    // Webhook in "responseNode" mode: a failed execution without a response is an HTTP 500.
    if (start.type === 'n8n-nodes-base.webhook' && result.response === null) {
        result.response = { status: 500, body: { message: 'Error in workflow' } };
    }

    return result;
}

module.exports = { DataTableStore, execute, makeEnvironment, WorkflowError };
