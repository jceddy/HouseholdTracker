-- Version-only migration: fixing "Hide finished today" not actually hiding
-- the finished-today list (an author-stylesheet `main ul { display: flex }`
-- rule silently defeated the browser's own `[hidden] { display: none }`
-- rule on that one directly-hidden <ul>) has no schema change of its own,
-- but every merged change bumps VERSION now -- this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.17.0' WHERE id = 1;
