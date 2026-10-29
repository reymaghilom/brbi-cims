-- SUPERSEDED ALTERNATIVE — NOT THE APPROVED PHASE 2A-3 STORAGE.
-- Preserved for reference only; do not provision without separate approval.
-- BRBI CIMS Phase 2A receiver storage (PostgreSQL 13+).
-- Run only in a dedicated staging/production n8n-side database after review.
-- The n8n database credential should receive EXECUTE on the function and no direct table writes.

BEGIN;

CREATE SCHEMA IF NOT EXISTS cims_n8n;

CREATE TABLE IF NOT EXISTS cims_n8n.reminder_states (
    subject_key text PRIMARY KEY,
    subject_type text NOT NULL CHECK (subject_type IN ('ci_activity', 'bank_target', 'asset_target')),
    subject_id bigint NOT NULL CHECK (subject_id > 0),
    ci_activity_id bigint NOT NULL CHECK (ci_activity_id > 0),
    schedule_version integer NOT NULL CHECK (schedule_version > 0),
    is_active boolean NOT NULL,
    event_type text NOT NULL CHECK (event_type IN ('schedule.created', 'schedule.changed', 'schedule.cancelled')),
    recipient_user_id bigint CHECK (recipient_user_id > 0),
    recipient_email text,
    scheduled_at timestamptz,
    trigger_at timestamptz,
    trigger_at_local text,
    timezone text NOT NULL CHECK (timezone = 'Asia/Manila'),
    cancel_reason text CHECK (cancel_reason IS NULL OR cancel_reason IN ('completed', 'unscheduled', 'deleted', 'recipient_unavailable')),
    subject_label text,
    activities_url text,
    last_event_uuid uuid NOT NULL,
    last_idempotency_key text NOT NULL,
    last_payload_sha256 char(64) NOT NULL,
    last_occurred_at timestamptz NOT NULL,
    last_request_timestamp bigint NOT NULL,
    last_received_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (subject_type, subject_id),
    CHECK (
        (is_active AND recipient_email IS NOT NULL AND scheduled_at IS NOT NULL AND trigger_at IS NOT NULL AND cancel_reason IS NULL)
        OR
        (NOT is_active AND scheduled_at IS NULL AND trigger_at IS NULL AND cancel_reason IS NOT NULL)
    )
);

CREATE TABLE IF NOT EXISTS cims_n8n.ingested_events (
    event_uuid uuid PRIMARY KEY,
    idempotency_key text NOT NULL UNIQUE,
    payload_sha256 char(64) NOT NULL,
    subject_key text NOT NULL,
    subject_type text NOT NULL,
    subject_id bigint NOT NULL,
    ci_activity_id bigint NOT NULL,
    schedule_version integer NOT NULL,
    event_type text NOT NULL,
    occurred_at timestamptz NOT NULL,
    request_timestamp bigint NOT NULL,
    received_at timestamptz NOT NULL,
    outcome text NOT NULL CHECK (outcome IN ('processing', 'applied', 'stale')),
    processed_at timestamptz
);

CREATE INDEX IF NOT EXISTS ingested_events_subject_version_idx
    ON cims_n8n.ingested_events (subject_key, schedule_version DESC);

