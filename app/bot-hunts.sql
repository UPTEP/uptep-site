-- Hunts declared from Discord (pasted reports) next to the ones read by UPTEP+.
BEGIN;
ALTER TABLE script_hunts ADD COLUMN IF NOT EXISTS source text NOT NULL DEFAULT 'userscript';
DO $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conname='script_hunts_source_check')THEN
  ALTER TABLE script_hunts ADD CONSTRAINT script_hunts_source_check CHECK(source IN ('userscript','discord'));
 END IF;
END $$;
COMMIT;
