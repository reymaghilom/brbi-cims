'use strict';

class ReferenceStore {
    constructor() {
        this.eventsByUuid = new Map();
        this.eventsByIdempotencyKey = new Map();
        this.states = new Map();
        this.audit = [];
        this.queue = Promise.resolve();
    }

    ingestAtomic(validated) {
        const operation = this.queue.then(() => this.ingest(validated));
        this.queue = operation.catch(() => undefined);

        return operation;
    }

    ingest(validated) {
        const { payload, payloadHash, active, requestTimestamp, receivedAt } = validated;
        const byUuid = this.eventsByUuid.get(payload.event_id);
        const byKey = this.eventsByIdempotencyKey.get(payload.idempotency_key);

        if (byUuid && byUuid.payloadHash !== payloadHash) {
            return this.recordAudit(validated, 'conflict', 'event_uuid_payload_conflict');
        }
        if (byKey && byKey.payloadHash !== payloadHash) {
            return this.recordAudit(validated, 'conflict', 'idempotency_key_payload_conflict');
        }
        if (byUuid || byKey) {
            const sameEvent = byUuid && byKey && byUuid === byKey && byUuid.payloadHash === payloadHash;
            return this.recordAudit(validated, sameEvent ? 'duplicate' : 'conflict', sameEvent ? null : 'identity_pair_conflict');
        }

        const event = {
            eventUuid: payload.event_id,
            idempotencyKey: payload.idempotency_key,
            payloadHash,
            subjectKey: payload.reminder.key,
            version: payload.reminder.version,
        };
        this.eventsByUuid.set(event.eventUuid, event);
        this.eventsByIdempotencyKey.set(event.idempotencyKey, event);

        const previous = this.states.get(event.subjectKey);
        if (previous && previous.version >= event.version) {
            return this.recordAudit(validated, 'stale', null);
        }

        this.states.set(event.subjectKey, {
            subjectKey: event.subjectKey,
            subjectType: payload.reminder.subject_type,
            subjectId: payload.reminder.subject_id,
            ciActivityId: payload.reminder.ci_activity_id,
            version: event.version,
            active,
            eventType: payload.event_type,
            recipientUserId: payload.recipient.user_id,
            recipientEmail: active ? payload.recipient.email : previous?.recipientEmail ?? null,
            scheduledAt: payload.reminder.scheduled_at,
            triggerAt: payload.reminder.trigger_at,
            triggerAtLocal: payload.reminder.trigger_at_local,
            cancelReason: payload.reminder.cancel_reason,
            lastEventUuid: payload.event_id,
            lastIdempotencyKey: payload.idempotency_key,
            payloadHash,
            requestTimestamp,
            receivedAt,
        });

        return this.recordAudit(validated, 'applied', null);
    }

    recordAudit(validated, outcome, reason) {
        const entry = {
            eventUuid: validated.payload.event_id,
            idempotencyKey: validated.payload.idempotency_key,
            subjectKey: validated.payload.reminder.key,
            version: validated.payload.reminder.version,
            payloadHash: validated.payloadHash,
            outcome,
            reason,
            receivedAt: validated.receivedAt,
        };
        this.audit.push(entry);

        return entry;
    }
}

module.exports = { ReferenceStore };
