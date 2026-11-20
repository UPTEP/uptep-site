BEGIN;
-- Optional wanted validation time (Paris, HH:MM) next to date_souhaitee. Existing dates keep no time.
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS heure_souhaitee text;
ALTER TABLE demandes_convois DROP CONSTRAINT IF EXISTS demandes_convois_heure_souhaitee_check;
ALTER TABLE demandes_convois ADD CONSTRAINT demandes_convois_heure_souhaitee_check
 CHECK (heure_souhaitee IS NULL OR (date_souhaitee IS NOT NULL AND heure_souhaitee ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'));
COMMIT;
