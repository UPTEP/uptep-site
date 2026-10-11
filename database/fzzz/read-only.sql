-- À exécuter sur la base FZZZ restaurée, comme propriétaire des tables.
-- Le rôle applicatif doit déjà exister et ne doit pas posséder ces tables.
-- Exemple : psql -d fzzz -v app_role=uptep -f database/fzzz/read-only.sql
\set ON_ERROR_STOP on
\if :{?app_role}
\else
\set app_role uptep
\endif
BEGIN;
GRANT CONNECT ON DATABASE :"DBNAME" TO :"app_role";
GRANT USAGE ON SCHEMA public TO :"app_role";
REVOKE ALL PRIVILEGES ON TABLE
 public.alliances, public.players, public.historique_alliances,
 public.historique_exports, public.historique_players
 FROM :"app_role";
REVOKE ALL PRIVILEGES ON SEQUENCE public.historique_exports_id_seq FROM :"app_role";
GRANT SELECT ON TABLE
 public.alliances, public.players, public.historique_alliances,
 public.historique_exports, public.historique_players
 TO :"app_role";
COMMIT;
