-- Account management (issue #18): changing your email address needs to
-- re-verify the new address before it takes effect, reusing the existing
-- email_verifications token flow (see AuthService::updateProfile()/
-- verifyEmail()) rather than inventing a second one. pending_email holds
-- the requested new address until its verification link is clicked --
-- `email` itself is untouched until then, so a user keeps logging in with
-- their current, already-verified address the whole time.
SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN pending_email VARCHAR(255) NULL AFTER email;

UPDATE schema_version SET version = '0.24.0' WHERE id = 1;
