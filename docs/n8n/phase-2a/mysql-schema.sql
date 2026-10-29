-- SUPERSEDED ALTERNATIVE — NOT THE APPROVED PHASE 2A-3 STORAGE.
-- Preserved for reference only; do not provision without separate approval.
-- BRBI CIMS Phase 2A-2 receiver storage (MySQL 8.0.16+).
-- Run only in a dedicated n8n-side staging database after review.
-- Do not install this schema in the Laravel application's banking database.
--
-- The routine is SQL SECURITY DEFINER. Create it while connected as the schema owner, then grant
-- the n8n login EXECUTE on the routine only. Do not grant that login direct table privileges.

CREATE DATABASE IF NOT EXISTS cims_n8n_staging
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE cims_n8n_staging;

CREATE TABLE IF NOT EXISTS reminder_states (
    subject_key varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id bigint unsigned NOT NULL,
    ci_activity_id bigint unsigned NOT NULL,
    schedule_version int unsigned NOT NULL,
    is_active boolean NOT NULL,
    event_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_user_id bigint unsigned NULL,
    recipient_email varchar(254) NULL,
    scheduled_at datetime(6) NULL COMMENT 'UTC',
    trigger_at datetime(6) NULL COMMENT 'UTC',
    trigger_at_local varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    timezone varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cancel_reason varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    subject_label varchar(100) NULL,
    activities_url varchar(2048) NULL,
    last_event_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_idempotency_key varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_payload_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_occurred_at datetime(6) NOT NULL COMMENT 'UTC',
    last_request_timestamp bigint unsigned NOT NULL,
    last_received_at datetime(6) NOT NULL COMMENT 'UTC',
    updated_at datetime(6) NOT NULL,
    PRIMARY KEY (subject_key),
    UNIQUE KEY reminder_states_subject_identity_uq (subject_type, subject_id),
    CONSTRAINT reminder_states_subject_type_chk
        CHECK (subject_type IN ('ci_activity', 'bank_target', 'asset_target')),
    CONSTRAINT reminder_states_version_chk CHECK (schedule_version > 0),
    CONSTRAINT reminder_states_event_type_chk
        CHECK (event_type IN ('schedule.created', 'schedule.changed', 'schedule.cancelled')),
    CONSTRAINT reminder_states_timezone_chk CHECK (timezone = 'Asia/Manila'),
    CONSTRAINT reminder_states_cancel_reason_chk
        CHECK (cancel_reason IS NULL OR cancel_reason IN ('completed', 'unscheduled', 'deleted', 'recipient_unavailable')),
    CONSTRAINT reminder_states_shape_chk CHECK (
        (is_active = 1 AND recipient_user_id IS NOT NULL AND recipient_email IS NOT NULL
            AND scheduled_at IS NOT NULL AND trigger_at IS NOT NULL AND cancel_reason IS NULL)
        OR
        (is_active = 0 AND scheduled_at IS NULL AND trigger_at IS NULL AND cancel_reason IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ingested_events (
    event_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registration_token char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_key varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id bigint unsigned NOT NULL,
    ci_activity_id bigint unsigned NOT NULL,
    schedule_version int unsigned NOT NULL,
    event_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at datetime(6) NOT NULL COMMENT 'UTC',
    request_timestamp bigint unsigned NOT NULL,
    received_at datetime(6) NOT NULL COMMENT 'UTC',
    outcome varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processed_at datetime(6) NULL COMMENT 'UTC',
    PRIMARY KEY (event_uuid),
    UNIQUE KEY ingested_events_idempotency_uq (idempotency_key),
    UNIQUE KEY ingested_events_registration_uq (registration_token),
    KEY ingested_events_subject_version_idx (subject_key, schedule_version DESC),
    CONSTRAINT ingested_events_outcome_chk CHECK (outcome IN ('processing', 'applied', 'stale'))
) ENGINE=InnoDB;

-- Metadata only: never store raw payloads, signatures, secrets, headers, or recipient addresses.
CREATE TABLE IF NOT EXISTS ingestion_audit (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    event_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_key varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    schedule_version int unsigned NOT NULL,
    outcome varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    received_at datetime(6) NOT NULL COMMENT 'UTC',
    recorded_at datetime(6) NOT NULL,
    PRIMARY KEY (id),
    KEY ingestion_audit_received_idx (received_at DESC),
    CONSTRAINT ingestion_audit_outcome_chk CHECK (outcome IN ('applied', 'duplicate', 'stale', 'conflict'))
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS ingest_reminder_event;

DELIMITER $$

CREATE PROCEDURE ingest_reminder_event(
    IN p_event_uuid char(36),
    IN p_idempotency_key varchar(128),
    IN p_payload_sha256 char(64),
    IN p_subject_key varchar(96),
    IN p_subject_type varchar(32),
    IN p_subject_id bigint unsigned,
    IN p_ci_activity_id bigint unsigned,
    IN p_schedule_version int unsigned,
    IN p_event_type varchar(32),
    IN p_occurred_at datetime(6),
    IN p_request_timestamp bigint unsigned,
    IN p_received_at datetime(6),
    IN p_recipient_user_id bigint unsigned,
    IN p_recipient_email varchar(254),
    IN p_scheduled_at datetime(6),
    IN p_trigger_at datetime(6),
    IN p_trigger_at_local varchar(32),
    IN p_timezone varchar(32),
    IN p_cancel_reason varchar(32),
    IN p_subject_label varchar(100),
    IN p_activities_url varchar(2048)
)
SQL SECURITY DEFINER
MODIFIES SQL DATA
BEGIN
    DECLARE v_registration_token char(36) DEFAULT NULL;
    DECLARE v_uuid_hash char(64) DEFAULT NULL;
    DECLARE v_uuid_key varchar(128) DEFAULT NULL;
    DECLARE v_uuid_token char(36) DEFAULT NULL;
    DECLARE v_key_hash char(64) DEFAULT NULL;
    DECLARE v_key_uuid char(36) DEFAULT NULL;
    DECLARE v_key_token char(36) DEFAULT NULL;
    DECLARE v_outcome varchar(16);
    DECLARE v_reason varchar(64) DEFAULT NULL;
    DECLARE v_current_version int unsigned DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    -- Defense in depth. Exact schema, email, URL, timestamp, and HMAC validation happens first.
    IF p_event_uuid IS NULL
        OR NOT (p_event_uuid REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$')
        OR p_subject_key <> CONCAT(p_subject_type, ':', p_subject_id)
        OR p_idempotency_key <> CONCAT(p_subject_key, ':v', p_schedule_version)
        OR p_subject_type NOT IN ('ci_activity', 'bank_target', 'asset_target')
        OR p_subject_id = 0 OR p_ci_activity_id = 0 OR p_schedule_version = 0
        OR p_event_type NOT IN ('schedule.created', 'schedule.changed', 'schedule.cancelled')
        OR p_timezone <> 'Asia/Manila'
        OR NOT (BINARY p_payload_sha256 REGEXP '^[0-9a-f]{64}$')
        OR (p_event_type = 'schedule.cancelled' AND (
            p_scheduled_at IS NOT NULL OR p_trigger_at IS NOT NULL
            OR p_cancel_reason NOT IN ('completed', 'unscheduled', 'deleted', 'recipient_unavailable')
        ))
        OR (p_event_type <> 'schedule.cancelled' AND (
            p_recipient_user_id IS NULL OR p_recipient_email IS NULL
            OR p_scheduled_at IS NULL OR p_trigger_at IS NULL OR p_cancel_reason IS NOT NULL
        ))
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'validated event invariants failed';
    END IF;

    SET v_registration_token = UUID();
    START TRANSACTION;

    -- Unique-key checks in InnoDB wait for competing transactions. The per-call token tells us
    -- whether this transaction inserted the row without relying on client-specific ROW_COUNT flags.
    INSERT INTO ingested_events (
        event_uuid, idempotency_key, payload_sha256, registration_token, subject_key,
        subject_type, subject_id, ci_activity_id, schedule_version, event_type, occurred_at,
        request_timestamp, received_at, outcome
    ) VALUES (
        p_event_uuid, p_idempotency_key, p_payload_sha256, v_registration_token, p_subject_key,
        p_subject_type, p_subject_id, p_ci_activity_id, p_schedule_version, p_event_type,
        p_occurred_at, p_request_timestamp, p_received_at, 'processing'
    ) ON DUPLICATE KEY UPDATE event_uuid = ingested_events.event_uuid;

    SELECT payload_sha256, idempotency_key, registration_token
      INTO v_uuid_hash, v_uuid_key, v_uuid_token
      FROM ingested_events WHERE event_uuid = p_event_uuid;

    SELECT payload_sha256, event_uuid, registration_token
      INTO v_key_hash, v_key_uuid, v_key_token
      FROM ingested_events WHERE idempotency_key = p_idempotency_key;

    IF v_uuid_token <> v_registration_token OR v_key_token <> v_registration_token THEN
        IF v_uuid_hash IS NOT NULL AND v_uuid_hash <> p_payload_sha256 THEN
            SET v_outcome = 'conflict';
            SET v_reason = 'event_uuid_payload_conflict';
        ELSEIF v_key_hash IS NOT NULL AND v_key_hash <> p_payload_sha256 THEN
            SET v_outcome = 'conflict';
            SET v_reason = 'idempotency_key_payload_conflict';
        ELSEIF v_uuid_key = p_idempotency_key AND v_key_uuid = p_event_uuid
            AND v_uuid_hash = p_payload_sha256 AND v_key_hash = p_payload_sha256 THEN
            SET v_outcome = 'duplicate';
        ELSE
            SET v_outcome = 'conflict';
            SET v_reason = 'identity_pair_conflict';
        END IF;

        SELECT schedule_version INTO v_current_version
          FROM reminder_states WHERE subject_key = p_subject_key;

        INSERT INTO ingestion_audit (
            event_uuid, idempotency_key, payload_sha256, subject_key, schedule_version,
            outcome, reason, received_at, recorded_at
        ) VALUES (
            p_event_uuid, p_idempotency_key, p_payload_sha256, p_subject_key,
            p_schedule_version, v_outcome, v_reason, p_received_at, UTC_TIMESTAMP(6)
        );

        COMMIT;
        SELECT v_outcome AS outcome, v_current_version AS current_version, v_reason AS reason;
    ELSE
        -- schedule_version is assigned last. MySQL evaluates UPDATE assignments left-to-right;
        -- every conditional therefore compares against the previously committed version.
        INSERT INTO reminder_states (
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
            p_received_at, UTC_TIMESTAMP(6)
        ) ON DUPLICATE KEY UPDATE
            subject_type = IF(VALUES(schedule_version) > schedule_version, VALUES(subject_type), subject_type),
            subject_id = IF(VALUES(schedule_version) > schedule_version, VALUES(subject_id), subject_id),
            ci_activity_id = IF(VALUES(schedule_version) > schedule_version, VALUES(ci_activity_id), ci_activity_id),
            is_active = IF(VALUES(schedule_version) > schedule_version, VALUES(is_active), is_active),
            event_type = IF(VALUES(schedule_version) > schedule_version, VALUES(event_type), event_type),
            recipient_user_id = IF(VALUES(schedule_version) > schedule_version,
                COALESCE(VALUES(recipient_user_id), recipient_user_id), recipient_user_id),
            recipient_email = IF(VALUES(schedule_version) > schedule_version,
                COALESCE(VALUES(recipient_email), recipient_email), recipient_email),
            scheduled_at = IF(VALUES(schedule_version) > schedule_version, VALUES(scheduled_at), scheduled_at),
            trigger_at = IF(VALUES(schedule_version) > schedule_version, VALUES(trigger_at), trigger_at),
            trigger_at_local = IF(VALUES(schedule_version) > schedule_version, VALUES(trigger_at_local), trigger_at_local),
            timezone = IF(VALUES(schedule_version) > schedule_version, VALUES(timezone), timezone),
            cancel_reason = IF(VALUES(schedule_version) > schedule_version, VALUES(cancel_reason), cancel_reason),
            subject_label = IF(VALUES(schedule_version) > schedule_version,
                COALESCE(VALUES(subject_label), subject_label), subject_label),
            activities_url = IF(VALUES(schedule_version) > schedule_version,
                COALESCE(VALUES(activities_url), activities_url), activities_url),
            last_event_uuid = IF(VALUES(schedule_version) > schedule_version, VALUES(last_event_uuid), last_event_uuid),
            last_idempotency_key = IF(VALUES(schedule_version) > schedule_version, VALUES(last_idempotency_key), last_idempotency_key),
            last_payload_sha256 = IF(VALUES(schedule_version) > schedule_version, VALUES(last_payload_sha256), last_payload_sha256),
            last_occurred_at = IF(VALUES(schedule_version) > schedule_version, VALUES(last_occurred_at), last_occurred_at),
            last_request_timestamp = IF(VALUES(schedule_version) > schedule_version, VALUES(last_request_timestamp), last_request_timestamp),
            last_received_at = IF(VALUES(schedule_version) > schedule_version, VALUES(last_received_at), last_received_at),
            updated_at = IF(VALUES(schedule_version) > schedule_version, VALUES(updated_at), updated_at),
            schedule_version = GREATEST(schedule_version, VALUES(schedule_version));

        SELECT schedule_version INTO v_current_version
          FROM reminder_states WHERE subject_key = p_subject_key;

        -- A distinct accepted event can never have the same subject/version as an earlier event:
        -- that version's canonical idempotency key would already exist. Equality therefore means
        -- this call inserted/advanced the state; a greater stored version means this call was stale.
        SET v_outcome = IF(v_current_version = p_schedule_version, 'applied', 'stale');

        UPDATE ingested_events
           SET outcome = v_outcome, processed_at = UTC_TIMESTAMP(6)
         WHERE event_uuid = p_event_uuid AND registration_token = v_registration_token;

        INSERT INTO ingestion_audit (
            event_uuid, idempotency_key, payload_sha256, subject_key, schedule_version,
            outcome, reason, received_at, recorded_at
        ) VALUES (
            p_event_uuid, p_idempotency_key, p_payload_sha256, p_subject_key,
            p_schedule_version, v_outcome, NULL, p_received_at, UTC_TIMESTAMP(6)
        );

        COMMIT;
        SELECT v_outcome AS outcome, v_current_version AS current_version, NULL AS reason;
    END IF;
END$$

DELIMITER ;

-- Example least-privilege grant, to be executed only after the staging login is created:
-- GRANT EXECUTE ON PROCEDURE cims_n8n_staging.ingest_reminder_event TO 'cims_n8n_ingest'@'<n8n-host>';
-- The schema owner must retain the tables/routine and must not be used by the n8n workflow.
