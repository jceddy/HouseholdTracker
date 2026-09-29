-- Household chat/messaging (issue #28): a household-wide channel so
-- members can talk to each other, without needing real-time transport
-- (websockets/SSE) -- Bluehost shared hosting has no persistent-connection
-- support, so the frontend polls GET /households/messages on an interval
-- instead, the same constraint MoodSwings-Web's own in-game chat design
-- already settled on.
--
-- Household-wide channel only for v1, no direct messages -- settles the
-- issue's own "channel only, DMs only, or both" open question in favor of
-- the simpler v1 (no recipient_user_id column at all; adding DMs later is
-- a follow-up migration, not a column sitting unused today).
--
-- No edit/delete for v1 either -- a message is permanent once sent,
-- settling the issue's own "can a sender edit/delete" open question in
-- favor of the simpler, arguably more honest option it names. So there's
-- no updated_at, and no soft-delete flag.
--
-- No privacy tiers -- like pets/contacts/staples, any member sees every
-- message and any member may post; unlike those, there's nothing to
-- edit/remove once posted.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    sender_user_id INT UNSIGNED NOT NULL,
    body VARCHAR(2000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_messages_household_id_id (household_id, id),
    CONSTRAINT fk_household_messages_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_messages_sender_user_id FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.35.0' WHERE id = 1;
