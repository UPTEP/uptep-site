
-- Player display name cache and optional Discord identifier (text preserves exact IDs).
ALTER TABLE chaine_members ADD COLUMN IF NOT EXISTS pseudo text;
ALTER TABLE chaine_members ADD COLUMN IF NOT EXISTS iddiscord text;
