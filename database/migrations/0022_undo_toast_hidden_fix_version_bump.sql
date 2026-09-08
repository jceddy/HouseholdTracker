-- Version-only migration: fixing the "Undo" toast not actually disappearing
-- on click or after 5 seconds (its own `#undo-toast { display: flex }`
-- silently defeated `[hidden] { display: none }` -- an ID selector beats a
-- plain attribute selector regardless of source order, same trap the
-- global [hidden] rule was already added once to prevent) has no schema
-- change of its own, but every merged change bumps VERSION now -- this
-- carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.20.0' WHERE id = 1;
