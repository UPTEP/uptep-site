BEGIN;
-- Construction or research launched once a demand is delivered: shown on the requester's next demand.
-- Not tied to fini: cancelling a convoy may reopen a demand whose launch was already confirmed.
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS lancement_construction timestamptz;
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS fin_construction timestamptz;
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS discord_lancement bigint;
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS pseudo_lancement text;
ALTER TABLE demandes_convois ADD COLUMN IF NOT EXISTS date_confirmation_lancement timestamptz;
ALTER TABLE demandes_convois DROP CONSTRAINT IF EXISTS demande_lancement_audit;
ALTER TABLE demandes_convois ADD CONSTRAINT demande_lancement_audit CHECK(
 (lancement_construction IS NULL AND fin_construction IS NULL AND discord_lancement IS NULL AND pseudo_lancement IS NULL AND date_confirmation_lancement IS NULL)
 OR (lancement_construction IS NOT NULL AND fin_construction>lancement_construction AND discord_lancement IS NOT NULL AND pseudo_lancement IS NOT NULL AND date_confirmation_lancement IS NOT NULL));
CREATE INDEX IF NOT EXISTS demandes_convois_lancement ON demandes_convois(iddemandeur,lancement_construction DESC) WHERE lancement_construction IS NOT NULL;
COMMIT;
