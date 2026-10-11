BEGIN;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS buildings_synced_at timestamptz;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS research_synced_at timestamptz;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS army_synced_at timestamptz;
-- Only transfer known collection dates; never infer a collection from updated_at.
UPDATE user_stats u SET buildings_synced_at=coalesce(u.buildings_synced_at,s.buildings_synced_at),research_synced_at=coalesce(u.research_synced_at,s.research_synced_at) FROM script_settings s WHERE s.user_id=u.user_id;
COMMIT;
