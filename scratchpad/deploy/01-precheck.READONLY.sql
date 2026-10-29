-- BRBI CIMS Stage 5.1 production precheck (READ ONLY)
-- MariaDB 10.4.x / phpMyAdmin. Every statement is SELECT or SHOW.

-- A. Server/session compatibility. Save the complete result.
SELECT VERSION() AS database_version,
       DATABASE() AS selected_database,
       @@SESSION.sql_mode AS session_sql_mode,
       @@SESSION.explicit_defaults_for_timestamp AS explicit_defaults_for_timestamp,
       @@SESSION.time_zone AS session_time_zone,
       @@character_set_database AS database_charset,
       @@collation_database AS database_collation;

-- B. All three rows must be absent before migration.
SELECT table_name, table_rows, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'reminder_schedule_states',
      'reminder_outbox_events',
      'reminder_delivery_claims'
  )
ORDER BY table_name;

-- C. Both rows must be absent before migration.
SELECT id, migration, batch
FROM migrations
WHERE migration IN (
    '2026_10_25_000100_create_reminder_schedule_outbox_tables',
    '2026_10_25_000200_create_reminder_delivery_claims_table'
)
ORDER BY id;

-- D. Data-preservation baseline. Save these counts before any change.
SELECT 'users' AS table_name, COUNT(*) AS row_count FROM users
UNION ALL SELECT 'client_folders', COUNT(*) FROM client_folders
UNION ALL SELECT 'ci_activities', COUNT(*) FROM ci_activities
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'cache', COUNT(*) FROM cache
UNION ALL SELECT 'migrations', COUNT(*) FROM migrations;

-- E. HTTPS URL-generation evidence from existing scheduled notifications.
-- PASS: no rows, or the only non-missing origin is https://cis.kesug.com.
-- STOP: http://cis.kesug.com or any unexpected origin appears.
SELECT
    COALESCE(
        NULLIF(
            SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(data, '$.url')), '/', 3),
            ''
        ),
        '(missing)'
    ) AS generated_origin,
    COUNT(*) AS notification_count
FROM notifications
WHERE type = 'App\\Notifications\\CiActivityScheduledReminder'
GROUP BY generated_origin
ORDER BY generated_origin;

-- F. Record the next migration batch that the prepared migration will use.
SELECT COALESCE(MAX(batch), 0) + 1 AS proposed_batch
FROM migrations;
