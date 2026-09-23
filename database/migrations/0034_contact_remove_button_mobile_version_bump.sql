-- Version-only migration: a small fix with no schema change (a phone/
-- email row's "Remove" button now an icon button, plus a narrow-viewport
-- CSS tweak, so it no longer overflows off-screen on a mobile display),
-- bumping PATCH under the MINOR-vs-PATCH split (see "Versioning" in the
-- top-level README).
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.30.1' WHERE id = 1;
