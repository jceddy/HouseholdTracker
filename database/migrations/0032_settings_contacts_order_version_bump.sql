-- Version-only migration, catching up a bump PR #77 (moving the Settings
-- tab's Contacts section above "Delete household") missed -- the hard
-- requirement is every merged change bumps VERSION, not only ones with a
-- schema change (see "Versioning" in the top-level README).
--
-- This is also the first migration under the new MINOR-vs-PATCH split:
-- PATCH for a small fix/cosmetic tweak with no new capability, MINOR
-- reserved for an actual feature or schema change. PR #77 was the former,
-- so this bumps PATCH rather than MINOR.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.29.1' WHERE id = 1;
