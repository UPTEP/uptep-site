BEGIN;
CREATE TABLE demandes_convois (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 serveur varchar(16) NOT NULL DEFAULT 's5',
 iddemandeur bigint NOT NULL CHECK(iddemandeur>0), pseudo_demandeur text NOT NULL, discord_demandeur bigint NOT NULL,
 nbpomme_original bigint NOT NULL CHECK(nbpomme_original>=0), nbpomme_restant bigint NOT NULL,
 nbmateriaux_original bigint NOT NULL CHECK(nbmateriaux_original>=0), nbmateriaux_restant bigint NOT NULL,
 valide boolean NOT NULL DEFAULT false, idvalidateur bigint, pseudo_validateur text, discord_validateur bigint,
 date_demande timestamptz NOT NULL DEFAULT now(), date_validation timestamptz,
 fini boolean NOT NULL DEFAULT false, date_fin timestamptz,
 CHECK(nbpomme_original>0 OR nbmateriaux_original>0),
 CHECK(nbpomme_restant BETWEEN 0 AND nbpomme_original), CHECK(nbmateriaux_restant BETWEEN 0 AND nbmateriaux_original),
 CHECK((valide AND idvalidateur IS NOT NULL AND pseudo_validateur IS NOT NULL AND discord_validateur IS NOT NULL AND date_validation IS NOT NULL) OR (NOT valide AND idvalidateur IS NULL AND pseudo_validateur IS NULL AND discord_validateur IS NULL AND date_validation IS NULL)),
 CHECK(fini=(nbpomme_restant=0 AND nbmateriaux_restant=0)),
 CHECK((fini AND valide AND date_fin IS NOT NULL) OR (NOT fini AND date_fin IS NULL))
);
CREATE INDEX demandes_convois_etat ON demandes_convois(valide,id) WHERE NOT fini;
CREATE TABLE convois_effectues (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 iddemande bigint NOT NULL REFERENCES demandes_convois(id) ON DELETE RESTRICT,
 idenvoyeur bigint NOT NULL CHECK(idenvoyeur>0), pseudo_envoyeur text NOT NULL, discord_envoyeur bigint NOT NULL,
 nbpomme bigint NOT NULL CHECK(nbpomme>=0), nbmateriaux bigint NOT NULL CHECK(nbmateriaux>=0),
 date_convoi timestamptz NOT NULL, date_enregistrement timestamptz NOT NULL DEFAULT now(),
 CHECK(nbpomme>0 OR nbmateriaux>0)
);
CREATE INDEX convois_effectues_demande ON convois_effectues(iddemande,id);
-- Transactional command receipts prevent duplicate writes and retain their original response.
CREATE TABLE bot_commandes (
 commande_id varchar(100) PRIMARY KEY,
 empreinte char(64) NOT NULL,
 discord_acteur bigint NOT NULL,
 action text NOT NULL,
 statut integer NOT NULL,
 reponse jsonb NOT NULL,
 date_creation timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE demandes_convois OWNER TO fzzz;
ALTER TABLE convois_effectues OWNER TO fzzz;
ALTER TABLE bot_commandes OWNER TO fzzz;
GRANT SELECT,INSERT,UPDATE,DELETE ON demandes_convois,convois_effectues,bot_commandes TO uptep;
GRANT USAGE,SELECT ON SEQUENCE demandes_convois_id_seq,convois_effectues_id_seq TO uptep;
COMMIT;
