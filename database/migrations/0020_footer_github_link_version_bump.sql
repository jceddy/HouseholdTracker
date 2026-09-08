-- Version-only migration: turning the footer's #app-version text into a
-- link to the GitHub repo has no schema change of its own, but every
-- merged change bumps VERSION now -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.18.0' WHERE id = 1;
