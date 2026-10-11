BEGIN;
CREATE TABLE demandes_ajustements (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 iddemande bigint NOT NULL REFERENCES demandes_convois(id) ON DELETE RESTRICT,
 discord_acteur bigint NOT NULL,
 pseudo_acteur text NOT NULL,
 pommes_avant bigint NOT NULL CHECK(pommes_avant>=0),
 pommes_apres bigint NOT NULL CHECK(pommes_apres>=0),
 materiaux_avant bigint NOT NULL CHECK(materiaux_avant>=0),
 materiaux_apres bigint NOT NULL CHECK(materiaux_apres>=0),
 date_ajustement timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX demandes_ajustements_demande ON demandes_ajustements(iddemande,id);
ALTER TABLE demandes_ajustements OWNER TO fzzz;
GRANT SELECT,INSERT ON demandes_ajustements TO uptep;
GRANT USAGE,SELECT ON SEQUENCE demandes_ajustements_id_seq TO uptep;
COMMIT;
