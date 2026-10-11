BEGIN;
ALTER TABLE demandes_convois ADD COLUMN objet text CHECK (length(objet) BETWEEN 1 AND 160);
ALTER TABLE demandes_convois ADD COLUMN niveau integer CHECK(niveau BETWEEN 1 AND 999);
ALTER TABLE demandes_convois ADD COLUMN date_souhaitee date;
ALTER TABLE demandes_convois ADD COLUMN annulee boolean NOT NULL DEFAULT false;
ALTER TABLE demandes_convois ADD COLUMN date_annulation timestamptz;
ALTER TABLE demandes_convois ADD COLUMN idannulateur bigint;
ALTER TABLE demandes_convois ADD COLUMN pseudo_annulateur text;
ALTER TABLE demandes_convois ADD COLUMN discord_annulateur bigint;
ALTER TABLE demandes_convois ADD CONSTRAINT demande_annulation CHECK(
 (annulee AND NOT fini AND date_annulation IS NOT NULL AND idannulateur IS NOT NULL AND pseudo_annulateur IS NOT NULL AND discord_annulateur IS NOT NULL)
 OR (NOT annulee AND date_annulation IS NULL AND idannulateur IS NULL AND pseudo_annulateur IS NULL AND discord_annulateur IS NULL));
ALTER TABLE convois_effectues ADD COLUMN date_arrivee timestamptz;
COMMIT;
