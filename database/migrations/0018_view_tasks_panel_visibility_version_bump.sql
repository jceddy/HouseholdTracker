-- Version-only migration: fixing the Home Improvement tab's "View tasks"
-- button, whose project detail panel opened below the entire "Add project"
-- form (and off-screen on longer project lists), making the click look like
-- it did nothing, has no schema change of its own, but every merged change
-- bumps VERSION now -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.16.0' WHERE id = 1;
