-- Add the optional laboratory comparison without overwriting saved choices.
UPDATE script_settings SET preferences=preferences||'{"laboratory":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'laboratory';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"buildings":true,"research":true,"army":true,"economy":true,"chaine":true,"clock":true,"statistics":true,"laboratory":true}'::jsonb;
