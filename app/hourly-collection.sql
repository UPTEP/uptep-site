BEGIN;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS resources_synced_at timestamptz;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS workers_synced_at timestamptz;
UPDATE script_settings SET preferences=preferences||'{"economy":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'economy';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"buildings":true,"research":true,"army":true,"economy":true,"chaine":true,"clock":true,"statistics":true}'::jsonb;
COMMIT;
