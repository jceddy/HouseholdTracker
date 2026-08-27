-- schema_version: a single row (id = 1) recording which VERSION the
-- currently-applied schema satisfies. Compared against the deployed
-- VERSION file on every request by MaintenanceGate (see php-app/README.md),
-- so the app shows a maintenance page instead of running against a schema
-- a migration hasn't been manually applied to yet. Every future
-- schema-changing migration must end with an UPDATE of this row matching
-- that same VERSION bump -- see "Adding a new migration" in
-- database/README.md.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_version (
    id TINYINT UNSIGNED NOT NULL,
    version VARCHAR(20) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (id, version) VALUES (1, '0.1.0')
    ON DUPLICATE KEY UPDATE version = '0.1.0';
