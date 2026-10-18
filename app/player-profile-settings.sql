BEGIN;
UPDATE script_settings SET preferences=preferences||'{"playerProfile":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'playerProfile';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"playerProfile":true,"convoys":true,"floodDeclarations":true,"multiflood":true,"traffic":true,"laboratory":true,"economy":false,"army":false,"buildings":false,"research":false,"chaine":true,"clock":true,"statistics":true}'::jsonb;
COMMIT;
