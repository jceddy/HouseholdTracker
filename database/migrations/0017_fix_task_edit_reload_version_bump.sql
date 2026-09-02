-- Version-only migration: fixing renderTaskEditForm()'s hardcoded reload
-- (it always refreshed the household Tasks tab's own list, even when
-- opened from the Home Improvement tab's Maintenance section or a
-- project's task list, so a successful save there looked like it silently
-- did nothing) has no schema change of its own, but every merged change
-- bumps VERSION now -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.15.0' WHERE id = 1;
