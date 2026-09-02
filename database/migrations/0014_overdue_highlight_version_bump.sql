-- Version-only migration (see "Adding a new migration" in this directory's
-- README for the shape): the red task-overdue highlight
-- (web-static/css/style.css, web-static/js/main.js) has no schema change
-- of its own, but every merged change bumps VERSION now, so this carries
-- that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.12.0' WHERE id = 1;
