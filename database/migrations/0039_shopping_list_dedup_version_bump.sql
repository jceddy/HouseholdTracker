-- Version-only migration: the "add to shopping list" bulk actions
-- (staples' flagged-items button, meal plans' add-ingredients button) now
-- skip an item whose (trimmed, case-insensitive) name already matches an
-- unpurchased shopping-list row, instead of adding a duplicate. No schema
-- change of its own, but every merged change bumps VERSION -- this carries
-- that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.33.1' WHERE id = 1;
