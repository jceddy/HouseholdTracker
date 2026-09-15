-- Version-only migration: relabeling the Staples tab's bulk-copy button
-- from "Add checked items to shopping list" to "Add flagged items to
-- shopping list" (matching the actual staple field name, needs_restock/
-- "flagged") has no schema change of its own, but every merged change
-- bumps VERSION -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.23.0' WHERE id = 1;
