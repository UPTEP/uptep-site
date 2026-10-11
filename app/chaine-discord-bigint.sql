-- Discord IDs are exact 64-bit integers, never floating-point numbers.
ALTER TABLE chaine_members ALTER COLUMN iddiscord TYPE bigint USING iddiscord::bigint;
