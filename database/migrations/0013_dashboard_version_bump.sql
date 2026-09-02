-- Version-only migration: the household dashboard (issue #20, PR #50)
-- shipped a new feature with no schema change of its own, so under the
-- previous "only a migration bumps VERSION" convention it didn't bump
-- VERSION. Going forward every merged change bumps VERSION regardless of
-- whether it includes a schema change (see "Versioning" in the top-level
-- README) -- a change with nothing else to migrate still needs a
-- migration like this one, whose only job is moving schema_version to
-- match, so it and the deployed VERSION file stay in lockstep for
-- MaintenanceGate's exact-match comparison.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.11.0' WHERE id = 1;
