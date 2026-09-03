-- Version-only migration: splitting the Home Improvement tab's Projects
-- and Maintenance sections into their own sub-tabs has no schema change
-- of its own, but every merged change bumps VERSION now (see "Versioning"
-- in the top-level README) -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.14.0' WHERE id = 1;
