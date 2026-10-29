-- Use only with the corresponding file rollback and explicit approval.
-- These statements remove only the three new reminder tables and migration rows.

DROP TABLE IF EXISTS `reminder_delivery_claims`;
DROP TABLE IF EXISTS `reminder_outbox_events`;
DROP TABLE IF EXISTS `reminder_schedule_states`;

DELETE FROM migrations
WHERE migration IN (
    '2026_10_25_000100_create_reminder_schedule_outbox_tables',
    '2026_10_25_000200_create_reminder_delivery_claims_table'
);
