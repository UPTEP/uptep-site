BEGIN;
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS surplus_pommes numeric NOT NULL DEFAULT 0 CHECK(surplus_pommes>=0);
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS surplus_materiaux numeric NOT NULL DEFAULT 0 CHECK(surplus_materiaux>=0);
COMMIT;
