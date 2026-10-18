BEGIN;
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS auto_production boolean NOT NULL DEFAULT false;
COMMIT;
