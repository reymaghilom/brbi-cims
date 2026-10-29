-- BRBI CIMS additive reminder schema migration for MariaDB 11.4.13.
-- Run only after 01-precheck.READONLY.sql passes and backups are verified.
-- Keep this session setting and all migration statements in one phpMyAdmin submission.
SET SESSION explicit_defaults_for_timestamp = 1;

SET @brbi_reminder_migration_batch := (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

create table `reminder_schedule_states` (`id` bigint unsigned not null auto_increment primary key, `subject_type` varchar(32) not null, `subject_id` bigint unsigned not null, `ci_activity_id` bigint unsigned not null, `client_folder_id` bigint unsigned not null, `version` int unsigned not null default '0', `is_active` tinyint(1) not null default '0', `recipient_user_id` bigint unsigned null, `scheduled_at` timestamp null, `scheduled_has_time` tinyint(1) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' ENGINE=InnoDB;
alter table `reminder_schedule_states` add unique `reminder_schedule_states_subject_type_subject_id_unique`(`subject_type`, `subject_id`);
alter table `reminder_schedule_states` add index `reminder_schedule_states_ci_activity_id_index`(`ci_activity_id`);
alter table `reminder_schedule_states` add index `reminder_schedule_states_client_folder_id_index`(`client_folder_id`);
alter table `reminder_schedule_states` add index `reminder_schedule_states_is_active_index`(`is_active`);

create table `reminder_outbox_events` (`id` bigint unsigned not null auto_increment primary key, `event_uuid` char(36) not null, `idempotency_key` varchar(96) not null, `subject_type` varchar(32) not null, `subject_id` bigint unsigned not null, `version` int unsigned not null, `event_type` varchar(32) not null, `payload` json not null, `status` varchar(16) not null default 'pending', `attempts` int unsigned not null default '0', `next_attempt_at` timestamp null, `claim_token` char(36) null, `claimed_at` timestamp null, `last_attempted_at` timestamp null, `last_response_status` smallint unsigned null, `last_error` varchar(255) null, `delivered_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' ENGINE=InnoDB;
alter table `reminder_outbox_events` add unique `reminder_outbox_events_subject_type_subject_id_version_unique`(`subject_type`, `subject_id`, `version`);
alter table `reminder_outbox_events` add index `reminder_outbox_events_status_next_attempt_at_index`(`status`, `next_attempt_at`);
alter table `reminder_outbox_events` add unique `reminder_outbox_events_event_uuid_unique`(`event_uuid`);
alter table `reminder_outbox_events` add unique `reminder_outbox_events_idempotency_key_unique`(`idempotency_key`);

create table `reminder_delivery_claims` (`id` bigint unsigned not null auto_increment primary key, `claim_uuid` char(36) not null, `event_uuid` char(36) not null, `occurrence_key` varchar(64) not null, `subject_type` varchar(32) not null, `subject_id` bigint unsigned not null, `ci_activity_id` bigint unsigned not null, `schedule_version` int unsigned not null, `occurrence_at` timestamp not null, `recipient_user_id` bigint unsigned not null, `recipient_email_hash` varchar(64) not null, `status` varchar(24) not null default 'claimed', `claimed_at` timestamp not null, `acknowledged_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' ENGINE=InnoDB;
alter table `reminder_delivery_claims` add unique `reminder_claim_subject_version_unique`(`subject_type`, `subject_id`, `schedule_version`);
alter table `reminder_delivery_claims` add index `reminder_delivery_claims_status_claimed_at_index`(`status`, `claimed_at`);
alter table `reminder_delivery_claims` add unique `reminder_delivery_claims_claim_uuid_unique`(`claim_uuid`);
alter table `reminder_delivery_claims` add unique `reminder_delivery_claims_event_uuid_unique`(`event_uuid`);
alter table `reminder_delivery_claims` add unique `reminder_delivery_claims_occurrence_key_unique`(`occurrence_key`);

INSERT INTO migrations (migration, batch) VALUES
('2026_10_25_000100_create_reminder_schedule_outbox_tables', @brbi_reminder_migration_batch),
('2026_10_25_000200_create_reminder_delivery_claims_table', @brbi_reminder_migration_batch);

SELECT 5 - COUNT(*) AS should_be_zero
FROM (
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('reminder_schedule_states', 'reminder_outbox_events', 'reminder_delivery_claims')
    UNION ALL
    SELECT migration
    FROM migrations
    WHERE migration IN (
        '2026_10_25_000100_create_reminder_schedule_outbox_tables',
        '2026_10_25_000200_create_reminder_delivery_claims_table'
    )
) AS installed;
