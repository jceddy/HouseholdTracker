-- LLM usage tracking (Fireworks AI). One row per POST /chat request, whether it ultimately
-- succeeded or failed -- Fireworks bills per underlying call regardless, so a request that made
-- at least one call before failing is still recorded (usage/cost will be zero for a request that
-- failed before any call completed). Tied to the authenticated user who made the request, unlike
-- MeadBotAPI's own opaque-string user_id -- HouseholdTracker already has real accounts.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS chat_usage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL,
    error_message TEXT NULL,
    model VARCHAR(255) NOT NULL,
    prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cached_prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd DECIMAL(12, 6) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_chat_usage_user_id (user_id),
    KEY idx_chat_usage_created_at (created_at),
    CONSTRAINT fk_chat_usage_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.2.0' WHERE id = 1;
