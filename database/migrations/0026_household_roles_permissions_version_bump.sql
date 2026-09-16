-- Version-only migration: household roles and permissions (issue #17)
-- reuses the existing household_members.role column from migration 0005
-- (owner/member) rather than adding anything new -- a shared
-- HouseholdService::requireOwner() guard, a new owner-only
-- POST /households/delete route, and a documented permission matrix
-- (see "Household roles and permissions" in php-app/README.md) are all
-- application-level changes with no schema of their own. Every merged
-- change still bumps VERSION -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.24.0' WHERE id = 1;
