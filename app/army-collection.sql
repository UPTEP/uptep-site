BEGIN;
UPDATE script_settings SET preferences=preferences||'{"army":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'army';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"buildings":true,"research":true,"army":true,"chaine":true,"clock":true,"statistics":true}'::jsonb;
COMMIT;