-- Metadata-only audit. Raw payloads, signatures, secrets, and sensitive headers are never stored.
CREATE TABLE IF NOT EXISTS cims_n8n.ingestion_audit (
    id bigserial PRIMARY KEY,
    event_uuid uuid NOT NULL,
    idempotency_key text NOT NULL,
    payload_sha256 char(64) NOT NULL,
    subject_key text NOT NULL,
    schedule_version integer NOT NULL,
    outcome text NOT NULL CHECK (outcome IN ('applied', 'duplicate', 'stale', 'conflict')),
    reason text,
    received_at timestamptz NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE INDEX IF NOT EXISTS ingestion_audit_received_idx
    ON cims_n8n.ingestion_audit (received_at DESC);

CREATE OR REPLACE FUNCTION cims_n8n.ingest_reminder_event(
    p_event_uuid uuid,
    p_idempotency_key text,
    p_payload_sha256 char(64),
    p_subject_key text,
    p_subject_type text,
    p_subject_id bigint,
    p_ci_activity_id bigint,
    p_schedule_version integer,
    p_event_type text,
    p_occurred_at timestamptz,
    p_request_timestamp bigint,
    p_received_at timestamptz,
    p_recipient_user_id bigint,
    p_recipient_email text,
    p_scheduled_at timestamptz,
    p_trigger_at timestamptz,
    p_trigger_at_local text,
    p_timezone text,
    p_cancel_reason text,
    p_subject_label text,
    p_activities_url text
) RETURNS TABLE (outcome text, current_version integer, reason text)
LANGUAGE plpgsql
SET search_path = cims_n8n, pg_temp
AS $$
DECLARE
    v_uuid_event cims_n8n.ingested_events%ROWTYPE;
    v_key_event cims_n8n.ingested_events%ROWTYPE;
    v_inserted_count integer := 0;
    v_applied_version integer;
    v_outcome text;
    v_reason text;
BEGIN
    -- Defense in depth. Full schema/HMAC validation must already have succeeded in the workflow.
    IF p_subject_key <> p_subject_type || ':' || p_subject_id::text
        OR p_idempotency_key <> p_subject_key || ':v' || p_schedule_version::text
        OR p_subject_type NOT IN ('ci_activity', 'bank_target', 'asset_target')
        OR p_schedule_version < 1
        OR p_event_type NOT IN ('schedule.created', 'schedule.changed', 'schedule.cancelled')
        OR p_timezone <> 'Asia/Manila'
        OR p_payload_sha256 !~ '^[0-9a-f]{64}$'
    THEN
        RAISE EXCEPTION 'validated event invariants failed' USING ERRCODE = '22023';
    END IF;

    INSERT INTO cims_n8n.ingested_events (
        event_uuid, idempotency_key, payload_sha256, subject_key, subject_type, subject_id,
        ci_activity_id, schedule_version, event_type, occurred_at, request_timestamp,
        received_at, outcome
    ) VALUES (
        p_event_uuid, p_idempotency_key, p_payload_sha256, p_subject_key, p_subject_type,
        p_subject_id, p_ci_activity_id, p_schedule_version, p_event_type, p_occurred_at,
        p_request_timestamp, p_received_at, 'processing'
    )
    ON CONFLICT DO NOTHING;

    GET DIAGNOSTICS v_inserted_count = ROW_COUNT;

    IF v_inserted_count = 0 THEN
        SELECT * INTO v_uuid_event FROM cims_n8n.ingested_events WHERE event_uuid = p_event_uuid;
        SELECT * INTO v_key_event FROM cims_n8n.ingested_events WHERE idempotency_key = p_idempotency_key;

        IF v_uuid_event.event_uuid IS NOT NULL
            AND v_key_event.event_uuid IS NOT NULL
            AND v_uuid_event.event_uuid = v_key_event.event_uuid
            AND v_uuid_event.payload_sha256 = p_payload_sha256
        THEN
            v_outcome := 'duplicate';
            v_reason := NULL;
        ELSIF v_uuid_event.event_uuid IS NOT NULL AND v_uuid_event.payload_sha256 <> p_payload_sha256 THEN
            v_outcome := 'conflict';
            v_reason := 'event_uuid_payload_conflict';
        ELSIF v_key_event.event_uuid IS NOT NULL AND v_key_event.payload_sha256 <> p_payload_sha256 THEN
            v_outcome := 'conflict';
            v_reason := 'idempotency_key_payload_conflict';
        ELSE
            v_outcome := 'conflict';
            v_reason := 'identity_pair_conflict';
        END IF;

        SELECT schedule_version INTO v_applied_version
        FROM cims_n8n.reminder_states
        WHERE subject_key = p_subject_key;

        INSERT INTO cims_n8n.ingestion_audit (
            event_uuid, idempotency_key, payload_sha256, subject_key, schedule_version,
            outcome, reason, received_at
        ) VALUES (
            p_event_uuid, p_idempotency_key, p_payload_sha256, p_subject_key,
            p_schedule_version, v_outcome, v_reason, p_received_at
        );

        RETURN QUERY SELECT v_outcome, v_applied_version, v_reason;
        RETURN;
    END IF;

    INSERT INTO cims_n8n.reminder_states (
        subject_key, subject_type, subject_id, ci_activity_id, schedule_version, is_active,
        event_type, recipient_user_id, recipient_email, scheduled_at, trigger_at,
        trigger_at_local, timezone, cancel_reason, subject_label, activities_url,
        last_event_uuid, last_idempotency_key, last_payload_sha256, last_occurred_at,
        last_request_timestamp, last_received_at, updated_at
    ) VALUES (
        p_subject_key, p_subject_type, p_subject_id, p_ci_activity_id, p_schedule_version,
        p_event_type <> 'schedule.cancelled', p_event_type, p_recipient_user_id,
        p_recipient_email, p_scheduled_at, p_trigger_at, p_trigger_at_local, p_timezone,
        p_cancel_reason, p_subject_label, p_activities_url, p_event_uuid,
        p_idempotency_key, p_payload_sha256, p_occurred_at, p_request_timestamp,
        p_received_at, clock_timestamp()
    )
    ON CONFLICT (subject_key) DO UPDATE SET
        subject_type = EXCLUDED.subject_type,
        subject_id = EXCLUDED.subject_id,
        ci_activity_id = EXCLUDED.ci_activity_id,
        schedule_version = EXCLUDED.schedule_version,
        is_active = EXCLUDED.is_active,
        event_type = EXCLUDED.event_type,
        recipient_user_id = COALESCE(EXCLUDED.recipient_user_id, reminder_states.recipient_user_id),
        recipient_email = COALESCE(EXCLUDED.recipient_email, reminder_states.recipient_email),
        scheduled_at = EXCLUDED.scheduled_at,
        trigger_at = EXCLUDED.trigger_at,
        trigger_at_local = EXCLUDED.trigger_at_local,
        timezone = EXCLUDED.timezone,
        cancel_reason = EXCLUDED.cancel_reason,
        subject_label = COALESCE(EXCLUDED.subject_label, reminder_states.subject_label),
        activities_url = COALESCE(EXCLUDED.activities_url, reminder_states.activities_url),
        last_event_uuid = EXCLUDED.last_event_uuid,
        last_idempotency_key = EXCLUDED.last_idempotency_key,
        last_payload_sha256 = EXCLUDED.last_payload_sha256,
        last_occurred_at = EXCLUDED.last_occurred_at,
        last_request_timestamp = EXCLUDED.last_request_timestamp,
        last_received_at = EXCLUDED.last_received_at,
        updated_at = clock_timestamp()
    WHERE EXCLUDED.schedule_version > reminder_states.schedule_version
    RETURNING schedule_version INTO v_applied_version;

    IF v_applied_version IS NULL THEN
        SELECT schedule_version INTO v_applied_version
        FROM cims_n8n.reminder_states
        WHERE subject_key = p_subject_key;
        v_outcome := 'stale';
    ELSE
        v_outcome := 'applied';
    END IF;

    UPDATE cims_n8n.ingested_events
    SET outcome = v_outcome,
        processed_at = clock_timestamp()
    WHERE event_uuid = p_event_uuid;

    INSERT INTO cims_n8n.ingestion_audit (
        event_uuid, idempotency_key, payload_sha256, subject_key, schedule_version,
        outcome, reason, received_at
    ) VALUES (
        p_event_uuid, p_idempotency_key, p_payload_sha256, p_subject_key,
        p_schedule_version, v_outcome, NULL, p_received_at
    );

    RETURN QUERY SELECT v_outcome, v_applied_version, NULL::text;
END;
$$;

COMMIT;

-- After creating a least-privilege login, grant only what the ingestion workflow needs:
-- GRANT USAGE ON SCHEMA cims_n8n TO cims_n8n_ingest;
-- GRANT EXECUTE ON FUNCTION cims_n8n.ingest_reminder_event(
--     uuid, text, character, text, text, bigint, bigint, integer, text, timestamptz,
--     bigint, timestamptz, bigint, text, timestamptz, timestamptz, text, text, text,
--     text, text
-- ) TO cims_n8n_ingest;
