-- Version-only migration: a contact's phone numbers/email addresses now
-- render as real tel:/mailto: links in the household Contacts list
-- instead of plain text, no schema change. PATCH bump under the
-- MINOR-vs-PATCH split (see "Versioning" in the top-level README).
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.30.2' WHERE id = 1;
