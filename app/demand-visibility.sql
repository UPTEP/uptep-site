BEGIN;
-- Restricted demands: a demand with rows here is shown only to its requester and these players,
-- on the site, the userscript and the Discord bot. No row: visible to every member, as before.
CREATE TABLE IF NOT EXISTS demandes_visibilite (
 iddemande bigint NOT NULL REFERENCES demandes_convois(id) ON DELETE CASCADE,
 player_id bigint NOT NULL CHECK(player_id>0),
 pseudo text NOT NULL,
 date_ajout timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(iddemande,player_id)
);
COMMIT;
