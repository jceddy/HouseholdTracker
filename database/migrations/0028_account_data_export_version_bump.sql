-- Version-only migration: account/household data export (issue #21) --
-- GET /account/export and AccountExportService are purely application-
-- level, built entirely on existing tracker list methods (no new table,
-- no new column). Every merged change still bumps VERSION -- this
-- carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.26.0' WHERE id = 1;
