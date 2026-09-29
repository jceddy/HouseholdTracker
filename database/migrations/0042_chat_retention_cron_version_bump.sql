-- Version-only migration: adding bin/prune_old_messages.php (a daily-cron
-- script deleting household chat messages older than 7 days -- see
-- "Household chat" in php-app/README.md) has no schema change of its own,
-- but every merged change bumps VERSION -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.35.1' WHERE id = 1;
